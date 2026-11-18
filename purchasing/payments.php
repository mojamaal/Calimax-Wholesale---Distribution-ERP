<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.view');

$pdo = db();
$period = resolve_report_period('this_month'); $sup = input_int($_GET, 'supplier_id'); $hl = input_int($_GET, 'highlight');
$page = max(1, input_int($_GET, 'page', 1)); $perPage = 40;
$where = 'sp.pay_date BETWEEN :f AND :t'; $params = [':f' => $period['from'], ':t' => $period['to']];
if ($sup) { $where .= ' AND sp.supplier_id = :s'; $params[':s'] = $sup; }
$from = "FROM supplier_payments sp JOIN suppliers s ON s.id = sp.supplier_id LEFT JOIN users u ON u.id = sp.created_by WHERE $where";
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
$sm = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN sp.kind = 'payment' THEN sp.amount ELSE 0 END),0) paid, COALESCE(SUM(CASE WHEN sp.kind <> 'payment' THEN sp.amount ELSE 0 END),0) credits $from"); $sm->execute($params); $sum = $sm->fetch();
$st = $pdo->prepare("SELECT sp.*, s.name AS supplier, u.full_name AS who, (SELECT GROUP_CONCAT(si.bill_no ORDER BY si.id SEPARATOR ', ') FROM supplier_payment_allocations a JOIN supplier_invoices si ON si.id = a.invoice_id WHERE a.payment_id = sp.id) AS bills $from ORDER BY sp.pay_date DESC, sp.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params); $rows = $st->fetchAll();
$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();
$pageTitle = 'Supplier payments';
require __DIR__ . '/../includes/header.php';
page_header('Supplier payments', 'Money paid to suppliers and credits from returned goods.', (can('supplier.pay') ? '<a class="btn btn-primary" href="' . e(app_url('/purchasing/payment_form.php')) . '">' . icon('plus', 16) . ' Pay supplier</a>' : '') . (can('purchase.receive') ? '<a class="btn btn-secondary" href="' . e(app_url('/purchasing/returns.php')) . '">' . icon('undo-2', 16) . ' Returns to supplier</a>' : ''));
nav_purchasing();
?>
<div class="card tight mb-4"><?php render_period_filter($period, ['supplier_id' => $sup ?: '']); ?></div>
<div class="grid grid-3 mb-4"><?= kpi('Paid to suppliers', format_money_short($sum['paid']), 'banknote', 'amber', e($period['label'])) ?><?= kpi('Return credits', format_money_short($sum['credits']), 'undo-2', 'blue', 'Goods sent back') ?><?= kpi('We owe suppliers', format_money_short(payables_total()), 'file-clock', 'red', format_money_short(payables_overdue()) . ' overdue', '/finance/payables.php') ?></div>
<div class="card flush">
  <div style="padding:16px 16px 0;"><form method="get" class="filter-bar"><?php foreach (['preset' => $period['preset'] ?: 'custom', 'from' => $period['from'], 'to' => $period['to']] as $k => $v) echo '<input type="hidden" name="' . $k . '" value="' . e($v) . '">'; ?><select class="form-control" name="supplier_id" onchange="this.form.submit()"><option value="">All suppliers</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sup === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></form></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Payment</th><th>Date</th><th>Supplier</th><th>Method</th><th>Applied to</th><th>By</th><th class="num">Amount</th></tr></thead><tbody>
  <?php foreach ($rows as $p): ?><tr class="<?= $hl === (int) $p['id'] ? 'row-warn' : '' ?>"><td class="strong mono"><?= e($p['pay_no']) ?></td><td class="text-secondary nowrap"><?= e(fmt_date($p['pay_date'])) ?></td><td><?= e($p['supplier']) ?></td><td class="text-secondary"><?= $p['kind'] === 'return_credit' ? '<span class="badge badge-info">Return credit</span>' : e(method_label($p['method'])) ?><?= $p['reference'] ? '<div class="cell-sub mono">' . e($p['reference']) . '</div>' : '' ?></td><td class="text-sm"><?= e($p['bills'] ?? '—') ?></td><td class="text-secondary"><?= e($p['who'] ?? '—') ?></td><td class="num strong"><?= format_money($p['amount']) ?></td></tr><?php endforeach; ?></tbody></table>
  <?php if (!$rows) echo empty_state('banknote', 'No payments in this period'); ?></div><?php render_pagination($page, $perPage, $total); ?></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
