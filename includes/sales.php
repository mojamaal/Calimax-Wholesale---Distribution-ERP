<?php
/**
 * Sales engine: customer pricing, credit control, sales orders, delivery
 * (pick / pack / dispatch / confirm), invoicing, collections, credit notes
 * and customer returns.
 *
 * A sales order, a delivery note, an invoice and a payment are four different
 * business events â€” each is its own record with its own status.
 */

// ---------------------------------------------------------------------
// Pricing
// ---------------------------------------------------------------------

/** Unit price (per BASE unit) for a customer: customer-specific > category price > default; bulk breaks apply to the latter two. */
function resolve_price(int $productId, ?int $customerId, int $qtyBase): array
{
    $pdo = db();
    $p = $pdo->prepare('SELECT default_price, tax_rate FROM products WHERE id = :id');
    $p->execute([':id' => $productId]);
    $prod = $p->fetch();
    if (!$prod) throw new RuntimeException('Product not found.');
    $price = (float) $prod['default_price']; $source = 'default'; $break = 0.0;
    if ($customerId) {
        $c = $pdo->prepare('SELECT price FROM customer_prices WHERE customer_id = :c AND product_id = :p');
        $c->execute([':c' => $customerId, ':p' => $productId]);
        $cp = $c->fetchColumn();
        if ($cp !== false) {
            return ['price' => (float) $cp, 'source' => 'customer', 'break_pct' => 0.0, 'tax_rate' => (float) $prod['tax_rate']];
        }
        $k = $pdo->prepare('SELECT pp.price FROM product_prices pp JOIN customers cu ON cu.category_id = pp.category_id WHERE cu.id = :c AND pp.product_id = :p');
        $k->execute([':c' => $customerId, ':p' => $productId]);
        $kp = $k->fetchColumn();
        if ($kp !== false) { $price = (float) $kp; $source = 'category'; }
    }
    $b = $pdo->prepare('SELECT discount_pct FROM product_price_breaks WHERE product_id = :p AND min_qty <= :q ORDER BY min_qty DESC LIMIT 1');
    $b->execute([':p' => $productId, ':q' => $qtyBase]);
    $bp = $b->fetchColumn();
    if ($bp !== false) $break = (float) $bp;
    return ['price' => $price, 'source' => $source, 'break_pct' => $break, 'tax_rate' => (float) $prod['tax_rate']];
}

function line_amounts(int $qty, float $unitPrice, float $discPct, float $taxRate): array
{
    $gross = $qty * $unitPrice;
    $net = round2($gross * (1 - $discPct / 100));
    $tax = round2($net * $taxRate / 100);
    return ['gross' => round2($gross), 'net' => $net, 'tax' => $tax, 'total' => round2($net + $tax)];
}

// ---------------------------------------------------------------------
// Customer credit control
// ---------------------------------------------------------------------

function invoice_outstanding_sql(string $alias = 'si'): string
{
    return "($alias.total - $alias.paid_amount - $alias.credited_amount)";
}

/** Receivable position of one customer. */
function customer_ar(int $customerId, ?int $excludeSoId = null): array
{
    $pdo = db();
    $s = $pdo->prepare("SELECT COALESCE(SUM(total - paid_amount - credited_amount),0) AS outstanding,
            COALESCE(SUM(CASE WHEN due_date < CURDATE() THEN total - paid_amount - credited_amount ELSE 0 END),0) AS overdue,
            COALESCE(MAX(CASE WHEN total - paid_amount - credited_amount > 0.005 THEN DATEDIFF(CURDATE(), due_date) END),0) AS max_days_overdue
        FROM sales_invoices WHERE customer_id = :c AND status <> 'void'");
    $s->execute([':c' => $customerId]);
    $r = $s->fetch();
    $u = $pdo->prepare('SELECT COALESCE(SUM(amount - allocated),0) FROM customer_payments WHERE customer_id = :c');
    $u->execute([':c' => $customerId]);
    $r['unallocated'] = (float) $u->fetchColumn();
    $o = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(so.total - COALESCE((SELECT SUM(si.total) FROM sales_invoices si WHERE si.so_id = so.id AND si.status <> 'void'),0),0)),0)
        FROM sales_orders so WHERE so.customer_id = :c AND so.payment_type = 'credit' AND so.status IN ('confirmed','partially_fulfilled','dispatched','completed') AND so.id <> :x");
    $o->execute([':c' => $customerId, ':x' => (int) $excludeSoId]);
    $r['open_orders'] = (float) $o->fetchColumn();
    $r['balance'] = (float) $r['outstanding'] - $r['unallocated'];   // what the customer owes (negative = credit in hand)
    return array_map('floatval', $r);
}

/**
 * Would a new order push the customer over the credit limit?
 * exposure = outstanding balance + uninvoiced open orders + this order.
 */
function credit_check(int $customerId, float $newTotal, string $paymentType, ?int $excludeSoId = null): array
{
    $c = db()->prepare('SELECT credit_limit, payment_terms_days FROM customers WHERE id = :id');
    $c->execute([':id' => $customerId]);
    $cu = $c->fetch();
    $ar = customer_ar($customerId, $excludeSoId);
    $limit = (float) ($cu['credit_limit'] ?? 0);
    $openOrders = setting('credit_include_open_orders', '1') === '1' ? $ar['open_orders'] : 0.0;
    $exposure = max($ar['balance'], 0) + $openOrders + $newTotal;
    $reasons = [];
    if ($paymentType === 'credit') {
        if ($limit <= 0) $reasons[] = 'Customer has no credit facility (credit limit is zero)';
        elseif ($exposure > $limit + 0.005) $reasons[] = 'Credit limit exceeded: exposure ' . format_money($exposure) . ' vs limit ' . format_money($limit);
        if (setting('flag_overdue_customers', '1') === '1' && $ar['overdue'] > 0.005) $reasons[] = 'Customer has overdue invoices of ' . format_money($ar['overdue']);
    }
    return ['limit' => $limit, 'outstanding' => max($ar['balance'], 0), 'overdue' => $ar['overdue'], 'open_orders' => $openOrders, 'new' => $newTotal, 'exposure' => $exposure,
        'available' => $limit - max($ar['balance'], 0) - $openOrders, 'exceeds' => $paymentType === 'credit' && $limit > 0 && $exposure > $limit + 0.005, 'reasons' => $reasons];
}

// ---------------------------------------------------------------------
// Sales orders
// ---------------------------------------------------------------------

/**
 * Create or update a DRAFT sales order. $items: [ ['product_id','pack_name','pack_factor','pack_qty','unit_price'(per pack, optional),'discount_pct'] ].
 * Prices are always re-resolved on the server; only users with sales.override_price can change them.
 */
function so_save(?int $soId, array $d, array $items): int
{
    $pdo = db();
    $customerId = (int) $d['customer_id'];
    $cu = $pdo->prepare('SELECT * FROM customers WHERE id = :id AND is_active = 1');
    $cu->execute([':id' => $customerId]);
    $cust = $cu->fetch();
    if (!$cust) throw new RuntimeException('Select an active customer.');
    if (!(int) $d['warehouse_id']) throw new RuntimeException('Select the dispatch warehouse.');
    if (!$items) throw new RuntimeException('Add at least one product to the order.');
    $canOverride = can('sales.override_price');
    $merged = [];
    foreach ($items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        $factor = max(1, (int) ($it['pack_factor'] ?? 1));
        $qty = (int) round((float) ($it['pack_qty'] ?? 0) * $factor);
        if (!$pid || $qty <= 0) throw new RuntimeException('Every line needs a product and a quantity.');
        $pr = $pdo->prepare('SELECT * FROM products WHERE id = :id AND is_active = 1');
        $pr->execute([':id' => $pid]);
        $prod = $pr->fetch();
        if (!$prod) throw new RuntimeException('A selected product is no longer available.');
        $packName = trim((string) ($it['pack_name'] ?? '')) ?: null;
        if ($factor > 1) {
            $chk = $pdo->prepare('SELECT COUNT(*) FROM product_units WHERE product_id = :p AND name = :n AND factor = :f');
            $chk->execute([':p' => $pid, ':n' => $packName, ':f' => $factor]);
            if (!(int) $chk->fetchColumn()) throw new RuntimeException('Invalid packaging selected for ' . $prod['name'] . '.');
        } else { $packName = $prod['base_unit']; }
        $key = $pid . '|' . $factor;
        $merged[$key] = isset($merged[$key]) ? array_merge($merged[$key], ['qty' => $merged[$key]['qty'] + $qty, 'pack_qty' => $merged[$key]['pack_qty'] + (float) $it['pack_qty']]) : [
            'prod' => $prod, 'pack_name' => $packName, 'factor' => $factor, 'qty' => $qty, 'pack_qty' => (float) $it['pack_qty'], 'raw' => $it,
        ];
    }
    // Aggregate base qty per product for break pricing & minimum order checks.
    $perProduct = [];
    foreach ($merged as $m) { $perProduct[$m['prod']['id']] = ($perProduct[$m['prod']['id']] ?? 0) + $m['qty']; }
    $rows = []; $sub = 0.0; $disc = 0.0; $tax = 0.0; $manualDiscount = 0.0;
    foreach ($merged as $m) {
        $prod = $m['prod'];
        if ($perProduct[$prod['id']] < (int) $prod['min_order_qty']) {
            throw new RuntimeException($prod['name'] . ': minimum order quantity is ' . number_format($prod['min_order_qty']) . ' ' . $prod['base_unit'] . '.');
        }
        $res = resolve_price((int) $prod['id'], $customerId, $perProduct[$prod['id']]);
        $unit = $res['price'];
        $submittedPrice = isset($m['raw']['unit_price']) && is_numeric($m['raw']['unit_price']) ? round((float) $m['raw']['unit_price'] / $m['factor'], 4) : null;
        if ($canOverride && $submittedPrice !== null && $submittedPrice >= 0 && abs($submittedPrice - $unit) > 0.0001) { $unit = $submittedPrice; }
        $dpct = min(100.0, max($res['break_pct'], (float) ($m['raw']['discount_pct'] ?? 0)));
        $manual = max(0.0, $dpct - $res['break_pct']);
        $manualDiscount = max($manualDiscount, $manual);
        $a = line_amounts($m['qty'], $unit, $dpct, (float) $prod['tax_rate']);
        $rows[] = ['product_id' => (int) $prod['id'], 'pack_name' => $m['pack_name'], 'factor' => $m['factor'], 'qty' => $m['qty'], 'unit_price' => $unit, 'list_price' => $res['price'],
            'disc' => $dpct, 'tax_rate' => (float) $prod['tax_rate'], 'a' => $a];
        $sub += $a['net']; $disc += $a['gross'] - $a['net']; $tax += $a['tax'];
    }
    $total = round2($sub + $tax);
    $pdo->beginTransaction();
    try {
        if ($soId) {
            $cur = $pdo->prepare('SELECT * FROM sales_orders WHERE id = :id FOR UPDATE'); $cur->execute([':id' => $soId]); $so = $cur->fetch();
            if (!$so || !in_array($so['status'], ['draft', 'rejected'], true)) throw new RuntimeException('Only draft orders can be edited. Cancel and re-create confirmed orders.');
            $pdo->prepare('UPDATE sales_orders SET customer_id=:c, rep_id=:r, warehouse_id=:w, order_date=:od, required_date=:rd, payment_type=:pt, customer_po_ref=:po, notes=:n, subtotal=:s, discount_total=:d, tax_total=:t, total=:tot, status=\'draft\' WHERE id=:id')
                ->execute([':c' => $customerId, ':r' => $cust['rep_id'] ?: (current_user()['role'] === 'sales_rep' ? current_user()['id'] : $so['rep_id']), ':w' => $d['warehouse_id'], ':od' => $d['order_date'], ':rd' => $d['required_date'] ?: null, ':pt' => $d['payment_type'],
                    ':po' => $d['customer_po_ref'] ?: null, ':n' => $d['notes'] ?: null, ':s' => $sub, ':d' => $disc, ':t' => $tax, ':tot' => $total, ':id' => $soId]);
            $pdo->prepare('DELETE FROM sales_order_items WHERE so_id = :id')->execute([':id' => $soId]);
            $no = $so['so_no'];
        } else {
            $no = next_no('sales_order', setting('so_prefix', 'SO'));
            $rep = current_user()['role'] === 'sales_rep' ? (int) current_user()['id'] : ((int) $cust['rep_id'] ?: null);
            $pdo->prepare('INSERT INTO sales_orders (so_no, customer_id, rep_id, warehouse_id, order_date, required_date, payment_type, status, customer_po_ref, subtotal, discount_total, tax_total, total, notes, created_by)
                           VALUES (:no,:c,:r,:w,:od,:rd,:pt,\'draft\',:po,:s,:d,:t,:tot,:n,:u)')
                ->execute([':no' => $no, ':c' => $customerId, ':r' => $rep, ':w' => $d['warehouse_id'], ':od' => $d['order_date'], ':rd' => $d['required_date'] ?: null, ':pt' => $d['payment_type'],
                    ':po' => $d['customer_po_ref'] ?: null, ':s' => $sub, ':d' => $disc, ':t' => $tax, ':tot' => $total, ':n' => $d['notes'] ?: null, ':u' => current_user()['id']]);
            $soId = (int) $pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO sales_order_items (so_id, product_id, pack_name, pack_factor, qty, unit_price, list_price, discount_pct, tax_rate, line_net, tax_amount, line_total) VALUES (:so,:p,:pn,:pf,:q,:up,:lp,:d,:tr,:ln,:ta,:lt)');
        foreach ($rows as $r) {
            $ins->execute([':so' => $soId, ':p' => $r['product_id'], ':pn' => $r['pack_name'], ':pf' => $r['factor'], ':q' => $r['qty'], ':up' => $r['unit_price'], ':lp' => $r['list_price'], ':d' => $r['disc'],
                ':tr' => $r['tax_rate'], ':ln' => $r['a']['net'], ':ta' => $r['a']['tax'], ':lt' => $r['a']['total']]);
        }
        $pdo->prepare('UPDATE sales_orders SET approval_reason = NULL WHERE id = :id')->execute([':id' => $soId]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('so_save', "Saved draft $no Â· {$cust['business_name']} Â· " . format_money($total), 'sales_order', $soId);
    return $soId;
}

/** Approval reasons for an order, e.g. credit limit exceeded, discount above rep allowance. */
function so_approval_reasons(array $so): array
{
    $reasons = [];
    $chk = credit_check((int) $so['customer_id'], (float) $so['total'], $so['payment_type'], (int) $so['id']);
    $reasons = array_merge($reasons, $chk['reasons']);
    $max = (float) setting('max_rep_discount_pct', '5');
    $it = db()->prepare('SELECT soi.discount_pct, soi.product_id, soi.qty FROM sales_order_items soi WHERE so_id = :id');
    $it->execute([':id' => $so['id']]);
    foreach ($it->fetchAll() as $i) {
        $res = resolve_price((int) $i['product_id'], (int) $so['customer_id'], (int) $i['qty']);
        if ((float) $i['discount_pct'] - $res['break_pct'] > $max + 0.001) { $reasons[] = 'Discount above the ' . rtrim(rtrim(number_format($max, 2), '0'), '.') . '% allowance'; break; }
    }
    $lc = db()->prepare('SELECT COUNT(*) FROM sales_order_items soi JOIN products p ON p.id = soi.product_id WHERE soi.so_id = :id AND soi.unit_price < p.avg_cost AND p.avg_cost > 0');
    $lc->execute([':id' => $so['id']]);
    if ((int) $lc->fetchColumn()) $reasons[] = 'One or more lines are priced below cost';
    return array_values(array_unique($reasons));
}

/** Submit a draft: confirms immediately when no exception, otherwise sends it for approval. Returns the new status. */
function so_submit(int $soId): string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM sales_orders WHERE id = :id FOR UPDATE'); $s->execute([':id' => $soId]); $so = $s->fetch();
        if (!$so || $so['status'] !== 'draft') throw new RuntimeException('Only draft orders can be submitted.');
        $reasons = so_approval_reasons($so);
        $canApprove = can('sales.approve');
        if ($reasons && !$canApprove) {
            $pdo->prepare("UPDATE sales_orders SET status = 'pending_approval', approval_reason = :r WHERE id = :id")->execute([':r' => implode('; ', $reasons), ':id' => $soId]);
            $status = 'pending_approval';
        } else {
            $pdo->prepare("UPDATE sales_orders SET status = 'confirmed', approval_reason = :r, approved_by = :u, approved_at = :at WHERE id = :id")
                ->execute([':r' => $reasons ? implode('; ', $reasons) : null, ':u' => $reasons ? current_user()['id'] : null, ':at' => $reasons ? date('Y-m-d H:i:s') : null, ':id' => $soId]);
            $status = 'confirmed';
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity($status === 'confirmed' ? 'so_confirm' : 'so_submit', $so['so_no'] . ($status === 'confirmed' ? ' confirmed â€” stock reserved' : ' sent for approval: ' . implode('; ', $reasons)), 'sales_order', $soId);
    return $status;
}

function so_decide(int $soId, bool $approve, string $note): void
{
    $pdo = db();
    $s = $pdo->prepare('SELECT * FROM sales_orders WHERE id = :id'); $s->execute([':id' => $soId]); $so = $s->fetch();
    if (!$so || $so['status'] !== 'pending_approval') throw new RuntimeException('This order is not awaiting approval.');
    if (!$approve && $note === '') throw new RuntimeException('Give a reason for rejecting the order.');
    $pdo->prepare('UPDATE sales_orders SET status = :st, approved_by = :u, approved_at = NOW(), decision_note = :n WHERE id = :id')
        ->execute([':st' => $approve ? 'confirmed' : 'rejected', ':u' => current_user()['id'], ':n' => $note ?: null, ':id' => $soId]);
    log_activity($approve ? 'so_approve' : 'so_reject', $so['so_no'] . ($approve ? ' approved' : ' rejected') . ($note ? ': ' . $note : '') . ' Â· exception: ' . $so['approval_reason'], 'sales_order', $soId);
}

/** Cancel an order that has not been dispatched. Open delivery notes are cancelled too. */
function so_cancel(int $soId, string $reason): void
{
    $pdo = db();
    if ($reason === '') throw new RuntimeException('Give a reason for cancelling the order.');
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM sales_orders WHERE id = :id FOR UPDATE'); $s->execute([':id' => $soId]); $so = $s->fetch();
        if (!$so || in_array($so['status'], ['cancelled', 'completed'], true)) throw new RuntimeException('This order can no longer be cancelled.');
        $disp = (int) $pdo->query('SELECT COALESCE(SUM(qty_dispatched),0) FROM sales_order_items WHERE so_id = ' . (int) $soId)->fetchColumn();
        if ($disp > 0) throw new RuntimeException('Goods have already been dispatched. Use "Close balance" to cancel only the undelivered quantity.');
        $open = $pdo->prepare("SELECT id FROM deliveries WHERE so_id = :id AND status IN ('pending_pick','picked','packed')");
        $open->execute([':id' => $soId]);
        foreach ($open->fetchAll() as $d) delivery_cancel((int) $d['id'], true);
        $pdo->prepare("UPDATE sales_orders SET status = 'cancelled', cancel_reason = :r, cancelled_by = :u WHERE id = :id")->execute([':r' => $reason, ':u' => current_user()['id'], ':id' => $soId]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('so_cancel', $so['so_no'] . ' cancelled: ' . $reason, 'sales_order', $soId);
}

/** Give up on the undelivered balance of a part-fulfilled order. */
function so_close_balance(int $soId, string $reason): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM sales_orders WHERE id = :id FOR UPDATE'); $s->execute([':id' => $soId]); $so = $s->fetch();
        if (!$so || !in_array($so['status'], ['partially_fulfilled', 'confirmed', 'dispatched'], true)) throw new RuntimeException('Nothing to close on this order.');
        $open = (int) $pdo->query("SELECT COUNT(*) FROM deliveries WHERE so_id = " . (int) $soId . " AND status IN ('pending_pick','picked','packed')")->fetchColumn();
        if ($open) throw new RuntimeException('Cancel the open delivery notes first.');
        $pdo->prepare('UPDATE sales_order_items SET qty_cancelled = qty - qty_dispatched WHERE so_id = :id')->execute([':id' => $soId]);
        so_recalc_totals($pdo, $soId);
        so_refresh_status($pdo, $soId);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('so_close_balance', $so['so_no'] . ' undelivered balance closed: ' . $reason, 'sales_order', $soId);
}

/** Recompute order money totals excluding cancelled quantities. */
function so_recalc_totals(PDO $pdo, int $soId): void
{
    $it = $pdo->prepare('SELECT * FROM sales_order_items WHERE so_id = :id');
    $it->execute([':id' => $soId]);
    $sub = 0; $disc = 0; $tax = 0;
    $upd = $pdo->prepare('UPDATE sales_order_items SET line_net = :n, tax_amount = :t, line_total = :l WHERE id = :id');
    foreach ($it->fetchAll() as $i) {
        $q = (int) $i['qty'] - (int) $i['qty_cancelled'];
        $a = line_amounts($q, (float) $i['unit_price'], (float) $i['discount_pct'], (float) $i['tax_rate']);
        $upd->execute([':n' => $a['net'], ':t' => $a['tax'], ':l' => $a['total'], ':id' => $i['id']]);
        $sub += $a['net']; $disc += $a['gross'] - $a['net']; $tax += $a['tax'];
    }
    $pdo->prepare('UPDATE sales_orders SET subtotal = :s, discount_total = :d, tax_total = :t, total = :tot WHERE id = :id')->execute([':s' => $sub, ':d' => $disc, ':t' => $tax, ':tot' => round2($sub + $tax), ':id' => $soId]);
}

/** Order status follows delivery progress: confirmed â†’ partially fulfilled â†’ dispatched â†’ completed. */
function so_refresh_status(PDO $pdo, int $soId): void
{
    $s = $pdo->prepare('SELECT status FROM sales_orders WHERE id = :id'); $s->execute([':id' => $soId]);
    $st = $s->fetchColumn();
    if (!in_array($st, ['confirmed', 'partially_fulfilled', 'dispatched', 'completed'], true)) return;
    $t = $pdo->prepare('SELECT COALESCE(SUM(qty - qty_cancelled),0) tgt, COALESCE(SUM(qty_dispatched),0) disp FROM sales_order_items WHERE so_id = :id');
    $t->execute([':id' => $soId]);
    $r = $t->fetch();
    $inTransit = (int) $pdo->query("SELECT COUNT(*) FROM deliveries WHERE so_id = " . (int) $soId . " AND status = 'dispatched'")->fetchColumn();
    if ((int) $r['tgt'] === 0) $new = 'cancelled';
    elseif ((int) $r['disp'] === 0) $new = 'confirmed';
    elseif ((int) $r['disp'] < (int) $r['tgt']) $new = 'partially_fulfilled';
    else $new = $inTransit ? 'dispatched' : 'completed';
    if ($new !== $st) $pdo->prepare('UPDATE sales_orders SET status = :s WHERE id = :id')->execute([':s' => $new, ':id' => $soId]);
}

/** Per order line: ordered, dispatched, planned on open deliveries, remaining to plan. */
function so_line_balances(int $soId): array
{
    $s = db()->prepare("SELECT soi.*, p.code, p.name, p.base_unit, p.tracking, p.track_expiry,
        (SELECT COALESCE(SUM(di.qty_planned),0) FROM delivery_items di JOIN deliveries d ON d.id = di.delivery_id WHERE di.so_item_id = soi.id AND d.status IN ('pending_pick','picked','packed')) AS planned
        FROM sales_order_items soi JOIN products p ON p.id = soi.product_id WHERE soi.so_id = :id ORDER BY soi.id");
    $s->execute([':id' => $soId]);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) {
        $r['remaining'] = (int) $r['qty'] - (int) $r['qty_dispatched'] - (int) $r['qty_cancelled'];
        $r['to_plan'] = max(0, $r['remaining'] - (int) $r['planned']);
    }
    return $rows;
}

// ---------------------------------------------------------------------
// Deliveries
// ---------------------------------------------------------------------

/** Create a delivery note for [so_item_id => base qty]. Quantities are capped to what is still unplanned. */
function delivery_create(int $soId, array $qtys, ?string $plannedDate = null): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM sales_orders WHERE id = :id FOR UPDATE'); $s->execute([':id' => $soId]); $so = $s->fetch();
        if (!$so || !in_array($so['status'], ['confirmed', 'partially_fulfilled'], true)) throw new RuntimeException('Only confirmed orders can be fulfilled.');
        $bal = [];
        foreach (so_line_balances($soId) as $b) $bal[(int) $b['id']] = $b;
        $lines = [];
        foreach ($qtys as $itemId => $q) {
            $q = (int) $q; if ($q <= 0) continue;
            if (!isset($bal[(int) $itemId])) continue;
            $take = min($q, $bal[(int) $itemId]['to_plan']);
            if ($take > 0) $lines[] = [$bal[(int) $itemId], $take];
        }
        if (!$lines) throw new RuntimeException('Nothing left to deliver on this order.');
        $no = next_no('delivery', setting('dn_prefix', 'DN'));
        $pdo->prepare('INSERT INTO deliveries (dn_no, so_id, customer_id, warehouse_id, status, planned_date, created_by) VALUES (:n,:so,:c,:w,\'pending_pick\',:pd,:u)')
            ->execute([':n' => $no, ':so' => $soId, ':c' => $so['customer_id'], ':w' => $so['warehouse_id'], ':pd' => $plannedDate ?: $so['required_date'], ':u' => current_user()['id']]);
        $id = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO delivery_items (delivery_id, so_item_id, product_id, qty_planned) VALUES (:d,:i,:p,:q)');
        foreach ($lines as [$b, $q]) $ins->execute([':d' => $id, ':i' => $b['id'], ':p' => $b['product_id'], ':q' => $q]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('delivery_create', "Created $no for {$so['so_no']}", 'delivery', $id);
    return $id;
}

function delivery_release_picks(PDO $pdo, int $deliveryId): void
{
    $ser = $pdo->prepare("SELECT da.serial_id FROM delivery_allocations da JOIN delivery_items di ON di.id = da.delivery_item_id WHERE di.delivery_id = :d AND da.serial_id IS NOT NULL");
    $ser->execute([':d' => $deliveryId]);
    foreach ($ser->fetchAll() as $r) {
        $cur = $pdo->prepare('SELECT status FROM serial_numbers WHERE id = :id'); $cur->execute([':id' => $r['serial_id']]);
        if ($cur->fetchColumn() === 'reserved') serial_transition($pdo, (int) $r['serial_id'], 'available', ['sales_order_id' => null, 'delivery_id' => null], 'serial_status');
    }
    $pdo->prepare('DELETE da FROM delivery_allocations da JOIN delivery_items di ON di.id = da.delivery_item_id WHERE di.delivery_id = :d')->execute([':d' => $deliveryId]);
    $pdo->prepare('UPDATE delivery_items SET qty_picked = 0 WHERE delivery_id = :d')->execute([':d' => $deliveryId]);
}

/**
 * Record the picked goods. $picks[delivery_item_id] = ['batches' => [batch_id => qty], 'qty' => n (untracked), 'serials' => [serial ids]].
 * A short pick reduces the delivery to what was found; the rest stays open on the order.
 */
function delivery_pick(int $deliveryId, array $picks): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $d = $pdo->prepare('SELECT * FROM deliveries WHERE id = :id FOR UPDATE'); $d->execute([':id' => $deliveryId]); $del = $d->fetch();
        if (!$del || !in_array($del['status'], ['pending_pick', 'picked'], true)) throw new RuntimeException('This delivery can no longer be picked.');
        delivery_release_picks($pdo, $deliveryId);
        $items = $pdo->prepare('SELECT di.*, p.name, p.tracking, p.base_unit FROM delivery_items di JOIN products p ON p.id = di.product_id WHERE di.delivery_id = :d');
        $items->execute([':d' => $deliveryId]);
        $any = 0;
        $insA = $pdo->prepare('INSERT INTO delivery_allocations (delivery_item_id, batch_id, serial_id, qty, unit_cost) VALUES (:i,:b,:s,:q,:c)');
        foreach ($items->fetchAll() as $it) {
            $pick = $picks[$it['id']] ?? [];
            $picked = 0; $pid = (int) $it['product_id'];
            if ($it['tracking'] === 'serial') {
                $ids = array_values(array_unique(array_map('intval', $pick['serials'] ?? [])));
                foreach ($ids as $sid) {
                    $c = $pdo->prepare('SELECT * FROM serial_numbers WHERE id = :id FOR UPDATE'); $c->execute([':id' => $sid]); $sn = $c->fetch();
                    if (!$sn || (int) $sn['product_id'] !== $pid) throw new RuntimeException('A scanned serial number does not belong to ' . $it['name'] . '.');
                    if ((int) $sn['warehouse_id'] !== (int) $del['warehouse_id']) throw new RuntimeException("Serial {$sn['serial_no']} is not in this warehouse.");
                    if ($sn['status'] !== 'available') throw new RuntimeException("Serial {$sn['serial_no']} is {$sn['status']} and cannot be picked.");
                    serial_transition($pdo, $sid, 'reserved', ['sales_order_id' => $del['so_id'], 'delivery_id' => $deliveryId], 'serial_status', ['delivery', $deliveryId, $del['dn_no']], 'Allocated to order', 'Picked for ' . $del['dn_no'] . '.');
                    $insA->execute([':i' => $it['id'], ':b' => 0, ':s' => $sid, ':q' => 1, ':c' => (float) $sn['unit_cost']]);
                    $picked++;
                }
            } elseif ($it['tracking'] === 'batch') {
                foreach (($pick['batches'] ?? []) as $batchId => $q) {
                    $q = (int) $q; if ($q <= 0) continue;
                    $avail = null;
                    foreach (saleable_batches($pid, (int) $del['warehouse_id'], $deliveryId) as $b) { if ((int) $b['id'] === (int) $batchId) { $avail = (int) $b['available']; $bn = $b['batch_no']; } }
                    if ($avail === null) throw new RuntimeException('A selected batch is expired, quarantined, recalled or has no stock for ' . $it['name'] . '.');
                    if ($q > $avail) throw new RuntimeException("Batch $bn has only $avail available for {$it['name']}.");
                    $insA->execute([':i' => $it['id'], ':b' => (int) $batchId, ':s' => null, ':q' => $q, ':c' => item_unit_cost($pid, (int) $batchId)]);
                    $picked += $q;
                }
            } else {
                $q = (int) ($pick['qty'] ?? 0);
                if ($q > 0) {
                    $on = $pdo->prepare('SELECT COALESCE(on_hand,0) FROM stock WHERE product_id = :p AND warehouse_id = :w AND batch_id = 0'); $on->execute([':p' => $pid, ':w' => $del['warehouse_id']]);
                    $other = $pdo->prepare("SELECT COALESCE(SUM(da.qty),0) FROM delivery_allocations da JOIN delivery_items di ON di.id = da.delivery_item_id JOIN deliveries d ON d.id = di.delivery_id
                        WHERE di.product_id = :p AND da.batch_id = 0 AND d.warehouse_id = :w AND d.status IN ('picked','packed') AND d.id <> :d");
                    $other->execute([':p' => $pid, ':w' => $del['warehouse_id'], ':d' => $deliveryId]);
                    $avail = (int) $on->fetchColumn() - (int) $other->fetchColumn();
                    if ($q > $avail) throw new RuntimeException("Only $avail {$it['base_unit']} of {$it['name']} are available in this warehouse.");
                    $insA->execute([':i' => $it['id'], ':b' => 0, ':s' => null, ':q' => $q, ':c' => item_unit_cost($pid)]);
                    $picked = $q;
                }
            }
            if ($picked > (int) $it['qty_planned']) throw new RuntimeException("{$it['name']}: picked $picked but only {$it['qty_planned']} were ordered on this delivery.");
            $pdo->prepare('UPDATE delivery_items SET qty_picked = :q, qty_planned = :q2 WHERE id = :id')->execute([':q' => $picked, ':q2' => $picked, ':id' => $it['id']]);
            $any += $picked;
        }
        if ($any === 0) throw new RuntimeException('Pick at least one item. If nothing is available, cancel this delivery.');
        $pdo->prepare("UPDATE deliveries SET status = 'picked', picked_by = :u, picked_at = NOW() WHERE id = :id")->execute([':u' => current_user()['id'], ':id' => $deliveryId]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('delivery_pick', "Picked goods for {$del['dn_no']}", 'delivery', $deliveryId);
}

function delivery_pack(int $deliveryId): void
{
    $pdo = db();
    $u = $pdo->prepare("UPDATE deliveries SET status = 'packed', packed_by = :u, packed_at = NOW() WHERE id = :id AND status = 'picked'");
    $u->execute([':u' => current_user()['id'], ':id' => $deliveryId]);
    if (!$u->rowCount()) throw new RuntimeException('Pick the goods before packing.');
    log_activity('delivery_pack', 'Packed delivery #' . $deliveryId, 'delivery', $deliveryId);
}

/** Dispatch: stock leaves the warehouse, cost of goods sold is recognised. */
function delivery_dispatch(int $deliveryId, ?int $driverId, ?int $vehicleId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $d = $pdo->prepare('SELECT d.*, so.so_no, c.business_name FROM deliveries d JOIN sales_orders so ON so.id = d.so_id JOIN customers c ON c.id = d.customer_id WHERE d.id = :id FOR UPDATE');
        $d->execute([':id' => $deliveryId]); $del = $d->fetch();
        if (!$del || !in_array($del['status'], ['picked', 'packed'], true)) throw new RuntimeException('Pick the goods before dispatching.');
        $al = $pdo->prepare('SELECT da.*, di.product_id, di.so_item_id, p.name, p.base_unit, p.tracking FROM delivery_allocations da JOIN delivery_items di ON di.id = da.delivery_item_id JOIN products p ON p.id = di.product_id WHERE di.delivery_id = :d');
        $al->execute([':d' => $deliveryId]);
        $cost = 0.0; $perBatch = [];
        foreach ($al->fetchAll() as $a) {
            $ref = ['delivery', $deliveryId, $del['dn_no']];
            if ($a['serial_id']) {
                serial_transition($pdo, (int) $a['serial_id'], 'dispatched', [], 'sale_dispatch', $ref, 'Dispatched', 'Dispatched to ' . $del['business_name'] . ' with delivery note ' . $del['dn_no'] . '.');
            } else {
                stock_move($pdo, ['product_id' => (int) $a['product_id'], 'warehouse_id' => (int) $del['warehouse_id'], 'batch_id' => (int) $a['batch_id'], 'qty' => -(int) $a['qty'],
                    'type' => 'sale_dispatch', 'ref_type' => 'delivery', 'ref_id' => $deliveryId, 'ref_no' => $del['dn_no'], 'unit_cost' => (float) $a['unit_cost'], 'note' => $del['business_name']]);
                if ((int) $a['batch_id']) $perBatch[(int) $a['batch_id']] = ($perBatch[(int) $a['batch_id']] ?? 0) + (int) $a['qty'];
            }
            $cost += (int) $a['qty'] * (float) $a['unit_cost'];
        }
        foreach ($perBatch as $bid => $q) {
            trace_event($pdo, 'batch', $bid, 'dispatch', 'Customer order and dispatch', number_format($q) . ' dispatched to ' . $del['business_name'] . '.', 'delivery', $deliveryId, $del['dn_no']);
        }
        $pdo->prepare('UPDATE sales_order_items soi JOIN delivery_items di ON di.so_item_id = soi.id SET soi.qty_dispatched = soi.qty_dispatched + di.qty_picked WHERE di.delivery_id = :d')->execute([':d' => $deliveryId]);
        post_journal($pdo, app_today(), 'Cost of goods dispatched Â· ' . $del['dn_no'] . ' Â· ' . $del['business_name'], 'delivery', $deliveryId, $del['dn_no'], [['5000', round2($cost), 0], ['1200', 0, round2($cost)]]);
        $pdo->prepare("UPDATE deliveries SET status = 'dispatched', driver_id = :dr, vehicle_id = :v, dispatched_by = :u, dispatched_at = NOW() WHERE id = :id")
            ->execute([':dr' => $driverId ?: null, ':v' => $vehicleId ?: null, ':u' => current_user()['id'], ':id' => $deliveryId]);
        so_refresh_status($pdo, (int) $del['so_id']);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('delivery_dispatch', "Dispatched {$del['dn_no']} to {$del['business_name']} ({$del['so_no']})", 'delivery', $deliveryId);
}

/**
 * Confirm what the customer actually received.
 * $results[delivery_item_id] = ['rejected' => qty, 'reason' => text, 'serial_rejects' => [serial ids]].
 * Rejected goods return to the warehouse quarantine for inspection.
 */
function delivery_confirm(int $deliveryId, array $results, string $receivedBy, string $podNote, bool $closeBalance): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $d = $pdo->prepare('SELECT d.*, so.so_no, so.customer_id AS so_customer, c.business_name FROM deliveries d JOIN sales_orders so ON so.id = d.so_id JOIN customers c ON c.id = d.customer_id WHERE d.id = :id FOR UPDATE');
        $d->execute([':id' => $deliveryId]); $del = $d->fetch();
        if (!$del || $del['status'] !== 'dispatched') throw new RuntimeException('Only dispatched deliveries can be confirmed.');
        $items = $pdo->prepare('SELECT di.*, p.name, p.tracking, p.warranty_months FROM delivery_items di JOIN products p ON p.id = di.product_id WHERE di.delivery_id = :d');
        $items->execute([':d' => $deliveryId]);
        $backCost = 0.0; $disc = false; $today = app_today();
        $ref = ['delivery', $deliveryId, $del['dn_no']];
        foreach ($items->fetchAll() as $it) {
            $res = $results[$it['id']] ?? [];
            $al = $pdo->prepare('SELECT * FROM delivery_allocations WHERE delivery_item_id = :i ORDER BY id'); $al->execute([':i' => $it['id']]); $allocs = $al->fetchAll();
            $rejected = 0;
            if ($it['tracking'] === 'serial') {
                $rej = array_map('intval', $res['serial_rejects'] ?? []);
                foreach ($allocs as $a) {
                    if (in_array((int) $a['serial_id'], $rej, true)) {
                        serial_transition($pdo, (int) $a['serial_id'], 'under_inspection', ['customer_id' => null, 'sales_order_id' => null, 'delivery_id' => null], 'delivery_rejected', $ref, 'Rejected at delivery', ($res['reason'] ?? '') ?: 'Rejected by ' . $del['business_name'] . '.');
                        $pdo->prepare('UPDATE delivery_allocations SET rejected_qty = 1 WHERE id = :id')->execute([':id' => $a['id']]);
                        $backCost += (float) $a['unit_cost']; $rejected++;
                    } else {
                        $wm = (int) $it['warranty_months'];
                        $exp = $wm > 0 ? date('Y-m-d', strtotime("$today +$wm months -1 day")) : null;
                        serial_transition($pdo, (int) $a['serial_id'], 'sold', ['customer_id' => $del['customer_id'], 'sold_date' => $today, 'warranty_expiry' => $exp], 'serial_status', $ref, 'Delivered to customer', 'Delivered to ' . $del['business_name'] . ' (' . $del['dn_no'] . ').');
                    }
                }
            } else {
                $rejected = min((int) $it['qty_picked'], max(0, (int) ($res['rejected'] ?? 0)));
                $left = $rejected;
                foreach (array_reverse($allocs) as $a) {
                    if ($left <= 0) break;
                    $take = min($left, (int) $a['qty']);
                    stock_move($pdo, ['product_id' => (int) $it['product_id'], 'warehouse_id' => (int) $del['warehouse_id'], 'batch_id' => (int) $a['batch_id'], 'bucket' => 'quarantine', 'qty' => $take,
                        'type' => 'delivery_rejected', 'ref_type' => 'delivery', 'ref_id' => $deliveryId, 'ref_no' => $del['dn_no'], 'unit_cost' => (float) $a['unit_cost'], 'note' => ($res['reason'] ?? '') ?: 'Rejected by customer']);
                    $pdo->prepare('UPDATE delivery_allocations SET rejected_qty = :q WHERE id = :id')->execute([':q' => $take, ':id' => $a['id']]);
                    if ((int) $a['batch_id']) trace_event($pdo, 'batch', (int) $a['batch_id'], 'rejected', 'Rejected at delivery', number_format($take) . ' returned by ' . $del['business_name'] . ' to quarantine.', 'delivery', $deliveryId, $del['dn_no']);
                    $backCost += $take * (float) $a['unit_cost'];
                    $left -= $take;
                }
            }
            if ($rejected > 0) $disc = true;
            $reason = trim((string) ($res['reason'] ?? ''));
            if ($rejected > 0 && $reason === '') throw new RuntimeException("Give a reason for the rejected quantity of {$it['name']}.");
            $pdo->prepare('UPDATE delivery_items SET qty_accepted = :a, qty_rejected = :r, reject_reason = :rs WHERE id = :id')
                ->execute([':a' => (int) $it['qty_picked'] - $rejected, ':r' => $rejected, ':rs' => $rejected ? $reason : null, ':id' => $it['id']]);
            if ($rejected > 0) {
                $pdo->prepare('UPDATE sales_order_items SET qty_dispatched = qty_dispatched - :r, qty_cancelled = qty_cancelled + :c WHERE id = :id')
                    ->execute([':r' => $rejected, ':c' => $closeBalance ? $rejected : 0, ':id' => $it['so_item_id']]);
            }
        }
        if ($backCost > 0) post_journal($pdo, app_today(), 'Rejected goods back in stock Â· ' . $del['dn_no'], 'delivery', $deliveryId, $del['dn_no'], [['1200', round2($backCost), 0], ['5000', 0, round2($backCost)]]);
        if ($closeBalance && $disc) so_recalc_totals($pdo, (int) $del['so_id']);
        $pdo->prepare("UPDATE deliveries SET status = 'delivered', delivered_at = NOW(), received_by_name = :r, pod_note = :p, has_discrepancy = :d WHERE id = :id")
            ->execute([':r' => $receivedBy ?: null, ':p' => $podNote ?: null, ':d' => $disc ? 1 : 0, ':id' => $deliveryId]);
        so_refresh_status($pdo, (int) $del['so_id']);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('delivery_confirm', "Delivery {$del['dn_no']} confirmed by customer" . ($receivedBy ? " ($receivedBy)" : '') . ($disc ? ' with shortages / rejected goods' : ''), 'delivery', $deliveryId);
}

/** Customer could not be reached / refused everything: goods return to saleable stock. */
function delivery_fail(int $deliveryId, string $reason): void
{
    $pdo = db();
    if ($reason === '') throw new RuntimeException('Give the reason the delivery failed.');
    $pdo->beginTransaction();
    try {
        $d = $pdo->prepare('SELECT * FROM deliveries WHERE id = :id FOR UPDATE'); $d->execute([':id' => $deliveryId]); $del = $d->fetch();
        if (!$del || $del['status'] !== 'dispatched') throw new RuntimeException('Only dispatched deliveries can be marked failed.');
        $al = $pdo->prepare('SELECT da.*, di.product_id FROM delivery_allocations da JOIN delivery_items di ON di.id = da.delivery_item_id WHERE di.delivery_id = :d');
        $al->execute([':d' => $deliveryId]);
        $cost = 0.0; $ref = ['delivery', $deliveryId, $del['dn_no']];
        foreach ($al->fetchAll() as $a) {
            if ($a['serial_id']) serial_transition($pdo, (int) $a['serial_id'], 'available', ['sales_order_id' => null, 'delivery_id' => null, 'customer_id' => null], 'delivery_return', $ref, 'Delivery failed', $reason);
            else {
                stock_move($pdo, ['product_id' => (int) $a['product_id'], 'warehouse_id' => (int) $del['warehouse_id'], 'batch_id' => (int) $a['batch_id'], 'qty' => (int) $a['qty'], 'type' => 'delivery_return',
                    'ref_type' => 'delivery', 'ref_id' => $deliveryId, 'ref_no' => $del['dn_no'], 'unit_cost' => (float) $a['unit_cost'], 'note' => $reason]);
                if ((int) $a['batch_id']) trace_event($pdo, 'batch', (int) $a['batch_id'], 'delivery_failed', 'Delivery failed', number_format($a['qty']) . ' returned to the warehouse: ' . $reason, 'delivery', $deliveryId, $del['dn_no']);
            }
            $cost += (int) $a['qty'] * (float) $a['unit_cost'];
        }
        $pdo->prepare('UPDATE sales_order_items soi JOIN delivery_items di ON di.so_item_id = soi.id SET soi.qty_dispatched = soi.qty_dispatched - di.qty_picked WHERE di.delivery_id = :d')->execute([':d' => $deliveryId]);
        post_journal($pdo, app_today(), 'Failed delivery returned Â· ' . $del['dn_no'], 'delivery', $deliveryId, $del['dn_no'], [['1200', round2($cost), 0], ['5000', 0, round2($cost)]]);
        $pdo->prepare("UPDATE deliveries SET status = 'failed', failure_reason = :r, delivered_at = NOW() WHERE id = :id")->execute([':r' => $reason, ':id' => $deliveryId]);
        so_refresh_status($pdo, (int) $del['so_id']);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('delivery_fail', "{$del['dn_no']} failed: $reason", 'delivery', $deliveryId);
}

function delivery_cancel(int $deliveryId, bool $inTx = false): void
{
    $pdo = db();
    if (!$inTx) $pdo->beginTransaction();
    try {
        $d = $pdo->prepare('SELECT * FROM deliveries WHERE id = :id FOR UPDATE'); $d->execute([':id' => $deliveryId]); $del = $d->fetch();
        if (!$del || !in_array($del['status'], ['pending_pick', 'picked', 'packed'], true)) throw new RuntimeException('Only deliveries that have not been dispatched can be cancelled.');
        delivery_release_picks($pdo, $deliveryId);
        $pdo->prepare("UPDATE deliveries SET status = 'cancelled' WHERE id = :id")->execute([':id' => $deliveryId]);
        so_refresh_status($pdo, (int) $del['so_id']);
        if (!$inTx) $pdo->commit();
    } catch (Throwable $e) { if (!$inTx && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('delivery_cancel', "Cancelled {$del['dn_no']}", 'delivery', $deliveryId);
}

// ---------------------------------------------------------------------
// Invoicing & collections
// ---------------------------------------------------------------------

/** Invoice the quantities the customer actually accepted on a delivered delivery note. */
function invoice_create_from_delivery(int $deliveryId, string $invoiceDate, string $payMethod = 'cash', ?string $notes = null): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $d = $pdo->prepare('SELECT d.*, so.so_no, so.payment_type, so.rep_id, c.business_name, c.payment_terms_days FROM deliveries d JOIN sales_orders so ON so.id = d.so_id JOIN customers c ON c.id = d.customer_id WHERE d.id = :id FOR UPDATE');
        $d->execute([':id' => $deliveryId]); $del = $d->fetch();
        if (!$del || $del['status'] !== 'delivered') throw new RuntimeException('Only delivered goods can be invoiced.');
        $it = $pdo->prepare('SELECT di.*, soi.unit_price, soi.discount_pct, soi.tax_rate, soi.pack_name, soi.pack_factor FROM delivery_items di JOIN sales_order_items soi ON soi.id = di.so_item_id WHERE di.delivery_id = :d AND di.qty_accepted > di.qty_invoiced');
        $it->execute([':d' => $deliveryId]);
        $lines = $it->fetchAll();
        if (!$lines) throw new RuntimeException('Everything on this delivery has already been invoiced.');
        $cash = $del['payment_type'] === 'cash';
        $due = $cash ? $invoiceDate : date('Y-m-d', strtotime($invoiceDate . ' +' . (int) $del['payment_terms_days'] . ' days'));
        $no = next_no('invoice', setting('inv_prefix', 'INV'));
        $pdo->prepare('INSERT INTO sales_invoices (inv_no, so_id, delivery_id, customer_id, rep_id, warehouse_id, invoice_date, due_date, payment_type, notes, created_by) VALUES (:n,:so,:d,:c,:r,:w,:id,:due,:pt,:no,:u)')
            ->execute([':n' => $no, ':so' => $del['so_id'], ':d' => $deliveryId, ':c' => $del['customer_id'], ':r' => $del['rep_id'], ':w' => $del['warehouse_id'], ':id' => $invoiceDate, ':due' => $due, ':pt' => $del['payment_type'], ':no' => $notes ?: null, ':u' => current_user()['id']]);
        $invId = (int) $pdo->lastInsertId();
        $sub = 0.0; $tax = 0.0; $cogs = 0.0;
        $ins = $pdo->prepare('INSERT INTO sales_invoice_items (invoice_id, delivery_item_id, product_id, pack_name, pack_factor, qty, unit_price, discount_pct, line_net, tax_rate, tax_amount, line_total, unit_cost) VALUES (:i,:di,:p,:pn,:pf,:q,:up,:d,:ln,:tr,:ta,:lt,:uc)');
        foreach ($lines as $l) {
            $q = (int) $l['qty_accepted'] - (int) $l['qty_invoiced'];
            $a = line_amounts($q, (float) $l['unit_price'], (float) $l['discount_pct'], (float) $l['tax_rate']);
            $cs = $pdo->prepare('SELECT COALESCE(SUM((qty - rejected_qty) * unit_cost),0) / NULLIF(SUM(qty - rejected_qty),0) FROM delivery_allocations WHERE delivery_item_id = :i');
            $cs->execute([':i' => $l['id']]);
            $uc = (float) $cs->fetchColumn();
            $ins->execute([':i' => $invId, ':di' => $l['id'], ':p' => $l['product_id'], ':pn' => $l['pack_name'], ':pf' => $l['pack_factor'], ':q' => $q, ':up' => $l['unit_price'], ':d' => $l['discount_pct'],
                ':ln' => $a['net'], ':tr' => $l['tax_rate'], ':ta' => $a['tax'], ':lt' => $a['total'], ':uc' => round($uc, 4)]);
            $pdo->prepare('UPDATE delivery_items SET qty_invoiced = qty_invoiced + :q WHERE id = :id')->execute([':q' => $q, ':id' => $l['id']]);
            $sub += $a['net']; $tax += $a['tax']; $cogs += $q * $uc;
            // Serial numbers sold on this invoice.
            $sr = $pdo->prepare('SELECT da.serial_id, sn.product_id FROM delivery_allocations da JOIN serial_numbers sn ON sn.id = da.serial_id WHERE da.delivery_item_id = :i AND da.serial_id IS NOT NULL AND da.rejected_qty = 0');
            $sr->execute([':i' => $l['id']]);
            foreach ($sr->fetchAll() as $s) {
                $pdo->prepare('UPDATE serial_numbers SET invoice_id = :inv WHERE id = :id')->execute([':inv' => $invId, ':id' => $s['serial_id']]);
                trace_event($pdo, 'serial', (int) $s['serial_id'], 'invoiced', 'Dispatched and sold', 'Dispatched with delivery note ' . $del['dn_no'] . ' and invoice ' . $no . ' to ' . $del['business_name'] . '.', 'sales_invoice', $invId, $no);
            }
        }
        $total = round2($sub + $tax);
        $pdo->prepare('UPDATE sales_invoices SET subtotal = :s, tax_total = :t, total = :tot, cogs = :c WHERE id = :id')->execute([':s' => round2($sub), ':t' => round2($tax), ':tot' => $total, ':c' => round2($cogs), ':id' => $invId]);
        post_journal($pdo, $invoiceDate, "Sales invoice $no Â· {$del['business_name']}", 'sales_invoice', $invId, $no, [['1100', $total, 0], ['4000', 0, round2($sub)], ['2200', 0, round2($tax)]]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('invoice_create', "$no Â· {$del['business_name']} Â· " . format_money($total) . " Â· from {$del['dn_no']}", 'sales_invoice', $invId);
    if ($cash) { customer_payment_record((int) $del['customer_id'], $total, $payMethod, $invoiceDate, null, $del['rep_id'] ? (int) $del['rep_id'] : null, 'Cash sale ' . $no, [$invId => $total]); }
    return $invId;
}

function invoice_refresh(PDO $pdo, int $invoiceId): void
{
    $s = $pdo->prepare('SELECT total, paid_amount, credited_amount, status FROM sales_invoices WHERE id = :id'); $s->execute([':id' => $invoiceId]); $i = $s->fetch();
    if (!$i || $i['status'] === 'void') return;
    $out = round2((float) $i['total'] - (float) $i['paid_amount'] - (float) $i['credited_amount']);
    $st = $out <= 0.004 ? 'paid' : (((float) $i['paid_amount'] + (float) $i['credited_amount']) > 0.004 ? 'partial' : 'unpaid');
    $pdo->prepare('UPDATE sales_invoices SET status = :s WHERE id = :id')->execute([':s' => $st, ':id' => $invoiceId]);
}

/**
 * Record a customer payment and allocate it. $map = [invoice_id => amount] or null for oldest-due-first.
 * Whatever is not allocated stays on the customer's account as an advance.
 */
function customer_payment_record(int $customerId, float $amount, string $method, string $date, ?string $reference, ?int $repId, ?string $notes, ?array $map = null): int
{
    $pdo = db();
    if ($amount <= 0) throw new RuntimeException('Payment amount must be greater than zero.');
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $c = $pdo->prepare('SELECT business_name, rep_id FROM customers WHERE id = :id'); $c->execute([':id' => $customerId]); $cust = $c->fetch();
        if (!$cust) throw new RuntimeException('Customer not found.');
        $no = next_no('receipt', 'RCP');
        $pdo->prepare('INSERT INTO customer_payments (receipt_no, customer_id, rep_id, amount, method, pay_date, reference, notes, created_by) VALUES (:n,:c,:r,:a,:m,:d,:ref,:no,:u)')
            ->execute([':n' => $no, ':c' => $customerId, ':r' => $repId ?: (current_user()['role'] === 'sales_rep' ? current_user()['id'] : $cust['rep_id']), ':a' => $amount, ':m' => $method, ':d' => $date, ':ref' => $reference ?: null, ':no' => $notes ?: null, ':u' => current_user()['id'] ?? null]);
        $payId = (int) $pdo->lastInsertId();
        post_journal($pdo, $date, "Payment $no from {$cust['business_name']}", 'customer_payment', $payId, $no, [[method_account($method), $amount, 0], ['1100', 0, $amount]]);
        payment_allocate($pdo, $payId, $map);
        if ($own) $pdo->commit();
    } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('customer_payment', "$no Â· {$cust['business_name']} Â· " . format_money($amount) . ' Â· ' . method_label($method), 'customer_payment', $payId);
    return $payId;
}

/** Allocate the unallocated part of a payment to invoices (explicit map or oldest due first). Returns amount allocated. */
function payment_allocate(PDO $pdo, int $paymentId, ?array $map = null): float
{
    $p = $pdo->prepare('SELECT * FROM customer_payments WHERE id = :id FOR UPDATE'); $p->execute([':id' => $paymentId]); $pay = $p->fetch();
    $free = round2((float) $pay['amount'] - (float) $pay['allocated']);
    if ($free <= 0.004) return 0.0;
    $open = $pdo->prepare("SELECT id, total - paid_amount - credited_amount AS due FROM sales_invoices WHERE customer_id = :c AND status IN ('unpaid','partial') ORDER BY due_date, id");
    $open->execute([':c' => $pay['customer_id']]);
    $dueMap = [];
    foreach ($open->fetchAll() as $o) $dueMap[(int) $o['id']] = round2((float) $o['due']);
    $plan = [];
    if ($map === null) {
        foreach ($dueMap as $id => $due) { if ($free <= 0.004) break; if ($due <= 0) continue; $a = min($free, $due); $plan[$id] = $a; $free = round2($free - $a); }
    } else {
        foreach ($map as $id => $a) {
            $a = round2((float) $a); $id = (int) $id;
            if ($a <= 0) continue;
            if (!isset($dueMap[$id])) throw new RuntimeException('One of the selected invoices is not open for payment.');
            if ($a > $dueMap[$id] + 0.004) throw new RuntimeException('You allocated more than an invoice\'s outstanding balance.');
            if ($a > $free + 0.004) throw new RuntimeException('Allocations exceed the payment amount.');
            $plan[$id] = $a; $free = round2($free - $a);
        }
    }
    $done = 0.0;
    foreach ($plan as $id => $a) {
        $pdo->prepare('INSERT INTO customer_payment_allocations (payment_id, invoice_id, amount) VALUES (:p,:i,:a)')->execute([':p' => $paymentId, ':i' => $id, ':a' => $a]);
        $pdo->prepare('UPDATE sales_invoices SET paid_amount = paid_amount + :a WHERE id = :id')->execute([':a' => $a, ':id' => $id]);
        invoice_refresh($pdo, $id);
        $done += $a;
    }
    $pdo->prepare('UPDATE customer_payments SET allocated = allocated + :a WHERE id = :id')->execute([':a' => $done, ':id' => $paymentId]);
    return $done;
}

/**
 * Issue a credit note against an invoice. $lines = [invoice_item_id => qty] (goods returned / short supplied),
 * or pass $amountOnly for a price / discount adjustment.
 */
function credit_note_issue(int $invoiceId, array $lines, ?string $reason, ?int $returnId = null, ?float $amountOnly = null, float $taxRate = 0.0, ?string $date = null): int
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT si.*, c.business_name FROM sales_invoices si JOIN customers c ON c.id = si.customer_id WHERE si.id = :id FOR UPDATE'); $s->execute([':id' => $invoiceId]); $inv = $s->fetch();
        if (!$inv || $inv['status'] === 'void') throw new RuntimeException('Invoice not found.');
        $net = 0.0; $tax = 0.0;
        if ($amountOnly !== null) {
            if ($amountOnly <= 0) throw new RuntimeException('Enter the credit amount.');
            $net = round2($amountOnly); $tax = round2($net * $taxRate / 100);
        } else {
            foreach ($lines as $itemId => $q) {
                $q = (int) $q; if ($q <= 0) continue;
                $it = $pdo->prepare('SELECT * FROM sales_invoice_items WHERE id = :id AND invoice_id = :i FOR UPDATE'); $it->execute([':id' => $itemId, ':i' => $invoiceId]); $row = $it->fetch();
                if (!$row) throw new RuntimeException('Invoice line not found.');
                if ($q > (int) $row['qty'] - (int) $row['returned_qty']) throw new RuntimeException('Cannot credit more than was invoiced.');
                $a = line_amounts($q, (float) $row['unit_price'], (float) $row['discount_pct'], (float) $row['tax_rate']);
                $net += $a['net']; $tax += $a['tax'];
                $pdo->prepare('UPDATE sales_invoice_items SET returned_qty = returned_qty + :q WHERE id = :id')->execute([':q' => $q, ':id' => $itemId]);
            }
            if ($net <= 0) throw new RuntimeException('Select the quantities to credit.');
        }
        $total = round2($net + $tax);
        $no = next_no('credit_note', 'CN');
        $cdate = $date ?: app_today();
        $pdo->prepare('INSERT INTO credit_notes (cn_no, customer_id, invoice_id, return_id, cn_date, subtotal, tax_total, total, reason, created_by) VALUES (:n,:c,:i,:r,:d,:s,:t,:tot,:re,:u)')
            ->execute([':n' => $no, ':c' => $inv['customer_id'], ':i' => $invoiceId, ':r' => $returnId, ':d' => $cdate, ':s' => round2($net), ':t' => round2($tax), ':tot' => $total, ':re' => $reason ?: null, ':u' => current_user()['id'] ?? null]);
        $cnId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE sales_invoices SET credited_amount = credited_amount + :a WHERE id = :id')->execute([':a' => $total, ':id' => $invoiceId]);
        invoice_refresh($pdo, $invoiceId);
        post_journal($pdo, $cdate, "Credit note $no Â· {$inv['inv_no']} Â· {$inv['business_name']}", 'credit_note', $cnId, $no, [['4100', round2($net), 0], ['2200', round2($tax), 0], ['1100', 0, $total]]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('credit_note_issue', "$no Â· {$inv['inv_no']} Â· {$inv['business_name']} Â· " . format_money($total) . ($reason ? " Â· $reason" : ''), 'credit_note', $cnId);
    return $cnId;
}

/** Void an invoice that has no payments or credits (e.g. issued by mistake). Reverses the revenue entry and re-opens the delivery for invoicing. */
function invoice_void(int $invoiceId, string $reason): void
{
    $pdo = db();
    if ($reason === '') throw new RuntimeException('Give a reason for voiding the invoice.');
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM sales_invoices WHERE id = :id FOR UPDATE'); $s->execute([':id' => $invoiceId]); $inv = $s->fetch();
        if (!$inv || $inv['status'] === 'void') throw new RuntimeException('Invoice not found.');
        if ((float) $inv['paid_amount'] > 0 || (float) $inv['credited_amount'] > 0) throw new RuntimeException('This invoice has payments or credit notes. Issue a credit note instead.');
        post_journal($pdo, app_today(), 'Void invoice ' . $inv['inv_no'], 'sales_invoice', $invoiceId, $inv['inv_no'], [['4000', (float) $inv['subtotal'], 0], ['2200', (float) $inv['tax_total'], 0], ['1100', 0, (float) $inv['total']]]);
        $pdo->prepare('UPDATE delivery_items di JOIN sales_invoice_items sii ON sii.delivery_item_id = di.id SET di.qty_invoiced = di.qty_invoiced - sii.qty WHERE sii.invoice_id = :id')->execute([':id' => $invoiceId]);
        $pdo->prepare("UPDATE sales_invoices SET status = 'void', notes = CONCAT(COALESCE(notes,''), ' VOID: ', :r) WHERE id = :id")->execute([':r' => $reason, ':id' => $invoiceId]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('invoice_void', $inv['inv_no'] . ' voided: ' . $reason, 'sales_invoice', $invoiceId);
}

// ---------------------------------------------------------------------
// Customer returns (RMA â†’ receive â†’ inspect â†’ credit note)
// ---------------------------------------------------------------------

/** Quantity of an invoice line that can still be returned (excludes credited and in-flight returns). */
function returnable_qty(int $invoiceItemId): int
{
    $s = db()->prepare("SELECT sii.qty - sii.returned_qty - COALESCE((SELECT SUM(sri.qty) FROM sales_return_items sri JOIN sales_returns sr ON sr.id = sri.return_id
        WHERE sri.invoice_item_id = sii.id AND sr.status IN ('requested','approved','received') AND sri.resolved_at IS NULL),0) FROM sales_invoice_items sii WHERE sii.id = :id");
    $s->execute([':id' => $invoiceItemId]);
    return max(0, (int) $s->fetchColumn());
}

/**
 * Raise a return authorization. $lines = [ ['invoice_item_id','qty','batch_id'?,'serial_ids'?[],'reason','condition'] ].
 */
function return_create(int $invoiceId, int $warehouseId, string $reason, array $lines): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $i = $pdo->prepare('SELECT * FROM sales_invoices WHERE id = :id'); $i->execute([':id' => $invoiceId]); $inv = $i->fetch();
        if (!$inv || $inv['status'] === 'void') throw new RuntimeException('Invoice not found.');
        $no = next_no('rma', 'RMA');
        $pdo->prepare('INSERT INTO sales_returns (rma_no, customer_id, invoice_id, warehouse_id, status, reason, requested_by) VALUES (:n,:c,:i,:w,\'requested\',:r,:u)')
            ->execute([':n' => $no, ':c' => $inv['customer_id'], ':i' => $invoiceId, ':w' => $warehouseId, ':r' => $reason ?: null, ':u' => current_user()['id']]);
        $rid = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO sales_return_items (return_id, invoice_item_id, product_id, batch_id, serial_id, qty, unit_price, discount_pct, tax_rate, unit_cost, reason, condition_note) VALUES (:r,:ii,:p,:b,:s,:q,:up,:d,:t,:c,:re,:cn)');
        $count = 0;
        foreach ($lines as $l) {
            $itemId = (int) $l['invoice_item_id'];
            $it = $pdo->prepare('SELECT sii.*, p.tracking, p.name FROM sales_invoice_items sii JOIN products p ON p.id = sii.product_id WHERE sii.id = :id AND sii.invoice_id = :i'); $it->execute([':id' => $itemId, ':i' => $invoiceId]); $row = $it->fetch();
            if (!$row) throw new RuntimeException('Invoice line not found.');
            $can = returnable_qty($itemId);
            if ($row['tracking'] === 'serial') {
                $sids = array_values(array_unique(array_map('intval', $l['serial_ids'] ?? [])));
                if (!$sids) continue;
                if (count($sids) > $can) throw new RuntimeException("{$row['name']}: only $can unit(s) can still be returned.");
                foreach ($sids as $sid) {
                    $sn = $pdo->prepare('SELECT * FROM serial_numbers WHERE id = :id'); $sn->execute([':id' => $sid]); $s = $sn->fetch();
                    if (!$s || (int) $s['invoice_id'] !== $invoiceId || !in_array($s['status'], ['sold', 'dispatched'], true)) throw new RuntimeException('Serial ' . ($s['serial_no'] ?? '') . ' was not sold on this invoice or is already returned.');
                    $dup = $pdo->prepare("SELECT COUNT(*) FROM sales_return_items sri JOIN sales_returns sr ON sr.id = sri.return_id WHERE sri.serial_id = :s AND sr.status IN ('requested','approved','received') AND sri.resolved_at IS NULL");
                    $dup->execute([':s' => $sid]);
                    if ((int) $dup->fetchColumn()) throw new RuntimeException('Serial ' . $s['serial_no'] . ' is already on an open return.');
                    $ins->execute([':r' => $rid, ':ii' => $itemId, ':p' => $row['product_id'], ':b' => 0, ':s' => $sid, ':q' => 1, ':up' => $row['unit_price'], ':d' => $row['discount_pct'], ':t' => $row['tax_rate'], ':c' => $s['unit_cost'], ':re' => $l['reason'] ?? null, ':cn' => $l['condition'] ?? null]);
                    $count++;
                }
            } else {
                $q = (int) ($l['qty'] ?? 0); if ($q <= 0) continue;
                if ($q > $can) throw new RuntimeException("{$row['name']}: only $can unit(s) can still be returned.");
                $batchId = (int) ($l['batch_id'] ?? 0);
                if ($row['tracking'] === 'batch') {
                    $chk = $pdo->prepare('SELECT COALESCE(SUM(da.qty - da.rejected_qty),0) FROM delivery_allocations da WHERE da.delivery_item_id = :di AND da.batch_id = :b'); $chk->execute([':di' => $row['delivery_item_id'], ':b' => $batchId]);
                    if (!$batchId || $q > (int) $chk->fetchColumn()) throw new RuntimeException("{$row['name']}: the returned quantity does not match the batch that was delivered to this customer.");
                }
                $ins->execute([':r' => $rid, ':ii' => $itemId, ':p' => $row['product_id'], ':b' => $batchId, ':s' => null, ':q' => $q, ':up' => $row['unit_price'], ':d' => $row['discount_pct'], ':t' => $row['tax_rate'], ':c' => $row['unit_cost'], ':re' => $l['reason'] ?? null, ':cn' => $l['condition'] ?? null]);
                $count++;
            }
        }
        if (!$count) throw new RuntimeException('Select at least one item to return.');
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('return_create', "$no raised against {$inv['inv_no']}" . ($reason ? ": $reason" : ''), 'sales_return', $rid);
    return $rid;
}

function return_decide(int $rid, bool $approve, string $note): void
{
    $pdo = db();
    $s = $pdo->prepare('SELECT * FROM sales_returns WHERE id = :id'); $s->execute([':id' => $rid]); $r = $s->fetch();
    if (!$r || $r['status'] !== 'requested') throw new RuntimeException('This return is not awaiting a decision.');
    if (!$approve && $note === '') throw new RuntimeException('Give a reason for rejecting the return.');
    $pdo->prepare('UPDATE sales_returns SET status = :s, approved_by = :u, approved_at = NOW(), decision_note = :n WHERE id = :id')->execute([':s' => $approve ? 'approved' : 'rejected', ':u' => current_user()['id'], ':n' => $note ?: null, ':id' => $rid]);
    log_activity($approve ? 'return_approve' : 'return_reject', $r['rma_no'] . ($approve ? ' approved' : ' rejected: ' . $note), 'sales_return', $rid);
}

/** Warehouse receives the returned goods: they go to quarantine / under inspection â€” never straight back to saleable stock. */
function return_receive(int $rid): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM sales_returns WHERE id = :id FOR UPDATE'); $s->execute([':id' => $rid]); $r = $s->fetch();
        if (!$r || $r['status'] !== 'approved') throw new RuntimeException('Only approved returns can be received.');
        $it = $pdo->prepare('SELECT sri.*, p.tracking FROM sales_return_items sri JOIN products p ON p.id = sri.product_id WHERE sri.return_id = :id'); $it->execute([':id' => $rid]);
        $cost = 0.0; $ref = ['sales_return', $rid, $r['rma_no']];
        foreach ($it->fetchAll() as $l) {
            if ($l['serial_id']) serial_transition($pdo, (int) $l['serial_id'], 'under_inspection', [], 'sale_return', $ref, 'Customer return', 'Returned: ' . ($l['reason'] ?: 'no reason given') . '. Awaiting technical inspection.', (int) $r['warehouse_id']);
            else {
                stock_move($pdo, ['product_id' => (int) $l['product_id'], 'warehouse_id' => (int) $r['warehouse_id'], 'batch_id' => (int) $l['batch_id'], 'bucket' => 'quarantine', 'qty' => (int) $l['qty'],
                    'type' => 'sale_return', 'ref_type' => 'sales_return', 'ref_id' => $rid, 'ref_no' => $r['rma_no'], 'unit_cost' => (float) $l['unit_cost'], 'note' => $l['reason']]);
                if ((int) $l['batch_id']) trace_event($pdo, 'batch', (int) $l['batch_id'], 'returned', 'Customer return', number_format($l['qty']) . ' returned to quarantine: ' . ($l['reason'] ?: 'no reason given'), 'sales_return', $rid, $r['rma_no']);
            }
            $cost += (int) $l['qty'] * (float) $l['unit_cost'];
        }
        post_journal($pdo, app_today(), 'Customer return received Â· ' . $r['rma_no'], 'sales_return', $rid, $r['rma_no'], [['1200', round2($cost), 0], ['5000', 0, round2($cost)]]);
        $pdo->prepare("UPDATE sales_returns SET status = 'received', received_at = NOW() WHERE id = :id")->execute([':id' => $rid]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('return_receive', "Received goods for {$r['rma_no']} into quarantine", 'sales_return', $rid);
}

function return_dispositions(bool $serial): array
{
    $d = ['restock' => 'Restock â€” approved as saleable', 'dispose' => 'Dispose â€” write off', 'return_supplier' => 'Send back to supplier'];
    if ($serial) { $d['repair'] = 'Send for repair'; $d['replace'] = 'Replace â€” defective unit kept as damaged'; }
    return $d;
}

/** Inspection decision for one returned line. Closes the return and issues the credit note when every line is resolved. */
function return_resolve(int $lineId, string $disposition, string $note, bool $giveCredit): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT sri.*, sr.status AS rstatus, sr.warehouse_id, sr.rma_no, sr.invoice_id, p.tracking, p.name FROM sales_return_items sri JOIN sales_returns sr ON sr.id = sri.return_id JOIN products p ON p.id = sri.product_id WHERE sri.id = :id FOR UPDATE');
        $s->execute([':id' => $lineId]); $l = $s->fetch();
        if (!$l || $l['rstatus'] !== 'received') throw new RuntimeException('Receive the returned goods before inspecting them.');
        if ($l['disposition'] !== 'pending' && $l['disposition'] !== 'repair') throw new RuntimeException('This line has already been resolved.');
        $wh = (int) $l['warehouse_id']; $q = (int) $l['qty']; $cost = (float) $l['unit_cost'];
        $ref = ['sales_return', (int) $l['return_id'], $l['rma_no']];
        $mv = ['product_id' => (int) $l['product_id'], 'warehouse_id' => $wh, 'batch_id' => (int) $l['batch_id'], 'unit_cost' => $cost, 'ref_type' => 'sales_return', 'ref_id' => (int) $l['return_id'], 'ref_no' => $l['rma_no'], 'note' => $note];
        $serial = $l['serial_id'] ? (int) $l['serial_id'] : null;
        if (!array_key_exists($disposition, return_dispositions((bool) $serial))) throw new RuntimeException('Choose a valid inspection result.');
        $resolved = true;
        switch ($disposition) {
            case 'restock':
                if ($serial) serial_transition($pdo, $serial, 'available', [], 'return_restock', $ref, 'Inspection passed', 'Approved as saleable and returned to stock. ' . $note);
                else {
                    stock_move($pdo, $mv + ['bucket' => 'quarantine', 'qty' => -$q, 'type' => 'return_restock']);
                    stock_move($pdo, $mv + ['qty' => $q, 'type' => 'return_restock']);
                    if ((int) $l['batch_id']) trace_event($pdo, 'batch', (int) $l['batch_id'], 'restocked', 'Return restocked', number_format($q) . ' inspected and approved as saleable.', 'sales_return', (int) $l['return_id'], $l['rma_no']);
                }
                break;
            case 'repair':
                if ($l['disposition'] === 'repair') throw new RuntimeException('Already under repair. Choose the final result once repaired.');
                serial_transition($pdo, $serial, 'under_repair', [], 'serial_status', $ref, 'Sent for repair', $note);
                $resolved = false;
                break;
            case 'replace':
                serial_transition($pdo, $serial, 'damaged', [], 'return_damaged', $ref, 'Replaced under warranty', 'Defective unit kept as damaged stock. ' . $note);
                $giveCredit = false;
                break;
            case 'dispose':
                if ($serial) serial_transition($pdo, $serial, 'written_off', [], 'return_dispose', $ref, 'Written off', 'Disposed after inspection. ' . $note);
                else {
                    stock_move($pdo, $mv + ['bucket' => 'quarantine', 'qty' => -$q, 'type' => 'return_dispose']);
                    if ((int) $l['batch_id']) trace_event($pdo, 'batch', (int) $l['batch_id'], 'disposed', 'Returned stock written off', number_format($q) . ' disposed: ' . $note, 'sales_return', (int) $l['return_id'], $l['rma_no']);
                }
                post_journal($pdo, app_today(), 'Returned goods written off Â· ' . $l['rma_no'], 'sales_return', (int) $l['return_id'], $l['rma_no'], [['5100', round2($q * $cost), 0], ['1200', 0, round2($q * $cost)]]);
                break;
            case 'return_supplier':
                $sup = null;
                if ($serial) { $x = $pdo->prepare('SELECT supplier_id FROM serial_numbers WHERE id = :id'); $x->execute([':id' => $serial]); $sup = $x->fetchColumn(); }
                elseif ((int) $l['batch_id']) { $x = $pdo->prepare('SELECT supplier_id FROM batches WHERE id = :id'); $x->execute([':id' => $l['batch_id']]); $sup = $x->fetchColumn(); }
                if (!$sup) throw new RuntimeException('The supplier of these goods is unknown. Choose "Dispose" instead.');
                purchase_return_create((int) $sup, $wh, app_today(), 'Customer return ' . $l['rma_no'] . ($note ? ': ' . $note : ''),
                    [['product_id' => (int) $l['product_id'], 'batch_id' => (int) $l['batch_id'], 'serial_id' => $serial, 'qty' => $q, 'unit_cost' => $cost, 'bucket' => 'quarantine']]);
                break;
        }
        $pdo->prepare('UPDATE sales_return_items SET disposition = :d, give_credit = :g, resolved_at = :ra WHERE id = :id')->execute([':d' => $disposition, ':g' => $giveCredit ? 1 : 0, ':ra' => $resolved ? date('Y-m-d H:i:s') : null, ':id' => $lineId]);
        if ($note !== '') $pdo->prepare('UPDATE sales_return_items SET condition_note = CONCAT(COALESCE(condition_note,\'\'), :n) WHERE id = :id')->execute([':n' => ' | Inspection: ' . $note, ':id' => $lineId]);
        // Close the return and issue the credit note when every line is resolved.
        $open = $pdo->prepare('SELECT COUNT(*) FROM sales_return_items WHERE return_id = :r AND resolved_at IS NULL'); $open->execute([':r' => $l['return_id']]);
        if ((int) $open->fetchColumn() === 0) return_close($pdo, (int) $l['return_id']);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('return_inspect', "{$l['rma_no']} Â· {$l['name']} Â· result: " . str_replace('_', ' ', $disposition) . ($note ? " Â· $note" : ''), 'sales_return', (int) $l['return_id']);
}

function return_close(PDO $pdo, int $rid): void
{
    $r = $pdo->prepare('SELECT * FROM sales_returns WHERE id = :id'); $r->execute([':id' => $rid]); $ret = $r->fetch();
    $it = $pdo->prepare('SELECT invoice_item_id, SUM(qty) q FROM sales_return_items WHERE return_id = :id AND give_credit = 1 GROUP BY invoice_item_id'); $it->execute([':id' => $rid]);
    $lines = [];
    foreach ($it->fetchAll() as $x) $lines[(int) $x['invoice_item_id']] = (int) $x['q'];
    $cn = null;
    if ($lines) $cn = credit_note_issue((int) $ret['invoice_id'], $lines, 'Return ' . $ret['rma_no'], $rid);
    $pdo->prepare("UPDATE sales_returns SET status = 'closed', closed_at = NOW(), credit_note_id = :cn WHERE id = :id")->execute([':cn' => $cn, ':id' => $rid]);
}

// ---------------------------------------------------------------------
// Statements & ageing
// ---------------------------------------------------------------------

/** Customer statement: opening balance + invoices, credit notes and payments with a running balance. */
function customer_statement(int $customerId, string $from, string $to): array
{
    $pdo = db();
    $ev = [];
    $q = $pdo->prepare("SELECT invoice_date d, 'Invoice' t, inv_no ref, total debit, 0 credit, id FROM sales_invoices WHERE customer_id = :c AND status <> 'void'
        UNION ALL SELECT cn_date, 'Credit note', cn_no, 0, total, id FROM credit_notes WHERE customer_id = :c2
        UNION ALL SELECT pay_date, 'Payment', receipt_no, 0, amount, id FROM customer_payments WHERE customer_id = :c3");
    $q->execute([':c' => $customerId, ':c2' => $customerId, ':c3' => $customerId]);
    $opening = 0.0;
    $rows = $q->fetchAll();
    usort($rows, fn($a, $b) => [$a['d'], $a['t'] === 'Invoice' ? 0 : 1, $a['id']] <=> [$b['d'], $b['t'] === 'Invoice' ? 0 : 1, $b['id']]);
    foreach ($rows as $r) {
        if ($r['d'] < $from) { $opening += (float) $r['debit'] - (float) $r['credit']; continue; }
        if ($r['d'] > $to) continue;
        $ev[] = $r;
    }
    $bal = $opening;
    foreach ($ev as &$e) { $bal += (float) $e['debit'] - (float) $e['credit']; $e['balance'] = $bal; }
    return ['opening' => $opening, 'rows' => $ev, 'closing' => $bal];
}

/** Open invoices with ageing buckets. Filters: customer, rep. */
function ar_open_invoices(?int $customerId = null, ?int $repId = null): array
{
    $w = "si.status IN ('unpaid','partial')"; $p = [];
    if ($customerId) { $w .= ' AND si.customer_id = :c'; $p[':c'] = $customerId; }
    if ($repId) { $w .= ' AND c.rep_id = :r'; $p[':r'] = $repId; }
    $s = db()->prepare("SELECT si.*, c.business_name, c.rep_id, c.credit_limit, u.full_name AS rep_name, (si.total - si.paid_amount - si.credited_amount) AS outstanding, DATEDIFF(CURDATE(), si.due_date) AS days_overdue
        FROM sales_invoices si JOIN customers c ON c.id = si.customer_id LEFT JOIN users u ON u.id = c.rep_id WHERE $w AND (si.total - si.paid_amount - si.credited_amount) > 0.004 ORDER BY si.due_date, si.id");
    $s->execute($p);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) $r['bucket'] = ageing_bucket((int) $r['days_overdue']);
    return $rows;
}
