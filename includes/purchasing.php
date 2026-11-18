<?php
/**
 * Procurement engine: purchase orders (with approval), goods receiving
 * (batch / serial capture), supplier invoices with 3-way matching,
 * supplier payments and returns to supplier.
 *
 * A purchase order is NOT a goods receipt: stock only increases when the
 * warehouse records what actually arrived.
 */

// ---------------------------------------------------------------------
// Purchase orders
// ---------------------------------------------------------------------

/** Create / update a DRAFT purchase order. $items: [ ['product_id','pack_name','pack_factor','pack_qty','unit_cost'(per pack)] ] */
function po_save(?int $poId, array $d, array $items): int
{
    $pdo = db();
    if (!(int) $d['supplier_id']) throw new RuntimeException('Select the supplier.');
    if (!(int) $d['warehouse_id']) throw new RuntimeException('Select the receiving warehouse.');
    if (!$items) throw new RuntimeException('Add at least one product to the order.');
    if (!empty($d['expected_date']) && $d['expected_date'] < $d['order_date']) throw new RuntimeException('Expected delivery cannot be before the order date.');
    $rows = []; $sub = 0.0; $tax = 0.0;
    foreach ($items as $it) {
        $pid = (int) ($it['product_id'] ?? 0); $factor = max(1, (int) ($it['pack_factor'] ?? 1));
        $qty = (int) round((float) ($it['pack_qty'] ?? 0) * $factor);
        $cost = round((float) ($it['unit_cost'] ?? 0) / $factor, 4);
        if (!$pid || $qty <= 0 || $cost < 0) throw new RuntimeException('Each line needs a product, a quantity and a cost.');
        $pr = $pdo->prepare('SELECT * FROM products WHERE id = :id'); $pr->execute([':id' => $pid]); $prod = $pr->fetch();
        if (!$prod) throw new RuntimeException('A selected product no longer exists.');
        $packName = $factor > 1 ? trim((string) ($it['pack_name'] ?? '')) : $prod['base_unit'];
        $a = line_amounts($qty, $cost, 0, (float) $prod['tax_rate']);
        $rows[] = [$pid, $packName ?: null, $factor, $qty, $cost, (float) $prod['tax_rate'], $a['net']];
        $sub += $a['net']; $tax += $a['tax'];
    }
    $total = round2($sub + $tax);
    $pdo->beginTransaction();
    try {
        if ($poId) {
            $c = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id FOR UPDATE'); $c->execute([':id' => $poId]); $po = $c->fetch();
            if (!$po || !in_array($po['status'], ['draft', 'rejected'], true)) throw new RuntimeException('Only draft purchase orders can be edited.');
            $pdo->prepare('UPDATE purchase_orders SET supplier_id=:s, warehouse_id=:w, order_date=:od, expected_date=:ed, notes=:n, subtotal=:sub, tax_total=:t, total=:tot, status=\'draft\' WHERE id=:id')
                ->execute([':s' => $d['supplier_id'], ':w' => $d['warehouse_id'], ':od' => $d['order_date'], ':ed' => $d['expected_date'] ?: null, ':n' => $d['notes'] ?: null, ':sub' => $sub, ':t' => $tax, ':tot' => $total, ':id' => $poId]);
            $pdo->prepare('DELETE FROM purchase_order_items WHERE po_id = :id')->execute([':id' => $poId]);
            $no = $po['po_no'];
        } else {
            $no = next_no('purchase_order', setting('po_prefix', 'PO'));
            $pdo->prepare('INSERT INTO purchase_orders (po_no, supplier_id, warehouse_id, status, order_date, expected_date, notes, subtotal, tax_total, total, created_by) VALUES (:n,:s,:w,\'draft\',:od,:ed,:no,:sub,:t,:tot,:u)')
                ->execute([':n' => $no, ':s' => $d['supplier_id'], ':w' => $d['warehouse_id'], ':od' => $d['order_date'], ':ed' => $d['expected_date'] ?: null, ':no' => $d['notes'] ?: null, ':sub' => $sub, ':t' => $tax, ':tot' => $total, ':u' => current_user()['id']]);
            $poId = (int) $pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO purchase_order_items (po_id, product_id, pack_name, pack_factor, qty, unit_cost, tax_rate, line_total) VALUES (:po,:p,:pn,:pf,:q,:c,:t,:lt)');
        foreach ($rows as [$pid, $pn, $pf, $q, $c, $tr, $lt]) $ins->execute([':po' => $poId, ':p' => $pid, ':pn' => $pn, ':pf' => $pf, ':q' => $q, ':c' => $c, ':t' => $tr, ':lt' => $lt]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('po_save', "Saved draft $no Â· " . format_money($total), 'purchase_order', $poId);
    return $poId;
}

/** Submit a draft PO: approved at once below the approval threshold, otherwise sent for approval. */
function po_submit(int $poId): string
{
    $pdo = db();
    $s = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id'); $s->execute([':id' => $poId]); $po = $s->fetch();
    if (!$po || $po['status'] !== 'draft') throw new RuntimeException('Only draft purchase orders can be submitted.');
    $thr = (float) setting('po_approval_threshold', '0');
    $needs = $thr > 0 && (float) $po['total'] >= $thr && !can('purchase.approve');
    $status = $needs ? 'pending_approval' : 'approved';
    $pdo->prepare('UPDATE purchase_orders SET status = :s, approved_by = :u, approved_at = :a WHERE id = :id')
        ->execute([':s' => $status, ':u' => $needs ? null : current_user()['id'], ':a' => $needs ? null : date('Y-m-d H:i:s'), ':id' => $poId]);
    log_activity($needs ? 'po_submit' : 'po_approve', $po['po_no'] . ($needs ? ' submitted for approval (' . format_money($po['total']) . ')' : ' approved'), 'purchase_order', $poId);
    return $status;
}

function po_decide(int $poId, bool $approve, string $note): void
{
    $pdo = db();
    $s = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id'); $s->execute([':id' => $poId]); $po = $s->fetch();
    if (!$po || $po['status'] !== 'pending_approval') throw new RuntimeException('This purchase order is not awaiting approval.');
    if (!$approve && $note === '') throw new RuntimeException('Give a reason for rejecting the purchase order.');
    $pdo->prepare('UPDATE purchase_orders SET status = :s, approved_by = :u, approved_at = NOW(), decision_note = :n WHERE id = :id')->execute([':s' => $approve ? 'approved' : 'rejected', ':u' => current_user()['id'], ':n' => $note ?: null, ':id' => $poId]);
    log_activity($approve ? 'po_approve' : 'po_reject', $po['po_no'] . ($approve ? ' approved' : ' rejected: ' . $note), 'purchase_order', $poId);
}

function po_mark_sent(int $poId): void
{
    $u = db()->prepare("UPDATE purchase_orders SET status = 'sent', sent_at = NOW() WHERE id = :id AND status = 'approved'");
    $u->execute([':id' => $poId]);
    if (!$u->rowCount()) throw new RuntimeException('Only approved purchase orders can be sent to the supplier.');
    log_activity('po_send', 'Purchase order #' . $poId . ' sent to supplier', 'purchase_order', $poId);
}

function po_cancel(int $poId, string $reason): void
{
    $pdo = db();
    $s = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id'); $s->execute([':id' => $poId]); $po = $s->fetch();
    if (!$po || !in_array($po['status'], ['draft', 'pending_approval', 'approved', 'sent'], true)) throw new RuntimeException('This purchase order can no longer be cancelled.');
    $pdo->prepare("UPDATE purchase_orders SET status = 'cancelled', decision_note = :r WHERE id = :id")->execute([':r' => $reason ?: null, ':id' => $poId]);
    log_activity('po_cancel', $po['po_no'] . ' cancelled' . ($reason ? ': ' . $reason : ''), 'purchase_order', $poId);
}

/** Close a part-received PO: the undelivered balance will not arrive. */
function po_close(int $poId): void
{
    $pdo = db();
    $u = $pdo->prepare("UPDATE purchase_orders SET status = 'closed' WHERE id = :id AND status = 'partially_received'");
    $u->execute([':id' => $poId]);
    if (!$u->rowCount()) throw new RuntimeException('Only part-received orders can be closed.');
    log_activity('po_close', 'Purchase order #' . $poId . ' closed with short delivery', 'purchase_order', $poId);
}

function po_lines_with_balance(int $poId): array
{
    $s = db()->prepare('SELECT poi.*, p.code, p.name, p.base_unit, p.tracking, p.track_expiry, (poi.qty - poi.received_qty) AS remaining FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id WHERE poi.po_id = :id ORDER BY poi.id');
    $s->execute([':id' => $poId]);
    return $s->fetchAll();
}

// ---------------------------------------------------------------------
// Goods receiving
// ---------------------------------------------------------------------

/**
 * Post a goods receipt. $lines: [ ['po_item_id'?,'product_id','qty_received','qty_rejected','reject_reason','unit_cost' (base),
 *   'batches' => [['batch_no','mfg_date','expiry_date','qty']], 'serials' => ['SN1','SN2']] ].
 * Short deliveries are recorded as they are: only what arrived enters stock.
 */
function grn_post(array $d, array $lines): int
{
    $pdo = db();
    $supplierId = (int) $d['supplier_id']; $whId = (int) $d['warehouse_id'];
    if (!$supplierId || !$whId) throw new RuntimeException('Select the supplier and warehouse.');
    $pdo->beginTransaction();
    try {
        $sup = $pdo->prepare('SELECT name FROM suppliers WHERE id = :id'); $sup->execute([':id' => $supplierId]); $supName = $sup->fetchColumn();
        $wn = $pdo->prepare('SELECT name FROM warehouses WHERE id = :id'); $wn->execute([':id' => $whId]); $whName = $wn->fetchColumn();
        $po = null;
        if (!empty($d['po_id'])) {
            $p = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id FOR UPDATE'); $p->execute([':id' => $d['po_id']]); $po = $p->fetch();
            if (!$po || !in_array($po['status'], ['approved', 'sent', 'partially_received'], true)) throw new RuntimeException('This purchase order is not open for receiving.');
        }
        $date = $d['received_date'];
        $no = next_no('grn', setting('grn_prefix', 'GRN'));
        $pdo->prepare('INSERT INTO goods_receipts (grn_no, po_id, supplier_id, warehouse_id, received_date, supplier_ref, notes, created_by) VALUES (:n,:po,:s,:w,:d,:r,:no,:u)')
            ->execute([':n' => $no, ':po' => $po['id'] ?? null, ':s' => $supplierId, ':w' => $whId, ':d' => $date, ':r' => $d['supplier_ref'] ?: null, ':no' => $d['notes'] ?: null, ':u' => current_user()['id']]);
        $grnId = (int) $pdo->lastInsertId();
        $insI = $pdo->prepare('INSERT INTO grn_items (grn_id, po_item_id, product_id, qty_received, qty_rejected, unit_cost, tax_rate, batch_id, reject_reason) VALUES (:g,:pi,:p,:q,:rj,:c,:t,:b,:rs)');
        $value = 0.0; $taxValue = 0.0; $any = false;
        foreach ($lines as $l) {
            $q = (int) $l['qty_received']; $rej = (int) ($l['qty_rejected'] ?? 0);
            if ($q <= 0 && $rej <= 0) continue;
            $pid = (int) $l['product_id'];
            $pr = $pdo->prepare('SELECT * FROM products WHERE id = :id'); $pr->execute([':id' => $pid]); $prod = $pr->fetch();
            $poItem = null;
            if (!empty($l['po_item_id'])) {
                $x = $pdo->prepare('SELECT * FROM purchase_order_items WHERE id = :id AND po_id = :po FOR UPDATE'); $x->execute([':id' => $l['po_item_id'], ':po' => $po['id'] ?? 0]); $poItem = $x->fetch();
                if (!$poItem) throw new RuntimeException('A receipt line does not belong to this purchase order.');
                $rem = (int) $poItem['qty'] - (int) $poItem['received_qty'];
                if ($q > $rem) throw new RuntimeException($prod['name'] . ': you entered ' . number_format($q) . ' but only ' . number_format($rem) . ' are still expected on the purchase order.');
            }
            if ($rej > 0 && trim((string) ($l['reject_reason'] ?? '')) === '') throw new RuntimeException($prod['name'] . ': give a reason for the rejected quantity.');
            $cost = (float) $l['unit_cost'];
            if ($q > 0) {
                $any = true;
                update_avg_cost($pdo, $pid, $q, $cost);
                if ($prod['tracking'] === 'serial') {
                    $sn = array_values(array_filter(array_map('trim', $l['serials'] ?? []), fn($x) => $x !== ''));
                    if (count($sn) !== $q) throw new RuntimeException($prod['name'] . ': enter exactly ' . $q . ' serial number(s) â€” ' . count($sn) . ' entered.');
                    if (count(array_unique(array_map('strtoupper', $sn))) !== count($sn)) throw new RuntimeException($prod['name'] . ': the same serial number appears twice.');
                    $info = ['supplier_id' => $supplierId, 'po_id' => $po['id'] ?? null, 'grn_id' => $grnId, 'grn_no' => $no, 'po_no' => $po['po_no'] ?? null, 'received_date' => $date, 'unit_cost' => $cost, 'supplier_name' => $supName, 'warehouse_name' => $whName];
                    foreach ($sn as $serial) serial_register($pdo, $pid, $serial, $whId, $info);
                    $insI->execute([':g' => $grnId, ':pi' => $poItem['id'] ?? null, ':p' => $pid, ':q' => $q, ':rj' => $rej, ':c' => $cost, ':t' => $prod['tax_rate'], ':b' => 0, ':rs' => $l['reject_reason'] ?? null]);
                } elseif ($prod['tracking'] === 'batch') {
                    $bl = array_values(array_filter($l['batches'] ?? [], fn($b) => (int) ($b['qty'] ?? 0) > 0));
                    if (!$bl) throw new RuntimeException($prod['name'] . ': enter the batch number and quantity.');
                    if (array_sum(array_map(fn($b) => (int) $b['qty'], $bl)) !== $q) throw new RuntimeException($prod['name'] . ': batch quantities must add up to the received quantity (' . number_format($q) . ').');
                    foreach ($bl as $i => $b) {
                        $bq = (int) $b['qty'];
                        $exp = trim((string) ($b['expiry_date'] ?? ''));
                        if ((int) $prod['track_expiry'] === 1 && $exp === '') throw new RuntimeException($prod['name'] . ': enter the expiry date for batch ' . ($b['batch_no'] ?? '') . '.');
                        $mfg = trim((string) ($b['mfg_date'] ?? ''));
                        if ($mfg !== '' && $exp !== '' && $exp < $mfg) throw new RuntimeException($prod['name'] . ': expiry date is before the manufacturing date.');
                        $batchId = batch_receive($pdo, $pid, (string) $b['batch_no'], ['supplier_id' => $supplierId, 'grn_id' => $grnId, 'received_date' => $date, 'mfg_date' => $mfg, 'expiry_date' => $exp, 'unit_cost' => $cost], $bq);
                        stock_move($pdo, ['product_id' => $pid, 'warehouse_id' => $whId, 'batch_id' => $batchId, 'qty' => $bq, 'type' => 'purchase', 'ref_type' => 'goods_receipt', 'ref_id' => $grnId, 'ref_no' => $no, 'unit_cost' => $cost, 'note' => $supName]);
                        trace_event($pdo, 'batch', $batchId, 'received', 'Goods received', number_format($bq) . ' ' . $prod['base_unit'] . ' received from ' . $supName . '.', 'goods_receipt', $grnId, $no);
                        trace_event($pdo, 'batch', $batchId, 'stored', 'Warehouse storage', 'Stored in ' . $whName . '.');
                        $insI->execute([':g' => $grnId, ':pi' => $poItem['id'] ?? null, ':p' => $pid, ':q' => $bq, ':rj' => $i === 0 ? $rej : 0, ':c' => $cost, ':t' => $prod['tax_rate'], ':b' => $batchId, ':rs' => $i === 0 ? ($l['reject_reason'] ?? null) : null]);
                    }
                } else {
                    stock_move($pdo, ['product_id' => $pid, 'warehouse_id' => $whId, 'qty' => $q, 'type' => 'purchase', 'ref_type' => 'goods_receipt', 'ref_id' => $grnId, 'ref_no' => $no, 'unit_cost' => $cost, 'note' => $supName]);
                    $insI->execute([':g' => $grnId, ':pi' => $poItem['id'] ?? null, ':p' => $pid, ':q' => $q, ':rj' => $rej, ':c' => $cost, ':t' => $prod['tax_rate'], ':b' => 0, ':rs' => $l['reject_reason'] ?? null]);
                }
                $lineVal = round2($q * $cost);
                $value += $lineVal; $taxValue += round2($lineVal * (float) $prod['tax_rate'] / 100);
                if ($poItem) $pdo->prepare('UPDATE purchase_order_items SET received_qty = received_qty + :q WHERE id = :id')->execute([':q' => $q, ':id' => $poItem['id']]);
            } else {
                $insI->execute([':g' => $grnId, ':pi' => $poItem['id'] ?? null, ':p' => $pid, ':q' => 0, ':rj' => $rej, ':c' => $cost, ':t' => $prod['tax_rate'], ':b' => 0, ':rs' => $l['reject_reason'] ?? null]);
                $any = true;
            }
        }
        if (!$any) throw new RuntimeException('Enter the quantity received for at least one product.');
        $pdo->prepare('UPDATE goods_receipts SET total_value = :v, tax_value = :t WHERE id = :id')->execute([':v' => round2($value), ':t' => round2($taxValue), ':id' => $grnId]);
        post_journal($pdo, $date, "Goods received $no Â· $supName", 'goods_receipt', $grnId, $no, [['1200', round2($value), 0], ['2100', 0, round2($value)]]);
        if ($po) {
            $rem = (int) $pdo->query('SELECT COALESCE(SUM(qty - received_qty),0) FROM purchase_order_items WHERE po_id = ' . (int) $po['id'])->fetchColumn();
            $pdo->prepare('UPDATE purchase_orders SET status = :s WHERE id = :id')->execute([':s' => $rem <= 0 ? 'received' : 'partially_received', ':id' => $po['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('grn_create', "$no Â· $supName Â· " . format_money($value) . ($po ? ' Â· against ' . $po['po_no'] : ''), 'goods_receipt', $grnId);
    return $grnId;
}

// ---------------------------------------------------------------------
// Supplier invoices (3-way match) and payments
// ---------------------------------------------------------------------

/** Record a supplier invoice and match it against the goods receipts it covers. */
function supplier_bill_create(array $d, array $grnIds): int
{
    $pdo = db();
    $supplierId = (int) $d['supplier_id'];
    $sub = round2((float) $d['subtotal']); $tax = round2((float) ($d['tax_total'] ?? 0)); $total = round2($sub + $tax);
    if ($sub <= 0) throw new RuntimeException('Enter the invoice amount.');
    if (trim((string) $d['supplier_ref']) === '') throw new RuntimeException("Enter the supplier's invoice number.");
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id'); $s->execute([':id' => $supplierId]); $sup = $s->fetch();
        if (!$sup) throw new RuntimeException('Select the supplier.');
        $dup = $pdo->prepare("SELECT COUNT(*) FROM supplier_invoices WHERE supplier_id = :s AND supplier_ref = :r AND status <> 'void'"); $dup->execute([':s' => $supplierId, ':r' => $d['supplier_ref']]);
        if ((int) $dup->fetchColumn()) throw new RuntimeException('This supplier invoice number is already recorded.');
        $grnValue = 0.0; $grnTax = 0.0; $poId = $d['po_id'] ?: null; $grns = [];
        foreach ($grnIds as $gid) {
            $g = $pdo->prepare('SELECT * FROM goods_receipts WHERE id = :id AND supplier_id = :s FOR UPDATE'); $g->execute([':id' => (int) $gid, ':s' => $supplierId]); $grn = $g->fetch();
            if (!$grn) throw new RuntimeException('A selected goods receipt does not belong to this supplier.');
            if ($grn['supplier_invoice_id']) throw new RuntimeException($grn['grn_no'] . ' is already invoiced.');
            $grnValue += (float) $grn['total_value']; $grnTax += (float) $grn['tax_value']; $grns[] = $grn;
            $poId = $poId ?: $grn['po_id'];
        }
        $variance = $grns ? round2($sub - $grnValue) : 0.0;
        $tol = (float) setting('invoice_match_tolerance_pct', '1');
        if (!$grns) { $match = 'unmatched'; $note = 'No goods receipt linked â€” invoice posted against goods-received-not-invoiced.'; }
        else {
            $expected = $grnValue + $grnTax; $diff = $total - $expected;
            $match = abs($diff) <= max(0.01, $expected * $tol / 100) ? 'matched' : 'variance';
            $note = $match === 'matched' ? 'Matches goods received (' . format_money($expected) . ').' : 'Invoice ' . format_money($total) . ' vs goods received ' . format_money($expected) . ' (difference ' . format_money($diff) . ').';
        }
        $no = next_no('supplier_bill', 'BILL');
        $due = date('Y-m-d', strtotime($d['invoice_date'] . ' +' . (int) $sup['payment_terms_days'] . ' days'));
        $pdo->prepare('INSERT INTO supplier_invoices (bill_no, supplier_ref, supplier_id, po_id, invoice_date, due_date, subtotal, tax_total, total, grn_value, variance, match_status, match_note, notes, created_by)
                       VALUES (:n,:r,:s,:po,:d,:due,:sub,:t,:tot,:gv,:v,:m,:mn,:no,:u)')
            ->execute([':n' => $no, ':r' => $d['supplier_ref'], ':s' => $supplierId, ':po' => $poId, ':d' => $d['invoice_date'], ':due' => $due, ':sub' => $sub, ':t' => $tax, ':tot' => $total, ':gv' => round2($grnValue), ':v' => $variance,
                ':m' => $match, ':mn' => $note, ':no' => $d['notes'] ?: null, ':u' => current_user()['id']]);
        $id = (int) $pdo->lastInsertId();
        foreach ($grns as $g) $pdo->prepare('UPDATE goods_receipts SET supplier_invoice_id = :i WHERE id = :g')->execute([':i' => $id, ':g' => $g['id']]);
        $lines = [['2100', $grns ? round2($grnValue) : $sub, 0], ['1300', $tax, 0], ['2000', 0, $total]];
        if ($variance > 0) $lines[] = ['5200', $variance, 0]; elseif ($variance < 0) $lines[] = ['5200', 0, -$variance];
        post_journal($pdo, $d['invoice_date'], "Supplier invoice {$d['supplier_ref']} Â· {$sup['name']}", 'supplier_invoice', $id, $no, $lines);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('supplier_bill_create', "$no ({$d['supplier_ref']}) Â· {$sup['name']} Â· " . format_money($total) . ' Â· ' . $match, 'supplier_invoice', $id);
    return $id;
}

function bill_refresh(PDO $pdo, int $id): void
{
    $s = $pdo->prepare('SELECT total, paid_amount, status FROM supplier_invoices WHERE id = :id'); $s->execute([':id' => $id]); $b = $s->fetch();
    if (!$b || $b['status'] === 'void') return;
    $out = round2((float) $b['total'] - (float) $b['paid_amount']);
    $pdo->prepare('UPDATE supplier_invoices SET status = :s WHERE id = :id')->execute([':s' => $out <= 0.004 ? 'paid' : ((float) $b['paid_amount'] > 0.004 ? 'partial' : 'unpaid'), ':id' => $id]);
}

/** Pay a supplier; $map = [bill_id => amount] or null for oldest-due-first. */
function supplier_payment_record(int $supplierId, float $amount, string $method, string $date, ?string $reference, ?string $notes, ?array $map = null): int
{
    $pdo = db();
    if ($amount <= 0) throw new RuntimeException('Payment amount must be greater than zero.');
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT name FROM suppliers WHERE id = :id'); $s->execute([':id' => $supplierId]); $name = $s->fetchColumn();
        if (!$name) throw new RuntimeException('Supplier not found.');
        $no = next_no('supplier_payment', 'SPAY');
        $pdo->prepare('INSERT INTO supplier_payments (pay_no, supplier_id, kind, amount, method, pay_date, reference, notes, created_by) VALUES (:n,:s,\'payment\',:a,:m,:d,:r,:no,:u)')
            ->execute([':n' => $no, ':s' => $supplierId, ':a' => $amount, ':m' => $method, ':d' => $date, ':r' => $reference ?: null, ':no' => $notes ?: null, ':u' => current_user()['id']]);
        $pid = (int) $pdo->lastInsertId();
        post_journal($pdo, $date, "Payment $no to $name", 'supplier_payment', $pid, $no, [['2000', $amount, 0], [method_account($method), 0, $amount]]);
        supplier_allocate($pdo, $pid, $map);
        if ($own) $pdo->commit();
    } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('supplier_payment', "$no Â· $name Â· " . format_money($amount) . ' Â· ' . method_label($method), 'supplier_payment', $pid);
    return $pid;
}

function supplier_allocate(PDO $pdo, int $paymentId, ?array $map = null): float
{
    $p = $pdo->prepare('SELECT * FROM supplier_payments WHERE id = :id'); $p->execute([':id' => $paymentId]); $pay = $p->fetch();
    $done = (float) $pdo->query('SELECT COALESCE(SUM(amount),0) FROM supplier_payment_allocations WHERE payment_id = ' . (int) $paymentId)->fetchColumn();
    $free = round2((float) $pay['amount'] - $done);
    if ($free <= 0.004) return 0.0;
    $open = $pdo->prepare("SELECT id, total - paid_amount AS due FROM supplier_invoices WHERE supplier_id = :s AND status IN ('unpaid','partial') ORDER BY due_date, id");
    $open->execute([':s' => $pay['supplier_id']]);
    $dueMap = [];
    foreach ($open->fetchAll() as $o) $dueMap[(int) $o['id']] = round2((float) $o['due']);
    $plan = [];
    if ($map === null) {
        foreach ($dueMap as $id => $due) { if ($free <= 0.004) break; $a = min($free, $due); if ($a > 0) { $plan[$id] = $a; $free = round2($free - $a); } }
    } else {
        foreach ($map as $id => $a) {
            $a = round2((float) $a); $id = (int) $id; if ($a <= 0) continue;
            if (!isset($dueMap[$id])) throw new RuntimeException('One of the selected invoices is not open for payment.');
            if ($a > $dueMap[$id] + 0.004) throw new RuntimeException('You allocated more than an invoice\'s outstanding balance.');
            if ($a > $free + 0.004) throw new RuntimeException('Allocations exceed the payment amount.');
            $plan[$id] = $a; $free = round2($free - $a);
        }
    }
    $tot = 0.0;
    foreach ($plan as $id => $a) {
        $pdo->prepare('INSERT INTO supplier_payment_allocations (payment_id, invoice_id, amount) VALUES (:p,:i,:a)')->execute([':p' => $paymentId, ':i' => $id, ':a' => $a]);
        $pdo->prepare('UPDATE supplier_invoices SET paid_amount = paid_amount + :a WHERE id = :id')->execute([':a' => $a, ':id' => $id]);
        bill_refresh($pdo, $id);
        $tot += $a;
    }
    return $tot;
}

/** What we owe a supplier: invoices less payments and return credits (negative = supplier owes us). */
function supplier_balance(int $supplierId): float
{
    $s = db()->prepare("SELECT (SELECT COALESCE(SUM(total),0) FROM supplier_invoices WHERE supplier_id = :a AND status <> 'void') - (SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE supplier_id = :b)");
    $s->execute([':a' => $supplierId, ':b' => $supplierId]);
    return (float) $s->fetchColumn();
}

// ---------------------------------------------------------------------
// Returns to supplier
// ---------------------------------------------------------------------

/** $lines: [ ['product_id','batch_id','serial_id','qty','unit_cost','bucket'] ]. Stock leaves, the supplier's payable is reduced. */
function purchase_return_create(int $supplierId, int $warehouseId, string $date, string $reason, array $lines): int
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT name FROM suppliers WHERE id = :id'); $s->execute([':id' => $supplierId]); $supName = $s->fetchColumn();
        if (!$supName) throw new RuntimeException('Supplier not found.');
        $no = next_no('purchase_return', 'PRN');
        $pdo->prepare('INSERT INTO purchase_returns (return_no, supplier_id, warehouse_id, return_date, reason, created_by) VALUES (:n,:s,:w,:d,:r,:u)')
            ->execute([':n' => $no, ':s' => $supplierId, ':w' => $warehouseId, ':d' => $date, ':r' => $reason ?: null, ':u' => current_user()['id'] ?? null]);
        $rid = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO purchase_return_items (return_id, product_id, batch_id, serial_id, qty, unit_cost, from_bucket) VALUES (:r,:p,:b,:s,:q,:c,:bk)');
        $total = 0.0;
        foreach ($lines as $l) {
            $q = (int) $l['qty']; if ($q <= 0) continue;
            $bucket = $l['bucket'] ?? 'on_hand'; $cost = (float) $l['unit_cost'];
            if (!empty($l['serial_id'])) {
                serial_transition($pdo, (int) $l['serial_id'], 'returned_to_supplier', [], 'return_supplier', ['purchase_return', $rid, $no], 'Returned to supplier', $supName . ' Â· ' . $reason);
            } else {
                stock_move($pdo, ['product_id' => (int) $l['product_id'], 'warehouse_id' => $warehouseId, 'batch_id' => (int) ($l['batch_id'] ?? 0), 'bucket' => $bucket, 'qty' => -$q, 'type' => 'return_supplier',
                    'ref_type' => 'purchase_return', 'ref_id' => $rid, 'ref_no' => $no, 'unit_cost' => $cost, 'note' => $supName]);
                if (!empty($l['batch_id'])) trace_event($pdo, 'batch', (int) $l['batch_id'], 'returned_supplier', 'Returned to supplier', number_format($q) . ' returned to ' . $supName . '.', 'purchase_return', $rid, $no);
            }
            $ins->execute([':r' => $rid, ':p' => $l['product_id'], ':b' => (int) ($l['batch_id'] ?? 0), ':s' => $l['serial_id'] ?? null, ':q' => $q, ':c' => $cost, ':bk' => $bucket]);
            $total += $q * $cost;
        }
        $total = round2($total);
        if ($total <= 0) throw new RuntimeException('Nothing to return.');
        $pdo->prepare('UPDATE purchase_returns SET total = :t WHERE id = :id')->execute([':t' => $total, ':id' => $rid]);
        post_journal($pdo, $date, "Return to supplier $no Â· $supName", 'purchase_return', $rid, $no, [['2000', $total, 0], ['1200', 0, $total]]);
        // The credit lands on the supplier account and is applied to open invoices (no cash moves).
        $pn = next_no('supplier_payment', 'SPAY');
        $pdo->prepare('INSERT INTO supplier_payments (pay_no, supplier_id, kind, amount, method, pay_date, reference, notes, created_by) VALUES (:n,:s,\'return_credit\',:a,\'credit\',:d,:r,:no,:u)')
            ->execute([':n' => $pn, ':s' => $supplierId, ':a' => $total, ':d' => $date, ':r' => $no, ':no' => 'Credit for ' . $no, ':u' => current_user()['id'] ?? null]);
        $payId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE purchase_returns SET payment_id = :p WHERE id = :id')->execute([':p' => $payId, ':id' => $rid]);
        supplier_allocate($pdo, $payId, null);
        if ($own) $pdo->commit();
    } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('purchase_return', "$no Â· $supName Â· " . format_money($total) . ($reason ? " Â· $reason" : ''), 'purchase_return', $rid);
    return $rid;
}

// ---------------------------------------------------------------------
// Statements & ageing
// ---------------------------------------------------------------------

function supplier_statement(int $supplierId, string $from, string $to): array
{
    $q = db()->prepare("SELECT invoice_date d, 'Invoice' t, CONCAT(bill_no, ' (', supplier_ref, ')') ref, 0 debit, total credit, id FROM supplier_invoices WHERE supplier_id = :a AND status <> 'void'
        UNION ALL SELECT pay_date, IF(kind = 'payment','Payment','Return credit'), pay_no, amount, 0, id FROM supplier_payments WHERE supplier_id = :b");
    $q->execute([':a' => $supplierId, ':b' => $supplierId]);
    $rows = $q->fetchAll();
    usort($rows, fn($a, $b) => [$a['d'], $a['id']] <=> [$b['d'], $b['id']]);
    $opening = 0.0; $ev = [];
    foreach ($rows as $r) {
        if ($r['d'] < $from) { $opening += (float) $r['credit'] - (float) $r['debit']; continue; }
        if ($r['d'] > $to) continue;
        $ev[] = $r;
    }
    $bal = $opening;
    foreach ($ev as &$e) { $bal += (float) $e['credit'] - (float) $e['debit']; $e['balance'] = $bal; }
    return ['opening' => $opening, 'rows' => $ev, 'closing' => $bal];
}

function ap_open_bills(?int $supplierId = null): array
{
    $w = "si.status IN ('unpaid','partial')"; $p = [];
    if ($supplierId) { $w .= ' AND si.supplier_id = :s'; $p[':s'] = $supplierId; }
    $s = db()->prepare("SELECT si.*, s.name AS supplier_name, (si.total - si.paid_amount) AS outstanding, DATEDIFF(CURDATE(), si.due_date) AS days_overdue FROM supplier_invoices si JOIN suppliers s ON s.id = si.supplier_id WHERE $w AND (si.total - si.paid_amount) > 0.004 ORDER BY si.due_date, si.id");
    $s->execute($p);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) $r['bucket'] = ageing_bucket((int) $r['days_overdue']);
    return $rows;
}
