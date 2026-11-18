<?php
/**
 * Inventory engine: multi-warehouse stock, batch / lot tracking (FEFO/FIFO),
 * serial-number tracking, traceability timelines, transfers, adjustments
 * and physical counts.
 *
 * Stock buckets per product / warehouse / batch:
 *   on_hand     saleable-condition goods physically in the warehouse
 *   quarantine  goods awaiting inspection (customer returns, rejected deliveries)
 *   damaged     damaged goods
 * Reserved stock is not stored: it is derived from confirmed sales orders.
 * Every change goes through stock_move() so the movement ledger is always complete.
 */

const SERIAL_IN_STOCK = "('available','reserved','returned','under_inspection','under_repair','damaged')";

// ---------------------------------------------------------------------
// Core ledger
// ---------------------------------------------------------------------

/**
 * Apply a signed change to one stock bucket and record the movement.
 * $o: product_id, warehouse_id, batch_id?, serial_id?, bucket?, qty, type, ref_type?, ref_id?, ref_no?, unit_cost?, note?
 * Returns the bucket's new balance. Must run inside the caller's transaction.
 */
function stock_move(PDO $pdo, array $o): int
{
    $bucket = $o['bucket'] ?? 'on_hand';
    if (!in_array($bucket, ['on_hand', 'quarantine', 'damaged'], true)) throw new InvalidArgumentException('Bad stock bucket.');
    $pid = (int) $o['product_id']; $wh = (int) $o['warehouse_id']; $batch = (int) ($o['batch_id'] ?? 0); $qty = (int) $o['qty'];
    if ($qty === 0) return 0;
    $pdo->prepare('INSERT IGNORE INTO stock (product_id, warehouse_id, batch_id) VALUES (:p,:w,:b)')->execute([':p' => $pid, ':w' => $wh, ':b' => $batch]);
    $s = $pdo->prepare("SELECT `$bucket` FROM stock WHERE product_id=:p AND warehouse_id=:w AND batch_id=:b FOR UPDATE");
    $s->execute([':p' => $pid, ':w' => $wh, ':b' => $batch]);
    $cur = (int) $s->fetchColumn();
    $new = $cur + $qty;
    if ($new < 0) {
        $nm = $pdo->prepare('SELECT p.name, w.name AS wn FROM products p, warehouses w WHERE p.id=:p AND w.id=:w');
        $nm->execute([':p' => $pid, ':w' => $wh]);
        $r = $nm->fetch();
        throw new RuntimeException('Not enough stock: ' . ($r['name'] ?? 'item') . ' has only ' . $cur . ' in ' . ($r['wn'] ?? 'the warehouse') . ' (' . str_replace('_', ' ', $bucket) . ').');
    }
    $pdo->prepare("UPDATE stock SET `$bucket` = :n WHERE product_id=:p AND warehouse_id=:w AND batch_id=:b")->execute([':n' => $new, ':p' => $pid, ':w' => $wh, ':b' => $batch]);
    $pdo->prepare('INSERT INTO stock_movements (product_id, warehouse_id, batch_id, serial_id, bucket, change_qty, balance_after, type, reference_type, reference_id, reference_no, unit_cost, note, created_by)
                   VALUES (:p,:w,:b,:s,:bk,:q,:bal,:t,:rt,:ri,:rn,:uc,:n,:u)')->execute([
        ':p' => $pid, ':w' => $wh, ':b' => $batch, ':s' => $o['serial_id'] ?? null, ':bk' => $bucket, ':q' => $qty, ':bal' => $new, ':t' => $o['type'],
        ':rt' => $o['ref_type'] ?? null, ':ri' => $o['ref_id'] ?? null, ':rn' => $o['ref_no'] ?? null, ':uc' => $o['unit_cost'] ?? 0, ':n' => isset($o['note']) ? mb_substr($o['note'], 0, 255) : null,
        ':u' => current_user()['id'] ?? null,
    ]);
    return $new;
}

function movement_types(): array
{
    return [
        'purchase' => ['Goods received', 'success'], 'sale_dispatch' => ['Dispatched to customer', 'orange'], 'delivery_return' => ['Failed delivery returned', 'info'],
        'delivery_rejected' => ['Rejected at delivery', 'warning'], 'sale_return' => ['Customer return', 'info'], 'return_restock' => ['Return restocked', 'success'],
        'return_dispose' => ['Return written off', 'error'], 'return_damaged' => ['Return marked damaged', 'warning'], 'return_supplier' => ['Returned to supplier', 'warning'],
        'transfer_out' => ['Transfer out', 'orange'], 'transfer_in' => ['Transfer in', 'info'], 'transfer_loss' => ['Transit shortage', 'error'],
        'damaged' => ['Marked damaged', 'warning'], 'write_off' => ['Write-off', 'error'], 'found' => ['Stock found', 'success'], 'correction' => ['Count correction', 'neutral'],
        'serial_status' => ['Serial status change', 'neutral'], 'recall_recovery' => ['Recalled stock recovered', 'info'], 'quarantine' => ['Moved to quarantine', 'warning'],
    ];
}

/** Weighted-average cost (untracked products) updated when new stock is received. Call BEFORE adding the stock. */
function update_avg_cost(PDO $pdo, int $productId, int $qtyIn, float $unitCost): void
{
    if ($qtyIn <= 0) return;
    $on = (int) $pdo->query('SELECT COALESCE(SUM(on_hand+quarantine+damaged),0) FROM stock WHERE product_id = ' . (int) $productId)->fetchColumn();
    $p = $pdo->prepare('SELECT avg_cost FROM products WHERE id = :id FOR UPDATE');
    $p->execute([':id' => $productId]);
    $avg = (float) $p->fetchColumn();
    $new = $on > 0 ? ($on * $avg + $qtyIn * $unitCost) / ($on + $qtyIn) : $unitCost;
    $pdo->prepare('UPDATE products SET avg_cost = :c WHERE id = :id')->execute([':c' => round($new, 4), ':id' => $productId]);
}

// ---------------------------------------------------------------------
// Availability
// ---------------------------------------------------------------------

/** Sales-order quantity still committed (reserved) for a product (optionally in one warehouse). */
function reserved_qty(int $productId, ?int $warehouseId = null): int
{
    $sql = "SELECT COALESCE(SUM(soi.qty - soi.qty_dispatched - soi.qty_cancelled),0) FROM sales_order_items soi JOIN sales_orders so ON so.id = soi.so_id
            WHERE soi.product_id = :p AND so.status IN ('confirmed','partially_fulfilled')" . ($warehouseId ? ' AND so.warehouse_id = :w' : '');
    $s = db()->prepare($sql);
    $s->execute($warehouseId ? [':p' => $productId, ':w' => $warehouseId] : [':p' => $productId]);
    return (int) $s->fetchColumn();
}

/** Full stock picture for a product, optionally in one warehouse. */
function stock_summary(int $productId, ?int $warehouseId = null): array
{
    $sql = "SELECT COALESCE(SUM(s.on_hand + s.quarantine + s.damaged),0) AS physical,
            COALESCE(SUM(CASE WHEN s.batch_id = 0 OR (b.status = 'active' AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())) THEN s.on_hand ELSE 0 END),0) AS saleable,
            COALESCE(SUM(CASE WHEN s.batch_id > 0 AND NOT (b.status = 'active' AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())) THEN s.on_hand ELSE 0 END),0) AS blocked,
            COALESCE(SUM(s.quarantine),0) AS quarantine, COALESCE(SUM(s.damaged),0) AS damaged
            FROM stock s LEFT JOIN batches b ON b.id = s.batch_id WHERE s.product_id = :p" . ($warehouseId ? ' AND s.warehouse_id = :w' : '');
    $st = db()->prepare($sql);
    $st->execute($warehouseId ? [':p' => $productId, ':w' => $warehouseId] : [':p' => $productId]);
    $r = $st->fetch();
    $r['reserved'] = reserved_qty($productId, $warehouseId);
    $r['available'] = (int) $r['saleable'] - $r['reserved'];
    return array_map('intval', $r);
}

/** Per-warehouse availability for a product: [ [warehouse_id, name, saleable, reserved, available], ... ] */
function stock_by_warehouse(int $productId): array
{
    $out = [];
    foreach (db()->query('SELECT id, name, code FROM warehouses WHERE is_active = 1 ORDER BY name')->fetchAll() as $w) {
        $s = stock_summary($productId, (int) $w['id']);
        $out[] = ['warehouse_id' => (int) $w['id'], 'name' => $w['name'], 'code' => $w['code']] + $s;
    }
    return $out;
}

function allocation_policy(array $product): string
{
    $policy = setting('allocation_policy', 'fefo');
    return ($policy === 'fefo' && (int) ($product['track_expiry'] ?? 0) === 1) ? 'fefo' : 'fifo';
}

/** Saleable batches in a warehouse, ordered by the allocation policy, with quantity not already picked on open deliveries. */
function saleable_batches(int $productId, int $warehouseId, ?int $excludeDeliveryId = null): array
{
    $p = db()->prepare('SELECT * FROM products WHERE id = :id');
    $p->execute([':id' => $productId]);
    $prod = $p->fetch() ?: [];
    $order = allocation_policy($prod) === 'fefo' ? 'b.expiry_date IS NULL, b.expiry_date, b.received_date, b.id' : 'b.received_date, b.id';
    $s = db()->prepare("SELECT b.*, s.on_hand,
        COALESCE((SELECT SUM(da.qty) FROM delivery_allocations da JOIN delivery_items di ON di.id = da.delivery_item_id JOIN deliveries d ON d.id = di.delivery_id
                  WHERE da.batch_id = b.id AND d.warehouse_id = :w1 AND d.status IN ('picked','packed') AND d.id <> :ex),0) AS picked
        FROM stock s JOIN batches b ON b.id = s.batch_id
        WHERE s.product_id = :p AND s.warehouse_id = :w2 AND s.batch_id > 0 AND s.on_hand > 0 AND b.status = 'active' AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
        ORDER BY $order");
    $s->execute([':w1' => $warehouseId, ':w2' => $warehouseId, ':p' => $productId, ':ex' => (int) $excludeDeliveryId]);
    $rows = [];
    foreach ($s->fetchAll() as $r) {
        $r['available'] = (int) $r['on_hand'] - (int) $r['picked'];
        if ($r['available'] > 0) $rows[] = $r;
    }
    return $rows;
}

/** Suggest which batches to pick: FEFO for expiring goods, FIFO otherwise. Returns [allocations, shortfall]. */
function suggest_batch_allocation(int $productId, int $warehouseId, int $qty, ?int $excludeDeliveryId = null): array
{
    $alloc = []; $left = $qty;
    foreach (saleable_batches($productId, $warehouseId, $excludeDeliveryId) as $b) {
        if ($left <= 0) break;
        $take = min($left, (int) $b['available']);
        $alloc[] = ['batch_id' => (int) $b['id'], 'batch_no' => $b['batch_no'], 'expiry_date' => $b['expiry_date'], 'qty' => $take];
        $left -= $take;
    }
    return [$alloc, $left];
}

/** Serial numbers available to sell in a warehouse (oldest first). */
function available_serials(int $productId, int $warehouseId, int $limit = 500, int $includeDelivery = 0): array
{
    $s = db()->prepare("SELECT id, serial_no, received_date, unit_cost, status FROM serial_numbers
        WHERE product_id = :p AND warehouse_id = :w AND (status = 'available' OR (status = 'reserved' AND delivery_id = :d)) ORDER BY received_date, id LIMIT " . (int) $limit);
    $s->execute([':p' => $productId, ':w' => $warehouseId, ':d' => $includeDelivery]);
    return $s->fetchAll();
}

/** Unit cost used for COGS: batch cost, serial cost or the product's weighted-average cost. */
function item_unit_cost(int $productId, int $batchId = 0, ?int $serialId = null): float
{
    $pdo = db();
    if ($serialId) { $s = $pdo->prepare('SELECT unit_cost FROM serial_numbers WHERE id = :id'); $s->execute([':id' => $serialId]); return (float) $s->fetchColumn(); }
    if ($batchId) { $s = $pdo->prepare('SELECT unit_cost FROM batches WHERE id = :id'); $s->execute([':id' => $batchId]); return (float) $s->fetchColumn(); }
    $s = $pdo->prepare('SELECT avg_cost FROM products WHERE id = :id'); $s->execute([':id' => $productId]);
    return (float) $s->fetchColumn();
}

// ---------------------------------------------------------------------
// Traceability events
// ---------------------------------------------------------------------

function trace_event(PDO $pdo, string $entity, int $entityId, string $eventType, string $title, ?string $detail = null, ?string $refType = null, ?int $refId = null, ?string $refNo = null): void
{
    $pdo->prepare('INSERT INTO trace_events (entity_type, entity_id, event_type, title, detail, ref_type, ref_id, ref_no, user_id) VALUES (:e,:i,:t,:ti,:d,:rt,:ri,:rn,:u)')
        ->execute([':e' => $entity, ':i' => $entityId, ':t' => $eventType, ':ti' => $title, ':d' => $detail ? mb_substr($detail, 0, 255) : null, ':rt' => $refType, ':ri' => $refId, ':rn' => $refNo, ':u' => current_user()['id'] ?? null]);
}

// ---------------------------------------------------------------------
// Batches
// ---------------------------------------------------------------------

/** Find the batch record for a product + batch number, or create it. Receiving into a recalled/quarantined batch is refused. */
function batch_receive(PDO $pdo, int $productId, string $batchNo, array $info, int $qty): int
{
    $batchNo = trim($batchNo);
    if ($batchNo === '') throw new RuntimeException('Enter the batch number.');
    $s = $pdo->prepare('SELECT * FROM batches WHERE product_id = :p AND batch_no = :b FOR UPDATE');
    $s->execute([':p' => $productId, ':b' => $batchNo]);
    $b = $s->fetch();
    $cost = (float) ($info['unit_cost'] ?? 0);
    if ($b) {
        if (in_array($b['status'], ['recalled', 'quarantined'], true)) throw new RuntimeException("Batch $batchNo is {$b['status']} and cannot receive more stock.");
        if (!empty($info['expiry_date']) && $b['expiry_date'] && $b['expiry_date'] !== $info['expiry_date']) {
            throw new RuntimeException("Batch $batchNo already exists with expiry " . fmt_date($b['expiry_date']) . '. A batch number must keep one expiry date.');
        }
        $tot = (int) $b['received_qty'] + $qty;
        $avg = $tot > 0 ? ((int) $b['received_qty'] * (float) $b['unit_cost'] + $qty * $cost) / $tot : $cost;
        $pdo->prepare('UPDATE batches SET received_qty = :q, unit_cost = :c WHERE id = :id')->execute([':q' => $tot, ':c' => round($avg, 4), ':id' => $b['id']]);
        return (int) $b['id'];
    }
    $pdo->prepare('INSERT INTO batches (product_id, batch_no, supplier_id, grn_id, received_date, mfg_date, expiry_date, received_qty, unit_cost, status)
                   VALUES (:p,:b,:s,:g,:rd,:m,:e,:q,:c,:st)')->execute([
        ':p' => $productId, ':b' => $batchNo, ':s' => $info['supplier_id'] ?? null, ':g' => $info['grn_id'] ?? null, ':rd' => $info['received_date'] ?? app_today(),
        ':m' => $info['mfg_date'] ?: null, ':e' => $info['expiry_date'] ?: null, ':q' => $qty, ':c' => $cost,
        ':st' => (!empty($info['expiry_date']) && $info['expiry_date'] < app_today()) ? 'expired' : 'active',
    ]);
    return (int) $pdo->lastInsertId();
}

/** Flip batches that have passed their expiry date to 'expired' (blocks dispatch). Cheap; runs on every page load. */
function expire_batches(): void
{
    try {
        $pdo = db();
        $ids = $pdo->query("SELECT id, batch_no FROM batches WHERE status = 'active' AND expiry_date IS NOT NULL AND expiry_date < CURDATE()")->fetchAll();
        foreach ($ids as $b) {
            $pdo->prepare("UPDATE batches SET status = 'expired', status_note = 'Passed expiry date' WHERE id = :id AND status = 'active'")->execute([':id' => $b['id']]);
            trace_event($pdo, 'batch', (int) $b['id'], 'expired', 'Batch expired', 'Blocked from normal sale and dispatch.');
        }
    } catch (Throwable $e) { error_log('expire_batches: ' . $e->getMessage()); }
}

function batch_set_status(PDO $pdo, int $batchId, string $status, string $note, string $eventType, string $eventTitle): void
{
    $pdo->prepare('UPDATE batches SET status = :s, status_note = :n WHERE id = :id')->execute([':s' => $status, ':n' => $note ?: null, ':id' => $batchId]);
    trace_event($pdo, 'batch', $batchId, $eventType, $eventTitle, $note ?: null);
}

/** Put a batch on hold (quarantine) or release it. Open pick lists that include it are released so it can't be dispatched. */
function batch_hold(int $batchId, bool $hold, string $note): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $b = $pdo->prepare('SELECT * FROM batches WHERE id = :id FOR UPDATE'); $b->execute([':id' => $batchId]); $bt = $b->fetch();
        if (!$bt) throw new RuntimeException('Batch not found.');
        if ($hold) {
            if ($bt['status'] !== 'active') throw new RuntimeException('Only active batches can be quarantined.');
            batch_set_status($pdo, $batchId, 'quarantined', $note, 'quarantined', 'Batch quarantined');
            batch_release_open_picks($pdo, $batchId);
        } else {
            if ($bt['status'] !== 'quarantined') throw new RuntimeException('Only quarantined batches can be released. Recalled batches stay blocked.');
            $status = ($bt['expiry_date'] && $bt['expiry_date'] < app_today()) ? 'expired' : 'active';
            batch_set_status($pdo, $batchId, $status, $note, 'released', 'Batch released from quarantine');
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity($hold ? 'batch_quarantine' : 'batch_release', "Batch {$bt['batch_no']} " . ($hold ? 'quarantined' : 'released') . ($note ? ": $note" : ''), 'batch', $batchId);
}

/** Deliveries that were picked/packed but not yet dispatched go back to the pick list when a batch becomes blocked. */
function batch_release_open_picks(PDO $pdo, int $batchId): void
{
    $d = $pdo->prepare("SELECT DISTINCT d.id FROM deliveries d JOIN delivery_items di ON di.delivery_id = d.id JOIN delivery_allocations da ON da.delivery_item_id = di.id WHERE da.batch_id = :b AND d.status IN ('picked','packed')");
    $d->execute([':b' => $batchId]);
    foreach ($d->fetchAll() as $r) {
        delivery_release_picks($pdo, (int) $r['id']);
        $pdo->prepare("UPDATE deliveries SET status = 'pending_pick', picked_by = NULL, picked_at = NULL, packed_by = NULL, packed_at = NULL WHERE id = :id")->execute([':id' => $r['id']]);
    }
}

/** Start a recall: block the batch, find every customer that received it. Returns the recall id. */
function recall_start(int $batchId, string $reason): int
{
    $pdo = db();
    if (trim($reason) === '') throw new RuntimeException('Enter the reason for the recall.');
    $pdo->beginTransaction();
    try {
        $b = $pdo->prepare('SELECT b.*, p.name FROM batches b JOIN products p ON p.id = b.product_id WHERE b.id = :id FOR UPDATE'); $b->execute([':id' => $batchId]); $bt = $b->fetch();
        if (!$bt) throw new RuntimeException('Batch not found.');
        $open = $pdo->prepare("SELECT COUNT(*) FROM batch_recalls WHERE batch_id = :b AND status = 'open'"); $open->execute([':b' => $batchId]);
        if ((int) $open->fetchColumn()) throw new RuntimeException('This batch already has an open recall.');
        $no = next_no('recall', 'RCL');
        $pdo->prepare('INSERT INTO batch_recalls (recall_no, batch_id, reason, initiated_by) VALUES (:n,:b,:r,:u)')->execute([':n' => $no, ':b' => $batchId, ':r' => $reason, ':u' => current_user()['id']]);
        $rid = (int) $pdo->lastInsertId();
        batch_set_status($pdo, $batchId, 'recalled', $reason, 'recall', 'Recall notification');
        trace_event($pdo, 'batch', $batchId, 'quarantine', 'Stock quarantine', 'Remaining stock blocked from normal dispatch.', 'batch_recall', $rid, $no);
        batch_release_open_picks($pdo, $batchId);
        // Quantity each customer still holds = dispatched (accepted) minus anything they already returned for this batch.
        $pdo->prepare("INSERT INTO recall_customers (recall_id, customer_id, qty_delivered)
            SELECT :r, x.customer_id, x.q - COALESCE(r.q, 0) FROM
              (SELECT d.customer_id, SUM(da.qty - da.rejected_qty) AS q FROM delivery_allocations da JOIN delivery_items di ON di.id = da.delivery_item_id JOIN deliveries d ON d.id = di.delivery_id
               WHERE da.batch_id = :b AND d.status IN ('dispatched','delivered') GROUP BY d.customer_id) x
              LEFT JOIN (SELECT sr.customer_id, SUM(sri.qty) AS q FROM sales_return_items sri JOIN sales_returns sr ON sr.id = sri.return_id WHERE sri.batch_id = :b2 AND sr.status IN ('received','closed') GROUP BY sr.customer_id) r ON r.customer_id = x.customer_id
            WHERE x.q - COALESCE(r.q, 0) > 0")->execute([':r' => $rid, ':b' => $batchId, ':b2' => $batchId]);
        trace_event($pdo, 'batch', $batchId, 'recall_customers', 'Customer recall', 'Affected customers identified and contacted.', 'batch_recall', $rid, $no);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('batch_recall', "$no · batch {$bt['batch_no']} of {$bt['name']} recalled: $reason", 'batch_recall', $rid);
    return $rid;
}

/** Customer returns recalled stock: it comes back into quarantine and the recovered quantity is tracked. */
function recall_record_recovery(int $recallId, int $customerId, int $qty, int $warehouseId, string $note): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = $pdo->prepare('SELECT rc.*, br.batch_id, br.recall_no, b.product_id, b.unit_cost, b.batch_no FROM recall_customers rc JOIN batch_recalls br ON br.id = rc.recall_id JOIN batches b ON b.id = br.batch_id WHERE rc.recall_id = :r AND rc.customer_id = :c FOR UPDATE');
        $r->execute([':r' => $recallId, ':c' => $customerId]); $rc = $r->fetch();
        if (!$rc) throw new RuntimeException('Customer not part of this recall.');
        $left = (int) $rc['qty_delivered'] - (int) $rc['qty_recovered'];
        if ($qty <= 0 || $qty > $left) throw new RuntimeException('Enter a quantity between 1 and ' . number_format($left) . '.');
        stock_move($pdo, ['product_id' => (int) $rc['product_id'], 'warehouse_id' => $warehouseId, 'batch_id' => (int) $rc['batch_id'], 'bucket' => 'quarantine', 'qty' => $qty, 'type' => 'recall_recovery',
            'ref_type' => 'batch_recall', 'ref_id' => $recallId, 'ref_no' => $rc['recall_no'], 'unit_cost' => (float) $rc['unit_cost'], 'note' => $note]);
        post_journal($pdo, app_today(), 'Recalled stock recovered · ' . $rc['recall_no'], 'batch_recall', $recallId, $rc['recall_no'], [['1200', round2($qty * (float) $rc['unit_cost']), 0], ['5000', 0, round2($qty * (float) $rc['unit_cost'])]]);
        $new = (int) $rc['qty_recovered'] + $qty;
        $pdo->prepare('UPDATE recall_customers SET qty_recovered = :q, status = :s, note = :n, contacted_at = COALESCE(contacted_at, NOW()) WHERE id = :id')
            ->execute([':q' => $new, ':s' => $new >= (int) $rc['qty_delivered'] ? 'recovered' : 'partial', ':n' => $note ?: $rc['note'], ':id' => $rc['id']]);
        trace_event($pdo, 'batch', (int) $rc['batch_id'], 'recovered', 'Recalled stock recovered', number_format($qty) . ' recovered from a customer into quarantine.', 'batch_recall', $recallId, $rc['recall_no']);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('recall_recovery', "{$rc['recall_no']} · recovered " . number_format($qty) . " of batch {$rc['batch_no']}", 'batch_recall', $recallId);
}

function recall_mark_contacted(int $recallId, int $customerId, string $note): void
{
    db()->prepare("UPDATE recall_customers SET status = IF(status = 'pending','contacted',status), contacted_at = NOW(), note = :n WHERE recall_id = :r AND customer_id = :c")->execute([':n' => $note ?: null, ':r' => $recallId, ':c' => $customerId]);
    log_activity('recall_contact', "Customer #$customerId contacted for recall #$recallId", 'batch_recall', $recallId);
}

function recall_close(int $recallId): void
{
    $u = db()->prepare("UPDATE batch_recalls SET status = 'closed', closed_at = NOW() WHERE id = :id AND status = 'open'");
    $u->execute([':id' => $recallId]);
    if (!$u->rowCount()) throw new RuntimeException('This recall is already closed.');
    log_activity('recall_close', "Recall #$recallId closed", 'batch_recall', $recallId);
}

// ---------------------------------------------------------------------
// Serial numbers
// ---------------------------------------------------------------------

function serial_bucket(string $status): ?string
{
    return match ($status) {
        'available', 'reserved' => 'on_hand',
        'returned', 'under_inspection', 'under_repair' => 'quarantine',
        'damaged' => 'damaged',
        default => null,   // dispatched, sold, returned_to_supplier, written_off: not in our warehouse
    };
}

/** Register a brand-new serial number into stock (goods receiving). */
function serial_register(PDO $pdo, int $productId, string $serialNo, int $warehouseId, array $info): int
{
    $serialNo = trim($serialNo);
    if ($serialNo === '') throw new RuntimeException('Serial number cannot be empty.');
    $dup = $pdo->prepare('SELECT id FROM serial_numbers WHERE product_id = :p AND serial_no = :s');
    $dup->execute([':p' => $productId, ':s' => $serialNo]);
    if ($dup->fetchColumn()) throw new RuntimeException("Serial number $serialNo is already registered.");
    $pm = $pdo->prepare('SELECT warranty_months FROM products WHERE id = :id');
    $pm->execute([':id' => $productId]);
    $pdo->prepare('INSERT INTO serial_numbers (product_id, serial_no, warehouse_id, status, supplier_id, po_id, grn_id, received_date, unit_cost, warranty_months)
                   VALUES (:p,:s,:w,\'available\',:su,:po,:g,:rd,:c,:wm)')->execute([
        ':p' => $productId, ':s' => $serialNo, ':w' => $warehouseId, ':su' => $info['supplier_id'] ?? null, ':po' => $info['po_id'] ?? null,
        ':g' => $info['grn_id'] ?? null, ':rd' => $info['received_date'] ?? app_today(), ':c' => $info['unit_cost'] ?? 0, ':wm' => (int) $pm->fetchColumn(),
    ]);
    $id = (int) $pdo->lastInsertId();
    stock_move($pdo, ['product_id' => $productId, 'warehouse_id' => $warehouseId, 'serial_id' => $id, 'qty' => 1, 'type' => 'purchase', 'ref_type' => 'goods_receipt',
        'ref_id' => $info['grn_id'] ?? null, 'ref_no' => $info['grn_no'] ?? null, 'unit_cost' => $info['unit_cost'] ?? 0]);
    trace_event($pdo, 'serial', $id, 'received', 'Product received', 'Received from ' . ($info['supplier_name'] ?? 'supplier') . (!empty($info['po_no']) ? ' under ' . $info['po_no'] : ''), 'goods_receipt', $info['grn_id'] ?? null, $info['grn_no'] ?? null);
    trace_event($pdo, 'serial', $id, 'stored', 'Warehouse storage', 'Stored in ' . ($info['warehouse_name'] ?? 'warehouse') . '.');
    return $id;
}

/**
 * Move a serial to a new status, keeping the stock buckets and the timeline in sync.
 * $set = extra columns (sales_order_id, delivery_id, invoice_id, customer_id, sold_date, warranty_expiry).
 */
function serial_transition(PDO $pdo, int $serialId, string $newStatus, array $set = [], string $moveType = 'serial_status', ?array $ref = null, ?string $eventTitle = null, ?string $eventDetail = null, ?int $newWarehouse = null): array
{
    $s = $pdo->prepare('SELECT * FROM serial_numbers WHERE id = :id FOR UPDATE');
    $s->execute([':id' => $serialId]);
    $old = $s->fetch();
    if (!$old) throw new RuntimeException('Serial number not found.');
    $oldB = serial_bucket($old['status']); $newB = serial_bucket($newStatus);
    $oldWh = (int) $old['warehouse_id']; $newWh = $newWarehouse ?? $oldWh;
    $mv = ['product_id' => (int) $old['product_id'], 'serial_id' => $serialId, 'type' => $moveType, 'ref_type' => $ref[0] ?? null, 'ref_id' => $ref[1] ?? null, 'ref_no' => $ref[2] ?? null,
        'unit_cost' => (float) $old['unit_cost'], 'note' => $old['serial_no']];
    if ($oldB !== $newB || ($oldB !== null && $oldWh !== $newWh)) {
        if ($oldB !== null) stock_move($pdo, $mv + ['warehouse_id' => $oldWh, 'bucket' => $oldB, 'qty' => -1]);
        if ($newB !== null) stock_move($pdo, $mv + ['warehouse_id' => $newWh, 'bucket' => $newB, 'qty' => 1]);
    }
    $cols = ['status = :st', 'warehouse_id = :wh']; $p = [':st' => $newStatus, ':wh' => $newWh ?: null, ':id' => $serialId];
    foreach (['sales_order_id', 'delivery_id', 'invoice_id', 'customer_id', 'sold_date', 'warranty_expiry'] as $c) {
        if (array_key_exists($c, $set)) { $cols[] = "$c = :$c"; $p[":$c"] = $set[$c]; }
    }
    $pdo->prepare('UPDATE serial_numbers SET ' . implode(', ', $cols) . ' WHERE id = :id')->execute($p);
    if ($eventTitle) trace_event($pdo, 'serial', $serialId, $newStatus, $eventTitle, $eventDetail, $ref[0] ?? null, $ref[1] ?? null, $ref[2] ?? null);
    return $old;
}

/** Warranty position of a serial row (needs sold_date / warranty_expiry / warranty_months). */
function warranty_state(array $s): array
{
    if (!in_array($s['status'], ['sold', 'returned', 'under_inspection', 'under_repair', 'damaged'], true) || empty($s['sold_date'])) return ['state' => 'not_sold', 'days' => 0, 'label' => 'Not sold yet'];
    if (empty($s['warranty_expiry'])) return ['state' => 'none', 'days' => 0, 'label' => 'No warranty'];
    $days = (int) floor((strtotime($s['warranty_expiry']) - strtotime(app_today())) / 86400);
    return $days >= 0 ? ['state' => 'active', 'days' => $days, 'label' => 'In warranty · ' . $days . ' day(s) left'] : ['state' => 'expired', 'days' => $days, 'label' => 'Warranty expired ' . abs($days) . ' day(s) ago'];
}

function warranty_claim_create(int $serialId, string $issue): int
{
    $pdo = db();
    if (trim($issue) === '') throw new RuntimeException('Describe the problem reported by the customer.');
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM serial_numbers WHERE id = :id FOR UPDATE'); $s->execute([':id' => $serialId]); $sn = $s->fetch();
        if (!$sn || !$sn['customer_id']) throw new RuntimeException('This unit has not been sold to a customer, so it cannot have a warranty claim.');
        $w = warranty_state($sn);
        $no = next_no('warranty_claim', 'WC');
        $pdo->prepare('INSERT INTO warranty_claims (claim_no, serial_id, customer_id, invoice_id, issue, in_warranty, created_by) VALUES (:n,:s,:c,:i,:is,:w,:u)')
            ->execute([':n' => $no, ':s' => $serialId, ':c' => $sn['customer_id'], ':i' => $sn['invoice_id'], ':is' => $issue, ':w' => $w['state'] === 'active' ? 1 : 0, ':u' => current_user()['id'] ?? null]);
        $id = (int) $pdo->lastInsertId();
        trace_event($pdo, 'serial', $serialId, 'warranty', 'Warranty claim ' . $no, $issue . ' · ' . $w['label'], 'warranty_claim', $id, $no);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('warranty_claim', "$no for serial {$sn['serial_no']}: $issue", 'warranty_claim', $id);
    return $id;
}

function warranty_claim_update(int $claimId, string $status, string $resolution, ?int $replacementSerialId = null): void
{
    $pdo = db();
    if (!in_array($status, ['in_repair', 'replaced', 'resolved', 'rejected'], true)) throw new RuntimeException('Choose a valid outcome.');
    $pdo->beginTransaction();
    try {
        $c = $pdo->prepare('SELECT wc.*, sn.serial_no, sn.product_id, sn.warranty_expiry FROM warranty_claims wc JOIN serial_numbers sn ON sn.id = wc.serial_id WHERE wc.id = :id FOR UPDATE'); $c->execute([':id' => $claimId]); $cl = $c->fetch();
        if (!$cl || in_array($cl['status'], ['replaced', 'resolved', 'rejected'], true)) throw new RuntimeException('This claim is already closed.');
        if ($status === 'replaced') {
            if (!$replacementSerialId) throw new RuntimeException('Choose the replacement unit.');
            $r = $pdo->prepare('SELECT * FROM serial_numbers WHERE id = :id'); $r->execute([':id' => $replacementSerialId]); $rep = $r->fetch();
            if (!$rep || $rep['status'] !== 'available' || (int) $rep['product_id'] !== (int) $cl['product_id']) throw new RuntimeException('The replacement must be an available unit of the same product.');
            serial_transition($pdo, $replacementSerialId, 'sold', ['customer_id' => $cl['customer_id'], 'sold_date' => app_today(), 'warranty_expiry' => $cl['warranty_expiry'], 'invoice_id' => $cl['invoice_id']], 'serial_status',
                ['warranty_claim', $claimId, $cl['claim_no']], 'Issued as warranty replacement', 'Replaces ' . $cl['serial_no'] . ' under ' . $cl['claim_no'] . '.');
        }
        $pdo->prepare('UPDATE warranty_claims SET status = :s, resolution = :r, replacement_serial_id = :rs, closed_at = :c WHERE id = :id')
            ->execute([':s' => $status, ':r' => $resolution ?: null, ':rs' => $replacementSerialId, ':c' => $status === 'in_repair' ? null : date('Y-m-d H:i:s'), ':id' => $claimId]);
        trace_event($pdo, 'serial', (int) $cl['serial_id'], 'warranty', 'Claim ' . $cl['claim_no'] . ': ' . str_replace('_', ' ', $status), $resolution ?: null, 'warranty_claim', $claimId, $cl['claim_no']);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('warranty_update', "{$cl['claim_no']} → " . str_replace('_', ' ', $status) . ($resolution ? ": $resolution" : ''), 'warranty_claim', $claimId);
}

function serial_status_options(): array
{
    return ['available' => 'Available', 'reserved' => 'Reserved', 'dispatched' => 'Dispatched', 'sold' => 'Sold', 'returned' => 'Returned', 'under_inspection' => 'Under inspection',
        'under_repair' => 'Under repair', 'damaged' => 'Damaged', 'returned_to_supplier' => 'Returned to supplier', 'written_off' => 'Written off'];
}

// ---------------------------------------------------------------------
// Valuation
// ---------------------------------------------------------------------

/**
 * Inventory valuation at cost: untracked = qty Ã— average cost, batch = qty Ã— batch cost, serial = sum of serial costs.
 * Returns rows [product_id, code, name, tracking, warehouse_id, warehouse, qty, value] plus a grand total.
 */
function inventory_valuation(?int $warehouseId = null, ?int $productId = null): array
{
    $pdo = db();
    $w = $warehouseId ? ' AND s.warehouse_id = ' . (int) $warehouseId : '';
    $pf = $productId ? ' AND p.id = ' . (int) $productId : '';
    $rows = $pdo->query("SELECT p.id AS product_id, p.code, p.name, p.tracking, p.base_unit, s.warehouse_id, wh.name AS warehouse, wh.code AS wh_code,
            SUM(s.on_hand) AS on_hand, SUM(s.quarantine) AS quarantine, SUM(s.damaged) AS damaged,
            SUM((s.on_hand + s.quarantine + s.damaged) * CASE WHEN s.batch_id > 0 THEN b.unit_cost ELSE p.avg_cost END) AS value
        FROM stock s JOIN products p ON p.id = s.product_id JOIN warehouses wh ON wh.id = s.warehouse_id LEFT JOIN batches b ON b.id = s.batch_id
        WHERE p.tracking <> 'serial' $w $pf GROUP BY p.id, s.warehouse_id")->fetchAll();
    $sw = $warehouseId ? ' AND sn.warehouse_id = ' . (int) $warehouseId : '';
    $spf = $productId ? ' AND p.id = ' . (int) $productId : '';
    $ser = $pdo->query("SELECT p.id AS product_id, p.code, p.name, p.tracking, p.base_unit, sn.warehouse_id, wh.name AS warehouse, wh.code AS wh_code,
            SUM(sn.status IN ('available','reserved')) AS on_hand, SUM(sn.status IN ('returned','under_inspection','under_repair')) AS quarantine, SUM(sn.status = 'damaged') AS damaged,
            SUM(sn.unit_cost) AS value
        FROM serial_numbers sn JOIN products p ON p.id = sn.product_id JOIN warehouses wh ON wh.id = sn.warehouse_id
        WHERE sn.status IN " . SERIAL_IN_STOCK . " $sw $spf GROUP BY p.id, sn.warehouse_id")->fetchAll();
    $all = array_merge($rows, $ser);
    $total = 0.0; $units = 0;
    foreach ($all as &$r) {
        $r['qty'] = (int) $r['on_hand'] + (int) $r['quarantine'] + (int) $r['damaged'];
        $r['value'] = (float) $r['value'];
        $total += $r['value']; $units += $r['qty'];
    }
    unset($r);
    usort($all, fn($a, $b) => strcmp($a['name'], $b['name']) ?: $a['warehouse_id'] <=> $b['warehouse_id']);
    // Goods on a truck between warehouses still belong to us.
    $transit = 0.0; $transitUnits = 0;
    if (!$warehouseId && !$productId) {
        $t = $pdo->query("SELECT COALESCE(SUM(sti.qty_sent * sti.unit_cost),0) v, COALESCE(SUM(sti.qty_sent),0) q FROM stock_transfer_items sti JOIN stock_transfers st ON st.id = sti.transfer_id WHERE st.status = 'in_transit'")->fetch();
        $s2 = $pdo->query("SELECT COALESCE(SUM(sn.unit_cost),0) v, COUNT(*) q FROM stock_transfer_serials sts JOIN stock_transfers st ON st.id = sts.transfer_id JOIN serial_numbers sn ON sn.id = sts.serial_id WHERE st.status = 'in_transit'")->fetch();
        $transit = (float) $t['v'] + (float) $s2['v']; $transitUnits = (int) $t['q'] + (int) $s2['q'];
    }
    return ['rows' => $all, 'total' => $total + $transit, 'units' => $units + $transitUnits, 'in_transit' => $transit, 'in_transit_units' => $transitUnits, 'in_warehouses' => $total];
}

function inventory_value_total(): float
{
    return inventory_valuation()['total'];
}

// ---------------------------------------------------------------------
// Stock transfers between warehouses
// ---------------------------------------------------------------------

/**
 * Create a transfer from lines [ ['product_id','batch_id','qty'] ] and serials [product_id => [serial ids]].
 * Goods leave the source on dispatch ("send") and enter the destination when received.
 */
function transfer_create(int $fromWh, int $toWh, string $date, ?string $notes, array $lines, array $serialIds, bool $sendNow): int
{
    $pdo = db();
    if ($fromWh === $toWh) throw new RuntimeException('Choose two different warehouses.');
    if (!$lines && !$serialIds) throw new RuntimeException('Add at least one item to transfer.');
    $pdo->beginTransaction();
    try {
        $no = next_no('transfer', 'TRF');
        $pdo->prepare('INSERT INTO stock_transfers (tr_no, from_wh, to_wh, status, transfer_date, notes, created_by) VALUES (:n,:f,:t,\'draft\',:d,:no,:u)')
            ->execute([':n' => $no, ':f' => $fromWh, ':t' => $toWh, ':d' => $date, ':no' => $notes ?: null, ':u' => current_user()['id']]);
        $id = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO stock_transfer_items (transfer_id, product_id, batch_id, qty_sent, unit_cost) VALUES (:t,:p,:b,:q,:c)');
        foreach ($lines as $l) {
            $qty = (int) $l['qty']; if ($qty <= 0) continue;
            $ins->execute([':t' => $id, ':p' => (int) $l['product_id'], ':b' => (int) ($l['batch_id'] ?? 0), ':q' => $qty, ':c' => item_unit_cost((int) $l['product_id'], (int) ($l['batch_id'] ?? 0))]);
        }
        $insS = $pdo->prepare('INSERT INTO stock_transfer_serials (transfer_id, serial_id) VALUES (:t,:s)');
        foreach ($serialIds as $pid => $ids) {
            foreach ($ids as $sid) {
                $c = $pdo->prepare("SELECT status, warehouse_id FROM serial_numbers WHERE id = :id AND product_id = :p");
                $c->execute([':id' => (int) $sid, ':p' => (int) $pid]);
                $r = $c->fetch();
                if (!$r || $r['status'] !== 'available' || (int) $r['warehouse_id'] !== $fromWh) throw new RuntimeException('A selected serial number is not available in the source warehouse.');
                $insS->execute([':t' => $id, ':s' => (int) $sid]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('transfer_create', "Created $no", 'stock_transfer', $id);
    if ($sendNow) transfer_send($id);
    return $id;
}

function transfer_send(int $id): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $t = $pdo->prepare('SELECT * FROM stock_transfers WHERE id = :id FOR UPDATE'); $t->execute([':id' => $id]); $tr = $t->fetch();
        if (!$tr || $tr['status'] !== 'draft') throw new RuntimeException('This transfer has already been sent.');
        $items = $pdo->prepare('SELECT * FROM stock_transfer_items WHERE transfer_id = :id'); $items->execute([':id' => $id]);
        foreach ($items->fetchAll() as $it) {
            if ((int) $it['batch_id']) {
                $b = $pdo->prepare('SELECT status, expiry_date, batch_no FROM batches WHERE id = :id'); $b->execute([':id' => $it['batch_id']]); $bb = $b->fetch();
                if ($bb['status'] !== 'active') throw new RuntimeException("Batch {$bb['batch_no']} is {$bb['status']} and cannot be transferred.");
            }
            stock_move($pdo, ['product_id' => (int) $it['product_id'], 'warehouse_id' => (int) $tr['from_wh'], 'batch_id' => (int) $it['batch_id'], 'qty' => -(int) $it['qty_sent'],
                'type' => 'transfer_out', 'ref_type' => 'stock_transfer', 'ref_id' => $id, 'ref_no' => $tr['tr_no'], 'unit_cost' => (float) $it['unit_cost']]);
        }
        $ser = $pdo->prepare('SELECT sts.serial_id, sn.product_id, sn.unit_cost, sn.serial_no FROM stock_transfer_serials sts JOIN serial_numbers sn ON sn.id = sts.serial_id WHERE sts.transfer_id = :id');
        $ser->execute([':id' => $id]);
        foreach ($ser->fetchAll() as $s) {
            stock_move($pdo, ['product_id' => (int) $s['product_id'], 'warehouse_id' => (int) $tr['from_wh'], 'serial_id' => (int) $s['serial_id'], 'qty' => -1, 'type' => 'transfer_out',
                'ref_type' => 'stock_transfer', 'ref_id' => $id, 'ref_no' => $tr['tr_no'], 'unit_cost' => (float) $s['unit_cost'], 'note' => $s['serial_no']]);
            $pdo->prepare('UPDATE serial_numbers SET warehouse_id = NULL WHERE id = :id')->execute([':id' => $s['serial_id']]);
            trace_event($pdo, 'serial', (int) $s['serial_id'], 'transfer', 'Transfer dispatched', 'In transit to another warehouse.', 'stock_transfer', $id, $tr['tr_no']);
        }
        $pdo->prepare('UPDATE stock_transfers SET status = \'in_transit\', sent_by = :u, sent_at = NOW() WHERE id = :id')->execute([':u' => current_user()['id'], ':id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('transfer_send', 'Dispatched transfer #' . $id, 'stock_transfer', $id);
}

/** Receive a transfer. $received = [item_id => qty]; serials arrive as a whole unless listed in $missingSerials. */
function transfer_receive(int $id, array $received, array $missingSerials = []): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $t = $pdo->prepare('SELECT * FROM stock_transfers WHERE id = :id FOR UPDATE'); $t->execute([':id' => $id]); $tr = $t->fetch();
        if (!$tr || $tr['status'] !== 'in_transit') throw new RuntimeException('This transfer is not in transit.');
        $items = $pdo->prepare('SELECT * FROM stock_transfer_items WHERE transfer_id = :id'); $items->execute([':id' => $id]);
        $lossValue = 0.0;
        foreach ($items->fetchAll() as $it) {
            $q = max(0, min((int) $it['qty_sent'], (int) ($received[$it['id']] ?? $it['qty_sent'])));
            if ($q > 0) stock_move($pdo, ['product_id' => (int) $it['product_id'], 'warehouse_id' => (int) $tr['to_wh'], 'batch_id' => (int) $it['batch_id'], 'qty' => $q, 'type' => 'transfer_in',
                'ref_type' => 'stock_transfer', 'ref_id' => $id, 'ref_no' => $tr['tr_no'], 'unit_cost' => (float) $it['unit_cost']]);
            $short = (int) $it['qty_sent'] - $q;
            if ($short > 0) {
                $lossValue += $short * (float) $it['unit_cost'];
                $pdo->prepare('INSERT INTO stock_movements (product_id, warehouse_id, batch_id, bucket, change_qty, balance_after, type, reference_type, reference_id, reference_no, unit_cost, note, created_by)
                               VALUES (:p,:w,:b,\'on_hand\',0,0,\'transfer_loss\',\'stock_transfer\',:id,:no,:c,:n,:u)')
                    ->execute([':p' => $it['product_id'], ':w' => $tr['from_wh'], ':b' => $it['batch_id'], ':id' => $id, ':no' => $tr['tr_no'], ':c' => $it['unit_cost'], ':n' => "Short by $short in transit", ':u' => current_user()['id']]);
            }
            $pdo->prepare('UPDATE stock_transfer_items SET qty_received = :q WHERE id = :id')->execute([':q' => $q, ':id' => $it['id']]);
        }
        $ser = $pdo->prepare('SELECT sts.serial_id, sn.product_id, sn.unit_cost, sn.serial_no FROM stock_transfer_serials sts JOIN serial_numbers sn ON sn.id = sts.serial_id WHERE sts.transfer_id = :id');
        $ser->execute([':id' => $id]);
        foreach ($ser->fetchAll() as $s) {
            if (in_array((int) $s['serial_id'], array_map('intval', $missingSerials), true)) {
                $lossValue += (float) $s['unit_cost'];
                $pdo->prepare("UPDATE serial_numbers SET status = 'written_off' WHERE id = :id")->execute([':id' => $s['serial_id']]);
                trace_event($pdo, 'serial', (int) $s['serial_id'], 'written_off', 'Lost in transit', 'Not received at destination.', 'stock_transfer', $id, $tr['tr_no']);
                continue;
            }
            stock_move($pdo, ['product_id' => (int) $s['product_id'], 'warehouse_id' => (int) $tr['to_wh'], 'serial_id' => (int) $s['serial_id'], 'qty' => 1, 'type' => 'transfer_in',
                'ref_type' => 'stock_transfer', 'ref_id' => $id, 'ref_no' => $tr['tr_no'], 'unit_cost' => (float) $s['unit_cost'], 'note' => $s['serial_no']]);
            $pdo->prepare('UPDATE serial_numbers SET warehouse_id = :w WHERE id = :id')->execute([':w' => $tr['to_wh'], ':id' => $s['serial_id']]);
            $pdo->prepare('UPDATE stock_transfer_serials SET received = 1 WHERE transfer_id = :t AND serial_id = :s')->execute([':t' => $id, ':s' => $s['serial_id']]);
            trace_event($pdo, 'serial', (int) $s['serial_id'], 'transfer', 'Transfer received', 'Stored in the destination warehouse.', 'stock_transfer', $id, $tr['tr_no']);
        }
        if ($lossValue > 0) post_journal($pdo, app_today(), 'Transit shortage on ' . $tr['tr_no'], 'stock_transfer', $id, $tr['tr_no'], [['5100', $lossValue, 0], ['1200', 0, $lossValue]]);
        $pdo->prepare('UPDATE stock_transfers SET status = \'received\', received_by = :u, received_at = NOW() WHERE id = :id')->execute([':u' => current_user()['id'], ':id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('transfer_receive', 'Received transfer #' . $id, 'stock_transfer', $id);
}

// ---------------------------------------------------------------------
// Stock adjustments
// ---------------------------------------------------------------------

/** reason => [label, description, direction(+1/-1/0 either), icon, value effect] */
function adjustment_reasons(): array
{
    return [
        'damaged'           => ['Mark as damaged', 'Move saleable stock into the damaged bucket (not for sale).', -1, 'package-x'],
        'expired'           => ['Expired stock', 'Remove expired stock from the warehouse (written off).', -1, 'calendar-x'],
        'lost'              => ['Lost / stolen', 'Items missing or stolen (written off).', -1, 'search-x'],
        'write_off_damaged' => ['Dispose damaged stock', 'Remove damaged items from the warehouse (written off).', -1, 'trash-2'],
        'restore_damaged'   => ['Repaired / restored', 'Move damaged items back to saleable stock.', 1, 'wrench'],
        'found'             => ['Found / extra stock', 'Add stock that was found or not recorded (batch items need a batch).', 1, 'package-plus'],
        'correction'        => ['Count correction', 'Correct a quantity after a physical check (+ or âˆ’).', 0, 'scale'],
    ];
}

function adjustment_reason_label(string $r): string
{
    return adjustment_reasons()[$r][0] ?? ucwords(str_replace('_', ' ', $r));
}

/**
 * Create an adjustment. $lines = [ ['product_id','batch_id','serial_id','qty','note'] ].
 * Auto-applies when $autoApprove (warehouse manager); otherwise waits for approval.
 */
function adjustment_create(int $warehouseId, string $reason, array $lines, ?string $note, bool $autoApprove, string $source = 'manual', ?int $countId = null): int
{
    $pdo = db();
    $reasons = adjustment_reasons();
    if (!isset($reasons[$reason])) throw new RuntimeException('Choose a valid reason.');
    $lines = array_values(array_filter($lines, fn($l) => (int) $l['qty'] !== 0));
    if (!$lines) throw new RuntimeException('Add at least one item with a quantity.');
    $dir = $reasons[$reason][2];
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $no = next_no('adjustment', 'ADJ');
        $pdo->prepare('INSERT INTO stock_adjustments (adj_no, warehouse_id, reason, status, source, count_id, note, requested_by) VALUES (:n,:w,:r,\'pending\',:s,:c,:note,:u)')
            ->execute([':n' => $no, ':w' => $warehouseId, ':r' => $reason, ':s' => $source, ':c' => $countId, ':note' => $note ?: null, ':u' => current_user()['id'] ?? null]);
        $id = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO stock_adjustment_items (adjustment_id, product_id, batch_id, serial_id, qty_change, unit_cost, note) VALUES (:a,:p,:b,:s,:q,:c,:n)');
        foreach ($lines as $l) {
            $q = (int) $l['qty'];
            if ($dir !== 0) $q = abs($q) * $dir;
            $ins->execute([':a' => $id, ':p' => (int) $l['product_id'], ':b' => (int) ($l['batch_id'] ?? 0), ':s' => $l['serial_id'] ?? null, ':q' => $q,
                ':c' => item_unit_cost((int) $l['product_id'], (int) ($l['batch_id'] ?? 0), $l['serial_id'] ?? null), ':n' => $l['note'] ?? null]);
        }
        if ($autoApprove) adjustment_apply($pdo, $id, (int) (current_user()['id'] ?? 0));
        if ($own) $pdo->commit();
    } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('stock_adjustment_create', "$no Â· " . adjustment_reason_label($reason) . ' Â· ' . count($lines) . ' line(s)' . ($autoApprove ? ' Â· applied' : ' Â· awaiting approval'), 'stock_adjustment', $id);
    return $id;
}

function adjustment_apply(PDO $pdo, int $id, int $approverId): void
{
    $a = $pdo->prepare('SELECT * FROM stock_adjustments WHERE id = :id FOR UPDATE'); $a->execute([':id' => $id]); $adj = $a->fetch();
    if (!$adj || $adj['status'] !== 'pending') throw new RuntimeException('This adjustment is no longer pending.');
    $items = $pdo->prepare('SELECT sai.*, p.name, p.tracking FROM stock_adjustment_items sai JOIN products p ON p.id = sai.product_id WHERE sai.adjustment_id = :id');
    $items->execute([':id' => $id]);
    $wh = (int) $adj['warehouse_id']; $value = 0.0; $glLoss = 0.0;
    $base = ['warehouse_id' => $wh, 'ref_type' => 'stock_adjustment', 'ref_id' => $id, 'ref_no' => $adj['adj_no'], 'note' => adjustment_reason_label($adj['reason']) . ($adj['note'] ? ' Â· ' . $adj['note'] : '')];
    foreach ($items->fetchAll() as $it) {
        $q = (int) $it['qty_change']; $abs = abs($q); $pid = (int) $it['product_id']; $b = (int) $it['batch_id']; $cost = (float) $it['unit_cost'];
        $mv = $base + ['product_id' => $pid, 'batch_id' => $b, 'unit_cost' => $cost];
        $isSerial = $it['tracking'] === 'serial';
        if ($isSerial) {
            if (!$it['serial_id']) throw new RuntimeException($it['name'] . ' is serial-tracked: choose the serial number.');
            $ref = ['stock_adjustment', $id, $adj['adj_no']];
            switch ($adj['reason']) {
                case 'damaged': serial_transition($pdo, (int) $it['serial_id'], 'damaged', [], 'damaged', $ref, 'Marked damaged', $adj['note']); break;
                case 'restore_damaged': serial_transition($pdo, (int) $it['serial_id'], 'available', [], 'serial_status', $ref, 'Repaired / restored', $adj['note']); break;
                case 'write_off_damaged': case 'lost': case 'expired':
                    serial_transition($pdo, (int) $it['serial_id'], 'written_off', [], 'write_off', $ref, $adj['reason'] === 'lost' ? 'Lost / stolen' : 'Written off', $adj['note']);
                    $glLoss += $cost; $value -= $cost; break;
                default: throw new RuntimeException('Serial items support damaged, restore, lost and write-off adjustments only.');
            }
            continue;
        }
        if ($b) {
            $bs = $pdo->prepare('SELECT batch_no FROM batches WHERE id = :id'); $bs->execute([':id' => $b]);
            if (!$bs->fetchColumn()) throw new RuntimeException('Selected batch no longer exists.');
        }
        switch ($adj['reason']) {
            case 'damaged':
                stock_move($pdo, $mv + ['qty' => -$abs, 'type' => 'damaged']);
                stock_move($pdo, $mv + ['qty' => $abs, 'type' => 'damaged', 'bucket' => 'damaged']);
                break;
            case 'restore_damaged':
                stock_move($pdo, $mv + ['qty' => -$abs, 'type' => 'correction', 'bucket' => 'damaged']);
                stock_move($pdo, $mv + ['qty' => $abs, 'type' => 'correction']);
                break;
            case 'write_off_damaged':
                stock_move($pdo, $mv + ['qty' => -$abs, 'type' => 'write_off', 'bucket' => 'damaged']);
                $glLoss += $abs * $cost; $value -= $abs * $cost;
                break;
            case 'expired': case 'lost':
                stock_move($pdo, $mv + ['qty' => -$abs, 'type' => 'write_off']);
                $glLoss += $abs * $cost; $value -= $abs * $cost;
                break;
            case 'found':
                if ($it['tracking'] === 'batch' && !$b) throw new RuntimeException($it['name'] . ' is batch-tracked: choose the batch.');
                stock_move($pdo, $mv + ['qty' => $abs, 'type' => 'found']);
                $glLoss -= $abs * $cost; $value += $abs * $cost;
                break;
            default: // correction (signed)
                stock_move($pdo, $mv + ['qty' => $q, 'type' => 'correction']);
                $glLoss -= $q * $cost; $value += $q * $cost;
        }
    }
    if (abs($glLoss) > 0.004) {
        // Loss: Dr Inventory Adjustments, Cr Inventory.  Gain: reverse.
        post_journal($pdo, app_today(), $adj['adj_no'] . ' Â· ' . adjustment_reason_label($adj['reason']), 'stock_adjustment', $id, $adj['adj_no'],
            $glLoss > 0 ? [['5100', $glLoss, 0], ['1200', 0, $glLoss]] : [['1200', -$glLoss, 0], ['5100', 0, -$glLoss]]);
    }
    $pdo->prepare("UPDATE stock_adjustments SET status = 'approved', approved_by = :u, approved_at = NOW(), value_change = :v WHERE id = :id")->execute([':u' => $approverId, ':v' => round2($value), ':id' => $id]);
}

// ---------------------------------------------------------------------
// Physical stock counts (untracked + batch products; serials are verified by scanning)
// ---------------------------------------------------------------------

function count_create(int $warehouseId, ?string $note): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $no = next_no('count', 'CNT');
        $pdo->prepare('INSERT INTO stock_counts (count_no, warehouse_id, note, created_by) VALUES (:n,:w,:no,:u)')->execute([':n' => $no, ':w' => $warehouseId, ':no' => $note ?: null, ':u' => current_user()['id']]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO stock_count_items (count_id, product_id, batch_id, system_qty)
            SELECT :c, s.product_id, s.batch_id, s.on_hand FROM stock s JOIN products p ON p.id = s.product_id WHERE s.warehouse_id = :w AND p.tracking <> 'serial' AND p.is_active = 1 AND (s.on_hand > 0 OR s.batch_id = 0)")
            ->execute([':c' => $id, ':w' => $warehouseId]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    log_activity('stock_count_create', "Started $no", 'stock_count', $id);
    return $id;
}

function count_approve(int $countId): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $c = $pdo->prepare('SELECT * FROM stock_counts WHERE id = :id FOR UPDATE'); $c->execute([':id' => $countId]); $cnt = $c->fetch();
        if (!$cnt || $cnt['status'] !== 'submitted') throw new RuntimeException('This count is not awaiting approval.');
        $it = $pdo->prepare('SELECT * FROM stock_count_items WHERE count_id = :id AND counted_qty IS NOT NULL AND counted_qty <> system_qty'); $it->execute([':id' => $countId]);
        $lines = [];
        foreach ($it->fetchAll() as $r) {
            // Re-read live qty so movements made during counting are respected.
            $cur = $pdo->prepare('SELECT COALESCE(on_hand,0) FROM stock WHERE product_id=:p AND warehouse_id=:w AND batch_id=:b');
            $cur->execute([':p' => $r['product_id'], ':w' => $cnt['warehouse_id'], ':b' => $r['batch_id']]);
            $live = (int) $cur->fetchColumn();
            $diff = (int) $r['counted_qty'] - $live;
            if ($diff !== 0) $lines[] = ['product_id' => $r['product_id'], 'batch_id' => $r['batch_id'], 'qty' => $diff, 'note' => 'Count ' . $cnt['count_no']];
        }
        $adjId = 0;
        if ($lines) $adjId = adjustment_create((int) $cnt['warehouse_id'], 'correction', $lines, 'Variance from ' . $cnt['count_no'], true, 'count', $countId);
        $pdo->prepare("UPDATE stock_counts SET status = 'approved', approved_by = :u, approved_at = NOW() WHERE id = :id")->execute([':u' => current_user()['id'], ':id' => $countId]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('stock_count_approve', 'Approved ' . $cnt['count_no'] . ($adjId ? ' and applied variances' : ' (no variances)'), 'stock_count', $countId);
    return $adjId;
}
