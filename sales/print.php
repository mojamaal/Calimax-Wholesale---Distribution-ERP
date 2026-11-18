<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/print.php';
require_login();
$pdo = db();
$type = input($_GET, 'type'); $id = input_int($_GET, 'id');
$tax = setting('tax_name', 'VAT');

if ($type === 'invoice') {
    require_permission('invoices.view');
    $s = $pdo->prepare('SELECT si.*, c.business_name, c.address, c.phone, c.tax_no, c.contact_person, so.so_no, so.customer_po_ref, d.dn_no FROM sales_invoices si JOIN customers c ON c.id = si.customer_id LEFT JOIN sales_orders so ON so.id = si.so_id LEFT JOIN deliveries d ON d.id = si.delivery_id WHERE si.id = :id');
    $s->execute([':id' => $id]); $inv = $s->fetch(); if (!$inv) { http_response_code(404); exit('Not found'); }
    $items = $pdo->prepare('SELECT sii.*, p.name, p.code, p.base_unit FROM sales_invoice_items sii JOIN products p ON p.id = sii.product_id WHERE sii.invoice_id = :i ORDER BY sii.id'); $items->execute([':i' => $id]); $items = $items->fetchAll();
    doc_start('Invoice', $inv['inv_no'], strtoupper($tax) . ' Invoice', ['Date' => fmt_date($inv['invoice_date']), 'Due' => fmt_date($inv['due_date'])], '/sales/invoice_view.php?id=' . $id);
    ?>
    <div class="doc-meta"><div class="box"><h4>Bill to</h4><strong><?= e($inv['business_name']) ?></strong><br><?= e($inv['address'] ?? '') ?><br><?= e($inv['phone'] ?? '') ?><?= $inv['tax_no'] ? '<br>' . e($tax) . ' no: ' . e($inv['tax_no']) : '' ?></div>
      <div class="box"><h4>Reference</h4>Order: <strong><?= e($inv['so_no'] ?? '—') ?></strong><?= $inv['customer_po_ref'] ? ' · Customer PO: ' . e($inv['customer_po_ref']) : '' ?><br>Delivery note: <strong><?= e($inv['dn_no'] ?? '—') ?></strong><br>Terms: <strong><?= $inv['payment_type'] === 'cash' ? 'Cash' : 'Credit' ?></strong></div></div>
    <table class="doc-table"><thead><tr><th>Description</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Disc.</th><th class="r">Net</th><th class="r"><?= e($tax) ?></th><th class="r">Total</th></tr></thead><tbody>
    <?php foreach ($items as $it):
        $ser = []; $bat = [];
        if ($it['delivery_item_id']) { $t = $pdo->prepare('SELECT sn.serial_no, b.batch_no, b.expiry_date, da.qty - da.rejected_qty AS q FROM delivery_allocations da LEFT JOIN serial_numbers sn ON sn.id = da.serial_id LEFT JOIN batches b ON b.id = da.batch_id WHERE da.delivery_item_id = :i AND da.rejected_qty < da.qty'); $t->execute([':i' => $it['delivery_item_id']]);
            foreach ($t->fetchAll() as $x) { if ($x['serial_no']) $ser[] = $x['serial_no']; elseif ($x['batch_no']) $bat[] = $x['batch_no'] . ' × ' . number_format($x['q']) . ($x['expiry_date'] ? ' (exp ' . fmt_date($x['expiry_date']) . ')' : ''); } } ?>
      <tr><td><strong><?= e($it['name']) ?></strong> <span style="color:#8A93A1"><?= e($it['code']) ?></span><?= $ser ? '<div class="doc-serials">S/N: ' . e(implode(', ', $ser)) . '</div>' : '' ?><?= $bat ? '<div class="doc-serials">Batch: ' . e(implode('; ', $bat)) . '</div>' : '' ?></td>
        <td class="r"><?= $it['pack_factor'] > 1 && $it['qty'] % $it['pack_factor'] === 0 ? number_format($it['qty'] / $it['pack_factor']) . ' ' . e($it['pack_name']) : number_format($it['qty']) . ' ' . e($it['base_unit']) ?></td><td class="r"><?= number_format($it['unit_price'] * $it['pack_factor'], 2) ?></td><td class="r"><?= (float) $it['discount_pct'] > 0 ? rtrim(rtrim(number_format($it['discount_pct'], 2), '0'), '.') . '%' : '' ?></td><td class="r"><?= number_format($it['line_net'], 2) ?></td><td class="r"><?= number_format($it['tax_amount'], 2) ?></td><td class="r"><?= number_format($it['line_total'], 2) ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <div class="doc-totals"><div><span>Subtotal</span><span><?= format_money($inv['subtotal']) ?></span></div><div><span><?= e($tax) ?></span><span><?= format_money($inv['tax_total']) ?></span></div><div class="grand"><span>Total</span><span><?= format_money($inv['total']) ?></span></div>
      <?php if ((float) $inv['paid_amount'] > 0): ?><div><span>Paid</span><span><?= format_money($inv['paid_amount']) ?></span></div><?php endif; ?><?php if ((float) $inv['credited_amount'] > 0): ?><div><span>Credited</span><span><?= format_money($inv['credited_amount']) ?></span></div><?php endif; ?>
      <div style="font-weight:700;"><span>Balance due</span><span><?= format_money($inv['total'] - $inv['paid_amount'] - $inv['credited_amount']) ?></span></div></div>
    <?php doc_end($inv['notes'] ? e($inv['notes']) : 'Thank you for your business. Goods once sold are returnable only with prior authorization.');

} elseif ($type === 'delivery') {
    require_permission('delivery.view');
    $s = $pdo->prepare('SELECT d.*, so.so_no, so.customer_po_ref, c.business_name, c.address, c.phone, c.contact_person, w.name AS wh, dr.name AS driver, v.reg_no FROM deliveries d JOIN sales_orders so ON so.id = d.so_id JOIN customers c ON c.id = d.customer_id JOIN warehouses w ON w.id = d.warehouse_id LEFT JOIN drivers dr ON dr.id = d.driver_id LEFT JOIN vehicles v ON v.id = d.vehicle_id WHERE d.id = :id');
    $s->execute([':id' => $id]); $d = $s->fetch(); if (!$d) { http_response_code(404); exit('Not found'); }
    $items = $pdo->prepare('SELECT di.*, p.name, p.code, p.base_unit, soi.pack_name, soi.pack_factor FROM delivery_items di JOIN products p ON p.id = di.product_id JOIN sales_order_items soi ON soi.id = di.so_item_id WHERE di.delivery_id = :d ORDER BY di.id'); $items->execute([':d' => $id]); $items = $items->fetchAll();
    doc_start('Delivery note', $d['dn_no'], 'Delivery Note', ['Order' => $d['so_no'], 'Date' => fmt_date($d['dispatched_at'] ?: $d['planned_date'])], '/sales/delivery_view.php?id=' . $id);
    ?>
    <div class="doc-meta"><div class="box"><h4>Deliver to</h4><strong><?= e($d['business_name']) ?></strong><br><?= e($d['address'] ?? '') ?><br><?= e($d['contact_person'] ?? '') ?> <?= e($d['phone'] ?? '') ?></div>
      <div class="box"><h4>Dispatch</h4>From: <strong><?= e($d['wh']) ?></strong><br>Driver: <strong><?= e($d['driver'] ?? '—') ?></strong> · Vehicle: <strong><?= e($d['reg_no'] ?? '—') ?></strong><?= $d['customer_po_ref'] ? '<br>Customer PO: ' . e($d['customer_po_ref']) : '' ?></div></div>
    <table class="doc-table"><thead><tr><th>Description</th><th class="r">Quantity</th><?php if ($d['status'] === 'delivered'): ?><th class="r">Accepted</th><th class="r">Rejected</th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($items as $it): $t = $pdo->prepare('SELECT sn.serial_no, b.batch_no, b.expiry_date, da.qty FROM delivery_allocations da LEFT JOIN serial_numbers sn ON sn.id = da.serial_id LEFT JOIN batches b ON b.id = da.batch_id WHERE da.delivery_item_id = :i'); $t->execute([':i' => $it['id']]); $al = $t->fetchAll();
        $ser = array_filter(array_column($al, 'serial_no')); $bat = array_map(fn($x) => $x['batch_no'] . ' × ' . number_format($x['qty']) . ($x['expiry_date'] ? ' (exp ' . fmt_date($x['expiry_date']) . ')' : ''), array_filter($al, fn($x) => $x['batch_no'])); $q = (int) ($it['qty_picked'] ?: $it['qty_planned']); ?>
      <tr><td><strong><?= e($it['name']) ?></strong> <span style="color:#8A93A1"><?= e($it['code']) ?></span><?= $ser ? '<div class="doc-serials">S/N: ' . e(implode(', ', $ser)) . '</div>' : '' ?><?= $bat ? '<div class="doc-serials">Batch: ' . e(implode('; ', $bat)) . '</div>' : '' ?></td>
        <td class="r"><?= $it['pack_factor'] > 1 && $q % $it['pack_factor'] === 0 ? number_format($q / $it['pack_factor']) . ' ' . e($it['pack_name']) . ' (' . number_format($q) . ')' : number_format($q) . ' ' . e($it['base_unit']) ?></td>
        <?php if ($d['status'] === 'delivered'): ?><td class="r"><?= number_format($it['qty_accepted']) ?></td><td class="r"><?= $it['qty_rejected'] ? number_format($it['qty_rejected']) . '<div style="font-size:11px;color:#8A93A1">' . e($it['reject_reason']) . '</div>' : '—' ?></td><?php endif; ?></tr>
    <?php endforeach; ?></tbody></table>
    <p style="font-size:12px;color:#5B6472;">This delivery note is not an invoice. Please check the goods on receipt and note any shortage or damage before signing.</p>
    <div class="doc-sign"><div>Prepared by (warehouse)</div><div>Driver</div><div>Received by (customer) — name &amp; signature</div></div>
    <?php doc_end();

} elseif ($type === 'receipt') {
    require_permission('invoices.view');
    $s = $pdo->prepare('SELECT cp.*, c.business_name, c.address FROM customer_payments cp JOIN customers c ON c.id = cp.customer_id WHERE cp.id = :id'); $s->execute([':id' => $id]); $p = $s->fetch(); if (!$p) { http_response_code(404); exit('Not found'); }
    $al = $pdo->prepare('SELECT a.amount, si.inv_no, si.total FROM customer_payment_allocations a JOIN sales_invoices si ON si.id = a.invoice_id WHERE a.payment_id = :p'); $al->execute([':p' => $id]); $al = $al->fetchAll();
    doc_start('Receipt', $p['receipt_no'], 'Payment Receipt', ['Date' => fmt_date($p['pay_date'])], '/sales/payments.php?highlight=' . $id);
    ?>
    <div class="doc-meta"><div class="box"><h4>Received from</h4><strong><?= e($p['business_name']) ?></strong><br><?= e($p['address'] ?? '') ?></div><div class="box"><h4>Payment</h4>Method: <strong><?= e(method_label($p['method'])) ?></strong><?= $p['reference'] ? '<br>Reference: <strong>' . e($p['reference']) . '</strong>' : '' ?></div></div>
    <table class="doc-table"><thead><tr><th>Applied to invoice</th><th class="r">Invoice total</th><th class="r">Amount applied</th></tr></thead><tbody><?php foreach ($al as $a): ?><tr><td><?= e($a['inv_no']) ?></td><td class="r"><?= number_format($a['total'], 2) ?></td><td class="r"><?= number_format($a['amount'], 2) ?></td></tr><?php endforeach; ?>
      <?php if ((float) $p['amount'] - (float) $p['allocated'] > 0.004): ?><tr><td>Advance on account</td><td></td><td class="r"><?= number_format($p['amount'] - $p['allocated'], 2) ?></td></tr><?php endif; ?></tbody></table>
    <div class="doc-totals"><div class="grand"><span>Amount received</span><span><?= format_money($p['amount']) ?></span></div></div>
    <div class="doc-sign"><div>Received by</div><div></div><div>Customer signature</div></div>
    <?php doc_end('Thank you for your payment.');

} elseif ($type === 'credit_note') {
    require_permission('invoices.view');
    $s = $pdo->prepare('SELECT cn.*, c.business_name, c.address, si.inv_no FROM credit_notes cn JOIN customers c ON c.id = cn.customer_id JOIN sales_invoices si ON si.id = cn.invoice_id WHERE cn.id = :id'); $s->execute([':id' => $id]); $cn = $s->fetch(); if (!$cn) { http_response_code(404); exit('Not found'); }
    doc_start('Credit note', $cn['cn_no'], 'Credit Note', ['Date' => fmt_date($cn['cn_date']), 'Against' => $cn['inv_no']], '/sales/credit_notes.php?highlight=' . $id);
    ?>
    <div class="doc-meta"><div class="box"><h4>Credit to</h4><strong><?= e($cn['business_name']) ?></strong><br><?= e($cn['address'] ?? '') ?></div><div class="box"><h4>Reason</h4><?= e($cn['reason'] ?? '—') ?></div></div>
    <div class="doc-totals"><div><span>Credit (excl. tax)</span><span><?= format_money($cn['subtotal']) ?></span></div><div><span><?= e($tax) ?></span><span><?= format_money($cn['tax_total']) ?></span></div><div class="grand"><span>Total credit</span><span><?= format_money($cn['total']) ?></span></div></div>
    <?php doc_end('This credit note reduces the amount owed on invoice ' . e($cn['inv_no']) . '.');

} elseif ($type === 'statement') {
    require_permission('invoices.view');
    $c = $pdo->prepare('SELECT * FROM customers WHERE id = :id'); $c->execute([':id' => $id]); $cu = $c->fetch(); if (!$cu) { http_response_code(404); exit('Not found'); }
    $from = input_date($_GET, 'from', date('Y-m-d', strtotime('-90 days'))); $to = input_date($_GET, 'to', date('Y-m-d'));
    $st = customer_statement($id, $from, $to); $open = ar_open_invoices($id);
    $age = array_fill_keys(array_keys(ageing_labels()), 0.0); foreach ($open as $o) $age[$o['bucket']] += (float) $o['outstanding'];
    doc_start('Statement', $cu['code'], 'Customer Statement', ['Period' => fmt_date($from) . ' – ' . fmt_date($to)], '/sales/customer_view.php?id=' . $id);
    ?>
    <div class="doc-meta"><div class="box"><h4>Customer</h4><strong><?= e($cu['business_name']) ?></strong><br><?= e($cu['address'] ?? '') ?><br><?= e($cu['phone'] ?? '') ?></div><div class="box"><h4>Account</h4>Credit limit: <strong><?= format_money($cu['credit_limit']) ?></strong><br>Terms: <strong><?= (int) $cu['payment_terms_days'] ? (int) $cu['payment_terms_days'] . ' days' : 'Cash' ?></strong></div></div>
    <table class="doc-table"><thead><tr><th>Date</th><th>Type</th><th>Reference</th><th class="r">Debit</th><th class="r">Credit</th><th class="r">Balance</th></tr></thead><tbody>
      <tr><td colspan="5"><em>Opening balance</em></td><td class="r"><?= number_format($st['opening'], 2) ?></td></tr>
      <?php foreach ($st['rows'] as $r): ?><tr><td><?= e(fmt_date($r['d'])) ?></td><td><?= e($r['t']) ?></td><td><?= e($r['ref']) ?></td><td class="r"><?= (float) $r['debit'] ? number_format($r['debit'], 2) : '' ?></td><td class="r"><?= (float) $r['credit'] ? number_format($r['credit'], 2) : '' ?></td><td class="r"><?= number_format($r['balance'], 2) ?></td></tr><?php endforeach; ?>
      <tr><td colspan="5"><strong>Closing balance</strong></td><td class="r"><strong><?= number_format($st['closing'], 2) ?></strong></td></tr></tbody></table>
    <table class="doc-table" style="max-width:560px;"><thead><tr><?php foreach (ageing_labels() as $l): ?><th class="r"><?= e($l) ?></th><?php endforeach; ?></tr></thead><tbody><tr><?php foreach ($age as $v): ?><td class="r"><?= number_format($v, 2) ?></td><?php endforeach; ?></tr></tbody></table>
    <?php doc_end('Please settle overdue amounts promptly. Contact accounts for any discrepancy.');
} else { http_response_code(404); echo 'Unknown document'; }
