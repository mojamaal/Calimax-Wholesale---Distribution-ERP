<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.view');

$pdo = db();
$id = input_int($_GET, 'id');
$s = $pdo->prepare('SELECT si.*, s.name AS supplier, s.payment_terms_days, po.po_no, u.full_name AS who FROM supplier_invoices si JOIN suppliers s ON s.id = si.supplier_id LEFT JOIN purchase_orders po ON po.id = si.po_id LEFT JOIN users u ON u.id = si.created_by WHERE si.id = :id');
$s->execute([':id' => $id]); $b = $s->fetch();
if (!$b) { flash('error', 'Invoice not found.'); redirect('/purchasing/bills.php'); }
$grns = $pdo->query("SELECT g.*, w.code AS wh FROM goods_receipts g JOIN warehouses w ON w.id = g.warehouse_id WHERE g.supplier_invoice_id = $id ORDER BY g.id")->fetchAll();
$pays = $pdo->query("SELECT spa.amount, sp.* FROM supplier_payment_allocations spa JOIN supplier_payments sp ON sp.id = spa.payment_id WHERE spa.invoice_id = $id ORDER BY sp.pay_date, sp.id")->fetchAll();
$out = round2((float) $b['total'] - (float) $b['paid_amount']);
$late = $out > 0.004 && $b['due_date'] < date('Y-m-d');
$pageTitle = $b['bill_no'];
require __DIR__ . '/../includes/header.php';
page_header($b['bill_no'], e($b['supplier']) . ' · their ref <strong>' . e($b['supplier_ref']) . '</strong> · ' . ($late ? '<span class="badge badge-error">Overdue</span>' : status_badge($b['status'])), $out > 0.004 && can('supplier.pay') ? '<a class="btn btn-primary" href="' . e(app_url('/purchasing/payment_form.php?supplier_id=' . $b['supplier_id'] . '&bill_id=' . $id)) . '">' . icon('banknote', 16) . ' Pay this invoice</a>' : '', [['Supplier invoices', '/purchasing/bills.php'], [$b['bill_no'], null]]);
echo next_step($b['match_status'] === 'matched' ? 'badge-check' : ($b['match_status'] === 'variance' ? 'alert-triangle' : 'help-circle'), $b['match_status'] === 'matched' ? 'Matched to goods received' : ($b['match_status'] === 'variance' ? 'Variance — review before paying' : 'Not matched to a goods receipt'), e($b['match_note'] ?? ''), '', $b['match_status'] === 'matched' ? 'good' : ($b['match_status'] === 'variance' ? 'warn' : 'info'));
?>
<div class="stat-strip"><div><div class="sl">Invoice total</div><div class="sv"><?= format_money($b['total']) ?></div></div><div><div class="sl">Paid</div><div class="sv text-success"><?= format_money($b['paid_amount']) ?></div></div><div><div class="sl">Outstanding</div><div class="sv <?= $out > 0.004 ? ($late ? 'text-error' : '') : 'text-success' ?>"><?= $out > 0.004 ? format_money($out) : 'Settled' ?></div></div><div><div class="sl">Due</div><div class="sv" style="font-size:16px;"><?= e(fmt_date($b['due_date'])) ?></div></div></div>
<div class="layout-2-1">
  <div class="grid">
    <div class="card flush"><div class="card-head"><h3>Three-way match</h3><span class="text-muted text-sm">Order ↔ goods received ↔ invoice</span></div>
      <div class="table-wrap"><table class="data-table"><thead><tr><th>Goods receipt</th><th>Date</th><th>Warehouse</th><th class="num">Goods value</th></tr></thead><tbody><?php foreach ($grns as $g): ?><tr><td class="strong"><a href="<?= e(app_url('/purchasing/grn_view.php?id=' . $g['id'])) ?>"><?= e($g['grn_no']) ?></a></td><td class="text-secondary"><?= e(fmt_date($g['received_date'])) ?></td><td class="text-secondary"><?= e($g['wh']) ?></td><td class="num"><?= format_money($g['total_value']) ?></td></tr><?php endforeach; ?></tbody>
        <tfoot><tr><td colspan="3" class="text-right">Goods received</td><td class="num"><?= format_money($b['grn_value']) ?></td></tr><tr><td colspan="3" class="text-right">Invoice amount (excl. tax)</td><td class="num"><?= format_money($b['subtotal']) ?></td></tr><tr><td colspan="3" class="text-right">Price variance</td><td class="num <?= abs($b['variance']) > 0.005 ? 'text-warning' : '' ?>"><?= format_money($b['variance']) ?></td></tr></tfoot></table><?php if (!$grns) echo empty_state('package', 'No goods receipt linked', 'This invoice was recorded without linking a delivery.'); ?></div></div>
    <div class="card flush"><div class="card-head"><h3>Payments</h3></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Payment</th><th>Date</th><th>Method</th><th class="num">Applied</th></tr></thead><tbody><?php foreach ($pays as $p): ?><tr><td class="strong mono"><?= e($p['pay_no']) ?></td><td class="text-secondary"><?= e(fmt_date($p['pay_date'])) ?></td><td class="text-secondary"><?= $p['kind'] === 'return_credit' ? 'Return credit' : e(method_label($p['method'])) ?></td><td class="num strong"><?= format_money($p['amount']) ?></td></tr><?php endforeach; ?></tbody></table><?php if (!$pays) echo empty_state('banknote', 'No payments yet'); ?></div></div>
  </div>
  <div class="card"><h3 class="mb-3">Details</h3><dl class="desc-list"><dt>Supplier</dt><dd><a class="link" href="<?= e(app_url('/purchasing/supplier_view.php?id=' . $b['supplier_id'])) ?>"><?= e($b['supplier']) ?></a></dd><dt>Their invoice</dt><dd><?= e($b['supplier_ref']) ?></dd><dt>Date</dt><dd><?= e(fmt_date($b['invoice_date'])) ?></dd><dt>Terms</dt><dd><?= (int) $b['payment_terms_days'] ?> days</dd><dt>Subtotal</dt><dd><?= format_money($b['subtotal']) ?></dd><dt><?= e(setting('tax_name', 'VAT')) ?></dt><dd><?= format_money($b['tax_total']) ?></dd><dt>Recorded by</dt><dd><?= e($b['who'] ?? '—') ?></dd><?php if ($b['po_no']): ?><dt>Purchase order</dt><dd><a class="link" href="<?= e(app_url('/purchasing/order_view.php?id=' . $b['po_id'])) ?>"><?= e($b['po_no']) ?></a></dd><?php endif; ?></dl><?php if ($b['notes']): ?><div class="callout mt-3"><?= e($b['notes']) ?></div><?php endif; ?></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
