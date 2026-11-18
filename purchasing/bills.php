<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.view');

$pdo = db();
$status = input($_GET, 'status'); $sup = input_int($_GET, 'supplier_id'); $q = input($_GET, 'q'); $match = input($_GET, 'match');
$page = max(1, input_int($_GET, 'page', 1)); $perPage = 40;
$where = "si.status <> 'void'"; $params = [];
if ($status === 'unpaid') $where .= " AND si.status IN ('unpaid','partial')"; elseif ($status === 'overdue') $where .= " AND si.status IN ('unpaid','partial') AND si.due_date < CURDATE()"; elseif ($status === 'paid') $where .= " AND si.status = 'paid'";
if (in_array($match, ['matched', 'variance', 'unmatched'], true)) { $where .= ' AND si.match_status = :m'; $params[':m'] = $match; }
if ($sup) { $where .= ' AND si.supplier_id = :s'; $params[':s'] = $sup; }
if ($q !== '') { $where .= ' AND (si.bill_no LIKE :q1 OR si.supplier_ref LIKE :q2 OR s.name LIKE :q3)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%"]; }
$from = "FROM supplier_invoices si JOIN suppliers s ON s.id = si.supplier_id WHERE $where";
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
$sm = $pdo->prepare("SELECT COALESCE(SUM(si.total),0) t, COALESCE(SUM(si.total - si.paid_amount),0) o, COALESCE(SUM(CASE WHEN si.due_date < CURDATE() THEN si.total - si.paid_amount ELSE 0 END),0) ov $from"); $sm->execute($params); $sum = $sm->fetch();
$st = $pdo->prepare("SELECT si.*, s.name AS supplier, (si.total - si.paid_amount) AS outstanding $from ORDER BY si.invoice_date DESC, si.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params); $rows = $st->fetchAll();
$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();
$varCount = (int) $pdo->query("SELECT COUNT(*) FROM supplier_invoices WHERE match_status = 'variance' AND status <> 'void'")->fetchColumn();
$pageTitle = 'Supplier invoices';
require __DIR__ . '/../includes/header.php';
page_header('Supplier invoices', 'Invoices are matched against the goods actually received, then paid by their due date.', can('supplier_bills.manage') ? '<a class="btn btn-primary" href="' . e(app_url('/purchasing/bill_form.php')) . '">' . icon('plus', 16) . ' Record invoice</a>' : '');
nav_purchasing();
?>
<div class="stat-strip"><div><div class="sl">Invoiced</div><div class="sv"><?= format_money_short($sum['t']) ?></div></div><div><div class="sl">Outstanding</div><div class="sv"><?= format_money_short($sum['o']) ?></div></div><div><div class="sl">Overdue</div><div class="sv <?= $sum['ov'] > 0 ? 'text-error' : '' ?>"><?= format_money_short($sum['ov']) ?></div></div><div><div class="sl">Match variances</div><div class="sv <?= $varCount ? 'text-warning' : '' ?>"><?= $varCount ?></div></div></div>
<div class="card flush">
  <div style="padding:16px 16px 0;"><div class="filter-bar"><div class="chip-group"><a class="chip <?= $status === '' && $match === '' ? 'active' : '' ?>" href="<?= e(query_with(['status' => null, 'match' => null, 'page' => null])) ?>">All</a><a class="chip <?= $status === 'unpaid' ? 'active' : '' ?>" href="<?= e(query_with(['status' => 'unpaid', 'page' => null])) ?>">Unpaid</a><a class="chip <?= $status === 'overdue' ? 'active' : '' ?>" href="<?= e(query_with(['status' => 'overdue', 'page' => null])) ?>">Overdue</a><a class="chip <?= $status === 'paid' ? 'active' : '' ?>" href="<?= e(query_with(['status' => 'paid', 'page' => null])) ?>">Paid</a><a class="chip <?= $match === 'variance' ? 'active' : '' ?>" href="<?= e(query_with(['match' => 'variance', 'page' => null])) ?>">Variance</a></div></div>
    <form method="get" class="filter-bar"><?php foreach (['status' => $status, 'match' => $match] as $k => $v) if ($v) echo '<input type="hidden" name="' . $k . '" value="' . e($v) . '">'; ?><div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Invoice no. or supplier"></div><select class="form-control" name="supplier_id" onchange="this.form.submit()"><option value="">All suppliers</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sup === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></form></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Invoice</th><th>Supplier</th><th>Date</th><th>Due</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Outstanding</th><th>3-way match</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $b): $late = in_array($b['status'], ['unpaid', 'partial'], true) && $b['due_date'] < date('Y-m-d'); ?><tr><td class="strong"><a href="<?= e(app_url('/purchasing/bill_view.php?id=' . $b['id'])) ?>"><?= e($b['bill_no']) ?></a><div class="cell-sub"><?= e($b['supplier_ref']) ?></div></td><td><?= e($b['supplier']) ?></td><td class="text-secondary nowrap"><?= e(fmt_date($b['invoice_date'])) ?></td><td class="nowrap <?= $late ? 'text-error fw-700' : 'text-secondary' ?>"><?= e(fmt_date($b['due_date'])) ?></td><td class="num"><?= format_money($b['total']) ?></td><td class="num text-secondary"><?= (float) $b['paid_amount'] > 0 ? format_money($b['paid_amount']) : '—' ?></td><td class="num strong"><?= $b['outstanding'] > 0.004 ? format_money($b['outstanding']) : '—' ?></td><td><?= status_badge($b['match_status']) ?></td><td><?= $late ? '<span class="badge badge-error">Overdue</span>' : status_badge($b['status']) ?></td></tr><?php endforeach; ?></tbody></table>
  <?php if (!$rows) echo empty_state('file-spreadsheet', 'No supplier invoices', 'Record the supplier’s invoice after goods are received.'); ?></div><?php render_pagination($page, $perPage, $total); ?></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
