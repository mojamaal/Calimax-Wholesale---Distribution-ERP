<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/print.php';
require_permission('purchase.view');
$pdo = db();
$type = input($_GET, 'type'); $id = input_int($_GET, 'id');

if ($type === 'po') {
    $s = $pdo->prepare('SELECT po.*, s.name AS supplier, s.address, s.phone, s.contact_person, s.payment_terms_days, w.name AS wh, w.address AS wh_addr FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id JOIN warehouses w ON w.id = po.warehouse_id WHERE po.id = :id');
    $s->execute([':id' => $id]); $po = $s->fetch(); if (!$po) { http_response_code(404); exit('Not found'); }
    $items = po_lines_with_balance($id);
    doc_start('PO', $po['po_no'], 'Purchase Order', ['Date' => fmt_date($po['order_date']), 'Expected' => fmt_date($po['expected_date'])], '/purchasing/order_view.php?id=' . $id);
    ?><div class="doc-meta"><div class="box"><h4>Supplier</h4><strong><?= e($po['supplier']) ?></strong><br><?= e($po['address'] ?? '') ?><br><?= e($po['contact_person'] ?? '') ?> <?= e($po['phone'] ?? '') ?></div><div class="box"><h4>Deliver to</h4><strong><?= e($po['wh']) ?></strong><br><?= e($po['wh_addr'] ?? '') ?><br>Payment terms: <strong><?= (int) $po['payment_terms_days'] ?> days</strong></div></div>
    <table class="doc-table"><thead><tr><th>Description</th><th class="r">Quantity</th><th class="r">Unit cost</th><th class="r">Total</th></tr></thead><tbody><?php foreach ($items as $l): ?><tr><td><strong><?= e($l['name']) ?></strong> <span style="color:#8A93A1"><?= e($l['code']) ?></span><?= $l['tracking'] === 'batch' ? '<div class="doc-serials">Please supply batch number' . ($l['track_expiry'] ? ', manufacturing and expiry dates' : '') . ' with each delivery.</div>' : '' ?><?= $l['tracking'] === 'serial' ? '<div class="doc-serials">Please supply a serial-number list with the delivery.</div>' : '' ?></td><td class="r"><?= $l['pack_factor'] > 1 && $l['qty'] % $l['pack_factor'] === 0 ? number_format($l['qty'] / $l['pack_factor']) . ' ' . e($l['pack_name']) . ' (' . number_format($l['qty']) . ')' : number_format($l['qty']) . ' ' . e($l['base_unit']) ?></td><td class="r"><?= number_format($l['unit_cost'] * $l['pack_factor'], 2) ?></td><td class="r"><?= number_format($l['line_total'], 2) ?></td></tr><?php endforeach; ?></tbody></table>
    <div class="doc-totals"><div><span>Subtotal</span><span><?= format_money($po['subtotal']) ?></span></div><div><span><?= e(setting('tax_name', 'VAT')) ?></span><span><?= format_money($po['tax_total']) ?></span></div><div class="grand"><span>Total</span><span><?= format_money($po['total']) ?></span></div></div>
    <div class="doc-sign"><div>Prepared by</div><div>Approved by</div><div>Supplier acknowledgement</div></div><?php doc_end($po['notes'] ? e($po['notes']) : 'Please confirm receipt of this order and the delivery date.');
} elseif ($type === 'grn') {
    $s = $pdo->prepare('SELECT g.*, s.name AS supplier, w.name AS wh, po.po_no FROM goods_receipts g JOIN suppliers s ON s.id = g.supplier_id JOIN warehouses w ON w.id = g.warehouse_id LEFT JOIN purchase_orders po ON po.id = g.po_id WHERE g.id = :id'); $s->execute([':id' => $id]); $g = $s->fetch(); if (!$g) { http_response_code(404); exit('Not found'); }
    $items = $pdo->prepare('SELECT gi.*, p.name, p.code, p.base_unit, b.batch_no, b.expiry_date FROM grn_items gi JOIN products p ON p.id = gi.product_id LEFT JOIN batches b ON b.id = gi.batch_id WHERE gi.grn_id = :g'); $items->execute([':g' => $id]); $items = $items->fetchAll();
    doc_start('GRN', $g['grn_no'], 'Goods Received Note', ['Date' => fmt_date($g['received_date']), 'PO' => $g['po_no'] ?? '—'], '/purchasing/grn_view.php?id=' . $id);
    ?><div class="doc-meta"><div class="box"><h4>Supplier</h4><strong><?= e($g['supplier']) ?></strong><br>Delivery ref: <?= e($g['supplier_ref'] ?? '—') ?></div><div class="box"><h4>Received at</h4><strong><?= e($g['wh']) ?></strong></div></div>
    <table class="doc-table"><thead><tr><th>Description</th><th>Batch</th><th class="r">Received</th><th class="r">Rejected</th></tr></thead><tbody><?php foreach ($items as $i): ?><tr><td><strong><?= e($i['name']) ?></strong> <span style="color:#8A93A1"><?= e($i['code']) ?></span></td><td><?= e($i['batch_no'] ?? '—') ?><?= $i['expiry_date'] ? '<div style="font-size:11px;color:#8A93A1">exp ' . e(fmt_date($i['expiry_date'])) . '</div>' : '' ?></td><td class="r"><?= number_format($i['qty_received']) ?> <?= e($i['base_unit']) ?></td><td class="r"><?= $i['qty_rejected'] ? number_format($i['qty_rejected']) . '<div style="font-size:11px;color:#8A93A1">' . e($i['reject_reason']) . '</div>' : '—' ?></td></tr><?php endforeach; ?></tbody></table>
    <div class="doc-sign"><div>Received by (warehouse)</div><div>Checked by</div><div>Driver / supplier</div></div><?php doc_end($g['notes'] ? e($g['notes']) : '');
} elseif ($type === 'statement') {
    $s = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id'); $s->execute([':id' => $id]); $sup = $s->fetch(); if (!$sup) { http_response_code(404); exit('Not found'); }
    $from = input_date($_GET, 'from', date('Y-m-d', strtotime('-90 days'))); $to = input_date($_GET, 'to', date('Y-m-d'));
    $st = supplier_statement($id, $from, $to);
    doc_start('Statement', $sup['code'], 'Supplier Statement', ['Period' => fmt_date($from) . ' – ' . fmt_date($to)], '/purchasing/supplier_view.php?id=' . $id);
    ?><div class="doc-meta"><div class="box"><h4>Supplier</h4><strong><?= e($sup['name']) ?></strong><br><?= e($sup['address'] ?? '') ?></div><div class="box"><h4>Terms</h4><?= (int) $sup['payment_terms_days'] ?> days</div></div>
    <table class="doc-table"><thead><tr><th>Date</th><th>Type</th><th>Reference</th><th class="r">Payments</th><th class="r">Invoiced</th><th class="r">We owe</th></tr></thead><tbody><tr><td colspan="5"><em>Opening balance</em></td><td class="r"><?= number_format($st['opening'], 2) ?></td></tr>
    <?php foreach ($st['rows'] as $r): ?><tr><td><?= e(fmt_date($r['d'])) ?></td><td><?= e($r['t']) ?></td><td><?= e($r['ref']) ?></td><td class="r"><?= (float) $r['debit'] ? number_format($r['debit'], 2) : '' ?></td><td class="r"><?= (float) $r['credit'] ? number_format($r['credit'], 2) : '' ?></td><td class="r"><?= number_format($r['balance'], 2) ?></td></tr><?php endforeach; ?>
    <tr><td colspan="5"><strong>Closing balance</strong></td><td class="r"><strong><?= number_format($st['closing'], 2) ?></strong></td></tr></tbody></table><?php doc_end();
} else { http_response_code(404); echo 'Unknown document'; }
