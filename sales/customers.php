<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('customers.view');

$pdo = db();
$me = (int) current_user()['id']; $own = !can('sales.view_all');
$q = input($_GET, 'q'); $cat = input_int($_GET, 'cat'); $rep = input_int($_GET, 'rep'); $filter = input($_GET, 'filter');
$page = max(1, input_int($_GET, 'page', 1)); $perPage = 40;

$where = '1=1'; $params = [];
if ($own) { $where .= ' AND c.rep_id = :me'; $params[':me'] = $me; }
if ($q !== '') { $where .= ' AND (c.business_name LIKE :q1 OR c.code LIKE :q2 OR c.contact_person LIKE :q3 OR c.phone LIKE :q4 OR c.city LIKE :q5)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%", ':q4' => "%$q%", ':q5' => "%$q%"]; }
if ($cat) { $where .= ' AND c.category_id = :cat'; $params[':cat'] = $cat; }
if ($rep) { $where .= ' AND c.rep_id = :rep'; $params[':rep'] = $rep; }
if ($filter === 'inactive') $where .= ' AND c.is_active = 0'; else $where .= ' AND c.is_active = 1';
$out = "COALESCE((SELECT SUM(si.total - si.paid_amount - si.credited_amount) FROM sales_invoices si WHERE si.customer_id = c.id AND si.status <> 'void'),0) - COALESCE((SELECT SUM(cp.amount - cp.allocated) FROM customer_payments cp WHERE cp.customer_id = c.id),0)";
$ovd = "COALESCE((SELECT SUM(si.total - si.paid_amount - si.credited_amount) FROM sales_invoices si WHERE si.customer_id = c.id AND si.status IN ('unpaid','partial') AND si.due_date < CURDATE()),0)";
$having = $filter === 'overdue' ? 'HAVING overdue > 0.005' : ($filter === 'over_limit' ? 'HAVING c.credit_limit > 0 AND outstanding > c.credit_limit' : '');
$base = "FROM customers c LEFT JOIN customer_categories cc ON cc.id = c.category_id LEFT JOIN users u ON u.id = c.rep_id WHERE $where";
$st = $pdo->prepare("SELECT c.*, cc.name AS category, u.full_name AS rep_name, ($out) AS outstanding, ($ovd) AS overdue, (SELECT MAX(order_date) FROM sales_orders WHERE customer_id = c.id AND status NOT IN ('draft','cancelled','rejected')) AS last_order $base $having ORDER BY c.business_name");
$st->execute($params);
$all = $st->fetchAll(); $total = count($all); $rows = array_slice($all, ($page - 1) * $perPage, $perPage);
$cats = $pdo->query('SELECT id, name FROM customer_categories ORDER BY id')->fetchAll();
$reps = $pdo->query("SELECT id, full_name FROM users WHERE role = 'sales_rep' ORDER BY full_name")->fetchAll();

$pageTitle = 'Customers';
require __DIR__ . '/../includes/header.php';
page_header('Customers', 'Business accounts with credit limits, payment terms and a full transaction history.', can('customers.create') ? '<a class="btn btn-primary" href="' . e(app_url('/sales/customer_form.php')) . '">' . icon('plus', 16) . ' New customer</a>' : '');
nav_customers();
?>
<div class="card flush">
  <div style="padding:16px 16px 0;">
    <form method="get" class="filter-bar">
      <div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, code, contact, phone or city"></div>
      <select class="form-control" name="cat" onchange="this.form.submit()"><option value="">All categories</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $cat === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
      <?php if (!$own): ?><select class="form-control" name="rep" onchange="this.form.submit()"><option value="">All sales reps</option><?php foreach ($reps as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $rep === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['full_name']) ?></option><?php endforeach; ?></select><?php endif; ?>
      <div class="chip-group"><a class="chip <?= $filter === '' ? 'active' : '' ?>" href="<?= e(query_with(['filter' => null, 'page' => null])) ?>">Active</a><a class="chip <?= $filter === 'overdue' ? 'active' : '' ?>" href="<?= e(query_with(['filter' => 'overdue', 'page' => null])) ?>">Overdue</a><a class="chip <?= $filter === 'over_limit' ? 'active' : '' ?>" href="<?= e(query_with(['filter' => 'over_limit', 'page' => null])) ?>">Over credit limit</a><a class="chip <?= $filter === 'inactive' ? 'active' : '' ?>" href="<?= e(query_with(['filter' => 'inactive', 'page' => null])) ?>">Inactive</a></div>
    </form>
  </div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Customer</th><th>Category</th><th>Sales rep</th><th>Terms</th><th style="width:190px;">Credit used</th><th class="num">Outstanding</th><th class="num">Overdue</th><th>Last order</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $c): $lim = (float) $c['credit_limit']; $pct = $lim > 0 ? min(100, max(0, $c['outstanding']) / $lim * 100) : 0; $over = $lim > 0 && $c['outstanding'] > $lim; ?>
      <tr class="<?= $c['is_active'] ? '' : 'row-muted' ?>">
        <td><a class="strong" href="<?= e(app_url('/sales/customer_view.php?id=' . $c['id'])) ?>"><?= e($c['business_name']) ?></a><div class="cell-sub"><span class="mono"><?= e($c['code']) ?></span> · <?= e($c['contact_person'] ?? '') ?> <?= e($c['phone'] ?? '') ?></div></td>
        <td class="text-secondary"><?= e($c['category'] ?? '—') ?></td>
        <td class="text-secondary"><?= e($c['rep_name'] ?? '—') ?></td>
        <td class="text-secondary nowrap"><?= (int) $c['payment_terms_days'] ? (int) $c['payment_terms_days'] . ' days' : 'Cash' ?></td>
        <td><?php if ($lim > 0): ?><div class="text-sm"><?= format_money_short(max(0, $c['outstanding'])) ?> of <?= format_money_short($lim) ?></div><div class="meter mt-1 <?= $over ? 'bad' : ($pct > 80 ? 'warn' : '') ?>"><span style="width:<?= round($pct) ?>%"></span></div><?php else: ?><span class="text-muted text-sm">No credit</span><?php endif; ?></td>
        <td class="num strong"><?= $c['outstanding'] > 0.005 ? format_money($c['outstanding']) : '<span class="text-muted">—</span>' ?></td>
        <td class="num <?= $c['overdue'] > 0.005 ? 'text-error strong' : 'text-muted' ?>"><?= $c['overdue'] > 0.005 ? format_money($c['overdue']) : '—' ?></td>
        <td class="text-secondary nowrap"><?= e(fmt_date($c['last_order'])) ?></td>
        <td class="actions"><?php if (can('sales.create') && $c['is_active']): ?><a class="btn btn-primary btn-sm" href="<?= e(app_url('/sales/order_form.php?customer_id=' . $c['id'])) ?>">New order</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows) echo empty_state('contact', 'No customers found', 'Register a business customer to start taking orders.'); ?></div>
  <?php render_pagination($page, $perPage, $total); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
