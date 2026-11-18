<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('invoices.view');

$pdo = db();
$period = resolve_report_period('this_year'); $hl = input_int($_GET, 'highlight'); $cust = input_int($_GET, 'customer');
$page = max(1, input_int($_GET, 'page', 1)); $perPage = 40;
$where = $hl ? '1=1' : 'cn.cn_date BETWEEN :f AND :t'; $params = $hl ? [] : [':f' => $period['from'], ':t' => $period['to']];
if ($cust) { $where .= ' AND cn.customer_id = :c'; $params[':c'] = $cust; }
$from = "FROM credit_notes cn JOIN customers c ON c.id = cn.customer_id JOIN sales_invoices si ON si.id = cn.invoice_id LEFT JOIN sales_returns sr ON sr.id = cn.return_id LEFT JOIN users u ON u.id = cn.created_by WHERE $where";
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
$sm = $pdo->prepare("SELECT COALESCE(SUM(cn.total),0) $from"); $sm->execute($params);
$st = $pdo->prepare("SELECT cn.*, c.business_name, si.inv_no, sr.rma_no, u.full_name AS who $from ORDER BY cn.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params); $rows = $st->fetchAll();
$pageTitle = 'Credit notes';
require __DIR__ . '/../includes/header.php';
page_header('Returns & credit notes', 'Credit notes reduce what a customer owes — issued after an inspected return or for a price adjustment.');
nav_sales();
render_subnav([['/sales/returns.php', 'Customer returns', 'returns.create', 'undo-2'], ['/sales/credit_notes.php', 'Credit notes', 'invoices.view', 'file-minus']]);
?>
<div class="card tight mb-4"><?php render_period_filter($period, ['customer' => $cust ?: '']); ?></div>
<div class="grid grid-3 mb-4"><?= kpi('Credit notes issued', format_money_short($sm->fetchColumn()), 'file-minus', 'amber', e($period['label'])) ?><?= kpi('Count', number_format($total), 'receipt') ?></div>
<div class="card flush"><div class="table-wrap"><table class="data-table">
  <thead><tr><th>Credit note</th><th>Date</th><th>Customer</th><th>Invoice</th><th>Return</th><th>Reason</th><th>Issued by</th><th class="num">Amount</th></tr></thead>
  <tbody><?php foreach ($rows as $c): ?><tr class="<?= $hl === (int) $c['id'] ? 'row-warn' : '' ?>"><td class="strong mono"><a href="<?= e(app_url('/sales/print.php?type=credit_note&id=' . $c['id'])) ?>" target="_blank"><?= e($c['cn_no']) ?></a></td><td class="text-secondary nowrap"><?= e(fmt_date($c['cn_date'])) ?></td><td><?= e($c['business_name']) ?></td><td><a class="link" href="<?= e(app_url('/sales/invoice_view.php?id=' . $c['invoice_id'])) ?>"><?= e($c['inv_no']) ?></a></td><td><?= $c['return_id'] ? '<a class="link" href="' . e(app_url('/sales/return_view.php?id=' . $c['return_id'])) . '">' . e($c['rma_no']) . '</a>' : '—' ?></td><td class="text-secondary"><?= e($c['reason'] ?? '') ?></td><td class="text-secondary"><?= e($c['who'] ?? '—') ?></td><td class="num strong"><?= format_money($c['total']) ?><?= (float) $c['tax_total'] > 0 ? '<div class="cell-sub">incl. ' . format_money($c['tax_total']) . ' tax</div>' : '' ?></td></tr><?php endforeach; ?></tbody>
</table><?php if (!$rows) echo empty_state('file-minus', 'No credit notes in this period'); ?></div><?php render_pagination($page, $perPage, $total); ?></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
