<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('invoices.view');

$pdo = db();
$me = (int) current_user()['id']; $own = !can('sales.view_all') && !can('invoices.create');
$status = input($_GET, 'status'); $filter = input($_GET, 'filter'); $q = input($_GET, 'q'); $cust = input_int($_GET, 'customer'); $rep = input_int($_GET, 'rep');
$period = resolve_report_period('this_year');
$all = input($_GET, 'range') === 'all' || $filter === 'overdue' || $cust || $q !== '';
$page = max(1, input_int($_GET, 'page', 1)); $perPage = 40;

$where = "si.status <> 'void'"; $params = [];
if ($own) { $where .= ' AND si.rep_id = :me'; $params[':me'] = $me; }
if (!$all) { $where .= ' AND si.invoice_date BETWEEN :f AND :t'; $params += [':f' => $period['from'], ':t' => $period['to']]; }
if ($status === 'unpaid') $where .= " AND si.status IN ('unpaid','partial')"; elseif ($status === 'paid') $where .= " AND si.status = 'paid'";
if ($filter === 'overdue') $where .= " AND si.status IN ('unpaid','partial') AND si.due_date < CURDATE() AND (si.total - si.paid_amount - si.credited_amount) > 0.004";
if ($q !== '') { $where .= ' AND (si.inv_no LIKE :q1 OR c.business_name LIKE :q2)'; $params += [':q1' => "%$q%", ':q2' => "%$q%"]; }
if ($cust) { $where .= ' AND si.customer_id = :cu'; $params[':cu'] = $cust; }
if ($rep && !$own) { $where .= ' AND si.rep_id = :rp'; $params[':rp'] = $rep; }
$from = "FROM sales_invoices si JOIN customers c ON c.id = si.customer_id LEFT JOIN users u ON u.id = si.rep_id WHERE $where";
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
$sum = $pdo->prepare("SELECT COALESCE(SUM(si.total),0) t, COALESCE(SUM(si.total - si.paid_amount - si.credited_amount),0) o, COALESCE(SUM(CASE WHEN si.due_date < CURDATE() THEN si.total - si.paid_amount - si.credited_amount ELSE 0 END),0) ov $from"); $sum->execute($params); $sm = $sum->fetch();
$st = $pdo->prepare("SELECT si.*, c.business_name, u.full_name AS rep, (si.total - si.paid_amount - si.credited_amount) AS outstanding $from ORDER BY si.invoice_date DESC, si.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params); $rows = $st->fetchAll();
$customers = $pdo->query('SELECT id, business_name FROM customers ORDER BY business_name')->fetchAll();
$reps = $pdo->query("SELECT id, full_name FROM users WHERE role = 'sales_rep' ORDER BY full_name")->fetchAll();

$pageTitle = 'Invoices';
require __DIR__ . '/../includes/header.php';
page_header('Invoices', 'Sales invoices raised from delivered goods, with payment status and ageing.', can('payments.receive') ? '<a class="btn btn-primary" href="' . e(app_url('/sales/payment_form.php')) . '">' . icon('hand-coins', 16) . ' Record payment</a>' : '');
nav_sales();
?>
<div class="stat-strip"><div><div class="sl">Invoiced</div><div class="sv"><?= format_money_short($sm['t']) ?></div></div><div><div class="sl">Outstanding</div><div class="sv"><?= format_money_short($sm['o']) ?></div></div><div><div class="sl">Overdue</div><div class="sv <?= $sm['ov'] > 0 ? 'text-error' : '' ?>"><?= format_money_short($sm['ov']) ?></div></div><div><div class="sl">Invoices</div><div class="sv"><?= number_format($total) ?></div></div></div>
<?php if (!$all) { echo '<div class="card tight mb-4">'; render_period_filter($period, ['status' => $status, 'q' => $q, 'customer' => $cust ?: '', 'rep' => $rep ?: '']); echo '</div>'; } ?>
<div class="card flush">
  <div style="padding:16px 16px 0;">
    <form method="get" class="filter-bar">
      <div class="chip-group"><a class="chip <?= $status === '' && $filter === '' ? 'active' : '' ?>" href="<?= e(query_with(['status' => null, 'filter' => null, 'page' => null])) ?>">All</a><a class="chip <?= $status === 'unpaid' && $filter === '' ? 'active' : '' ?>" href="<?= e(query_with(['status' => 'unpaid', 'filter' => null, 'page' => null])) ?>">Unpaid</a><a class="chip <?= $filter === 'overdue' ? 'active' : '' ?>" href="<?= e(query_with(['filter' => 'overdue', 'status' => null, 'page' => null])) ?>">Overdue</a><a class="chip <?= $status === 'paid' ? 'active' : '' ?>" href="<?= e(query_with(['status' => 'paid', 'filter' => null, 'page' => null])) ?>">Paid</a><a class="chip <?= $all && $filter === '' && !$q && !$cust ? 'active' : '' ?>" href="<?= e(query_with(['range' => 'all', 'page' => null])) ?>">All time</a></div>
      <?php foreach (['status' => $status, 'filter' => $filter, 'range' => input($_GET, 'range')] as $k => $v) if ($v) echo '<input type="hidden" name="' . $k . '" value="' . e($v) . '">'; ?>
      <div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Invoice no. or customer"></div>
      <select class="form-control" name="customer" onchange="this.form.submit()"><option value="">All customers</option><?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $cust === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['business_name']) ?></option><?php endforeach; ?></select>
      <?php if (!$own): ?><select class="form-control" name="rep" onchange="this.form.submit()"><option value="">All reps</option><?php foreach ($reps as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $rep === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['full_name']) ?></option><?php endforeach; ?></select><?php endif; ?>
    </form>
  </div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Invoice</th><th>Customer</th><th>Date</th><th>Due</th><th>Rep</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Outstanding</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i): $late = in_array($i['status'], ['unpaid', 'partial'], true) && $i['due_date'] < date('Y-m-d') && $i['outstanding'] > 0.004; ?>
      <tr>
        <td class="strong"><a href="<?= e(app_url('/sales/invoice_view.php?id=' . $i['id'])) ?>"><?= e($i['inv_no']) ?></a><?= $i['payment_type'] === 'cash' ? ' <span class="badge badge-neutral">Cash</span>' : '' ?></td>
        <td><a href="<?= e(app_url('/sales/customer_view.php?id=' . $i['customer_id'])) ?>"><?= e($i['business_name']) ?></a></td>
        <td class="text-secondary nowrap"><?= e(fmt_date($i['invoice_date'])) ?></td>
        <td class="nowrap <?= $late ? 'text-error fw-700' : 'text-secondary' ?>"><?= e(fmt_date($i['due_date'])) ?><?= $late ? '<div class="cell-sub text-error">' . (int) floor((time() - strtotime($i['due_date'])) / 86400) . ' days overdue</div>' : '' ?></td>
        <td class="text-secondary"><?= e($i['rep'] ?? '—') ?></td>
        <td class="num"><?= format_money($i['total']) ?></td>
        <td class="num text-secondary"><?= (float) $i['paid_amount'] > 0 ? format_money($i['paid_amount']) : '—' ?><?= (float) $i['credited_amount'] > 0 ? '<div class="cell-sub">credited ' . format_money($i['credited_amount']) . '</div>' : '' ?></td>
        <td class="num strong"><?= $i['outstanding'] > 0.004 ? format_money($i['outstanding']) : '—' ?></td>
        <td><?= $late ? '<span class="badge badge-error">Overdue</span>' : status_badge($i['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows) echo empty_state('receipt', 'No invoices found', 'Invoices are created from delivered goods.'); ?></div>
  <?php render_pagination($page, $perPage, $total); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
