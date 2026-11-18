<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('suppliers.view');

$pdo = db();
$id = input_int($_GET, 'id');
$s = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id'); $s->execute([':id' => $id]); $sup = $s->fetch();
if (!$sup) { flash('error', 'Supplier not found.'); redirect('/purchasing/suppliers.php'); }
$balance = supplier_balance($id);
$open = ap_open_bills($id);
$ageing = array_fill_keys(array_keys(ageing_labels()), 0.0); foreach ($open as $o) $ageing[$o['bucket']] += (float) $o['outstanding'];
$ageTotal = array_sum($ageing) ?: 1;
$bills = $pdo->query("SELECT * FROM supplier_invoices WHERE supplier_id = $id AND status <> 'void' ORDER BY invoice_date DESC, id DESC LIMIT 8")->fetchAll();
$pos = $pdo->query("SELECT po.*, (SELECT COALESCE(SUM(qty),0) FROM purchase_order_items WHERE po_id = po.id) AS q, (SELECT COALESCE(SUM(received_qty),0) FROM purchase_order_items WHERE po_id = po.id) AS r FROM purchase_orders po WHERE supplier_id = $id ORDER BY id DESC LIMIT 8")->fetchAll();
$pays = $pdo->query("SELECT * FROM supplier_payments WHERE supplier_id = $id ORDER BY pay_date DESC, id DESC LIMIT 8")->fetchAll();
$life = $pdo->query("SELECT COALESCE(SUM(total),0) t, COUNT(*) n FROM supplier_invoices WHERE supplier_id = $id AND status <> 'void'")->fetch();
$pageTitle = $sup['name'];
require __DIR__ . '/../includes/header.php';
page_header($sup['name'], '<span class="mono">' . e($sup['code']) . '</span> · ' . (int) $sup['payment_terms_days'] . ' day terms' . ($sup['is_active'] ? '' : ' · <span class="text-error">Inactive</span>'),
    (can('purchase.create') ? '<a class="btn btn-primary" href="' . e(app_url('/purchasing/order_form.php?supplier_id=' . $id)) . '">' . icon('plus', 16) . ' New purchase order</a>' : '') . (can('supplier.pay') ? '<a class="btn btn-secondary" href="' . e(app_url('/purchasing/payment_form.php?supplier_id=' . $id)) . '">' . icon('banknote', 16) . ' Pay supplier</a>' : '')
    . '<a class="btn btn-secondary" target="_blank" href="' . e(app_url('/purchasing/print.php?type=statement&id=' . $id)) . '">' . icon('file-text', 16) . ' Statement</a>', [['Suppliers', '/purchasing/suppliers.php'], [$sup['name'], null]]);
?>
<div class="stat-strip"><div><div class="sl">We owe</div><div class="sv <?= $balance > 0 ? '' : 'text-success' ?>"><?= format_money($balance) ?></div></div><div><div class="sl">Overdue</div><div class="sv <?= $ageing['d1_30'] + $ageing['d31_60'] + $ageing['d61_90'] + $ageing['d90p'] > 0 ? 'text-error' : '' ?>"><?= format_money_short($ageing['d1_30'] + $ageing['d31_60'] + $ageing['d61_90'] + $ageing['d90p']) ?></div></div><div><div class="sl">Total invoiced</div><div class="sv"><?= format_money_short($life['t']) ?></div></div><div><div class="sl">Invoices</div><div class="sv"><?= (int) $life['n'] ?></div></div></div>
<div class="layout-1-2 mb-4">
  <div class="card"><h3 class="mb-3">Supplier details</h3><dl class="desc-list"><dt>Contact</dt><dd><?= e($sup['contact_person'] ?? '—') ?></dd><dt>Telephone</dt><dd><?= e($sup['phone'] ?? '—') ?></dd><dt>Email</dt><dd><?= e($sup['email'] ?? '—') ?></dd><dt>Address</dt><dd><?= e($sup['address'] ?? '—') ?></dd><dt>Tax no.</dt><dd><?= e($sup['tax_no'] ?? '—') ?></dd><dt>Terms</dt><dd><?= (int) $sup['payment_terms_days'] ?> days</dd></dl></div>
  <div class="grid">
    <div class="card"><div class="card-head"><h3>Payable ageing</h3></div><div class="age-bar mb-3"><?php foreach (array_keys(ageing_labels()) as $i => $k): if ($ageing[$k] > 0): ?><span class="age-<?= $i ?>" style="width:<?= $ageing[$k] / $ageTotal * 100 ?>%"></span><?php endif; endforeach; ?></div><div class="age-legend"><?php foreach (ageing_labels() as $k => $l): $i = array_search($k, array_keys(ageing_labels())); ?><span><i class="age-<?= $i ?>"></i><?= e($l) ?> <strong><?= format_money_short($ageing[$k]) ?></strong></span><?php endforeach; ?></div></div>
    <div class="card flush"><div class="card-head"><h3>Unpaid invoices</h3></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Invoice</th><th>Due</th><th class="num">Total</th><th class="num">Outstanding</th></tr></thead><tbody><?php foreach ($open as $o): ?><tr><td class="strong"><a href="<?= e(app_url('/purchasing/bill_view.php?id=' . $o['id'])) ?>"><?= e($o['bill_no']) ?></a><div class="cell-sub"><?= e($o['supplier_ref']) ?></div></td><td class="nowrap <?= $o['days_overdue'] > 0 ? 'text-error fw-700' : 'text-secondary' ?>"><?= e(fmt_date($o['due_date'])) ?><?= $o['days_overdue'] > 0 ? ' · ' . (int) $o['days_overdue'] . 'd late' : '' ?></td><td class="num"><?= format_money($o['total']) ?></td><td class="num strong"><?= format_money($o['outstanding']) ?></td></tr><?php endforeach; ?></tbody></table><?php if (!$open) echo empty_state('check-circle-2', 'Nothing owed', 'All invoices from this supplier are paid.'); ?></div></div>
  </div>
</div>
<div class="grid grid-2">
  <div class="card flush"><div class="card-head"><h3>Purchase orders</h3></div><div class="table-wrap"><table class="data-table"><thead><tr><th>PO</th><th>Date</th><th>Received</th><th class="num">Total</th><th>Status</th></tr></thead><tbody><?php foreach ($pos as $p): ?><tr><td class="strong"><a href="<?= e(app_url('/purchasing/order_view.php?id=' . $p['id'])) ?>"><?= e($p['po_no']) ?></a></td><td class="text-secondary nowrap"><?= e(fmt_date($p['order_date'])) ?></td><td class="text-sm"><?= fmt_qty($p['r']) ?> / <?= fmt_qty($p['q']) ?></td><td class="num"><?= format_money($p['total']) ?></td><td><?= status_badge($p['status']) ?></td></tr><?php endforeach; ?></tbody></table><?php if (!$pos) echo empty_state('file-text', 'No purchase orders'); ?></div></div>
  <div class="card flush"><div class="card-head"><h3>Recent payments</h3></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Payment</th><th>Date</th><th>Method</th><th class="num">Amount</th></tr></thead><tbody><?php foreach ($pays as $p): ?><tr><td class="strong mono"><?= e($p['pay_no']) ?></td><td class="text-secondary nowrap"><?= e(fmt_date($p['pay_date'])) ?></td><td class="text-secondary"><?= $p['kind'] === 'return_credit' ? 'Return credit' : e(method_label($p['method'])) ?></td><td class="num strong"><?= format_money($p['amount']) ?></td></tr><?php endforeach; ?></tbody></table><?php if (!$pays) echo empty_state('banknote', 'No payments yet'); ?></div></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
