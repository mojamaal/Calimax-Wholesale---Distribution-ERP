<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('sales.view');

$pdo = db();
$me = (int) current_user()['id']; $own = !can('sales.view_all');
$status = input($_GET, 'status'); $q = input($_GET, 'q'); $cust = input_int($_GET, 'customer'); $rep = input_int($_GET, 'rep');
$page = max(1, input_int($_GET, 'page', 1)); $perPage = 40;
$statuses = ['draft' => 'Draft', 'pending_approval' => 'Awaiting approval', 'confirmed' => 'Confirmed', 'partially_fulfilled' => 'Part fulfilled', 'dispatched' => 'Dispatched', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'rejected' => 'Rejected'];

$scope = '1=1'; $sp = [];
if ($own) { $scope .= ' AND so.rep_id = :me'; $sp[':me'] = $me; }
$where = $scope; $params = $sp;
if (isset($statuses[$status])) { $where .= ' AND so.status = :st'; $params[':st'] = $status; }
if ($q !== '') { $where .= ' AND (so.so_no LIKE :q1 OR c.business_name LIKE :q2 OR so.customer_po_ref LIKE :q3)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%"]; }
if ($cust) { $where .= ' AND so.customer_id = :cu'; $params[':cu'] = $cust; }
if ($rep && !$own) { $where .= ' AND so.rep_id = :rp'; $params[':rp'] = $rep; }
$from = "FROM sales_orders so JOIN customers c ON c.id = so.customer_id LEFT JOIN users u ON u.id = so.rep_id WHERE $where";
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
$st = $pdo->prepare("SELECT so.*, c.business_name, u.full_name AS rep,
    (SELECT COALESCE(SUM(qty - qty_cancelled),0) FROM sales_order_items WHERE so_id = so.id) AS units, (SELECT COALESCE(SUM(qty_dispatched),0) FROM sales_order_items WHERE so_id = so.id) AS disp
    $from ORDER BY so.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params); $orders = $st->fetchAll();
$counts = [];
$cs = $pdo->prepare("SELECT so.status, COUNT(*) n FROM sales_orders so WHERE $scope GROUP BY so.status"); $cs->execute($sp);
foreach ($cs->fetchAll() as $r) $counts[$r['status']] = (int) $r['n'];
$openValue = $pdo->prepare("SELECT COALESCE(SUM(so.total),0) FROM sales_orders so WHERE $scope AND so.status IN ('confirmed','partially_fulfilled','dispatched')"); $openValue->execute($sp);
$customers = $pdo->query('SELECT id, business_name FROM customers ORDER BY business_name')->fetchAll();
$reps = $pdo->query("SELECT id, full_name FROM users WHERE role = 'sales_rep' ORDER BY full_name")->fetchAll();

$pageTitle = 'Sales Orders';
require __DIR__ . '/../includes/header.php';
page_header('Sales', 'Customer orders from request to delivery. Stock is reserved when an order is confirmed.', can('sales.create') ? '<a class="btn btn-primary" href="' . e(app_url('/sales/order_form.php')) . '">' . icon('plus', 16) . ' New sales order</a>' : '');
nav_sales();
?>
<div class="grid grid-4 mb-4">
  <?= kpi('Awaiting approval', (string) ($counts['pending_approval'] ?? 0), 'shield-alert', ($counts['pending_approval'] ?? 0) ? 'amber' : 'gray', 'Credit or discount exceptions', '/sales/orders.php?status=pending_approval') ?>
  <?= kpi('To fulfil', (string) (($counts['confirmed'] ?? 0) + ($counts['partially_fulfilled'] ?? 0)), 'package-check', 'blue', 'Confirmed, stock reserved', '/sales/orders.php?status=confirmed') ?>
  <?= kpi('In transit', (string) ($counts['dispatched'] ?? 0), 'truck', 'amber', 'Dispatched, awaiting delivery', '/sales/orders.php?status=dispatched') ?>
  <?= kpi('Open order value', format_money_short($openValue->fetchColumn()), 'banknote', 'green', 'Confirmed but not completed') ?>
</div>
<div class="card flush">
  <div style="padding:16px 16px 0;">
    <div class="filter-bar"><div class="chip-group">
      <a class="chip <?= $status === '' ? 'active' : '' ?>" href="<?= e(query_with(['status' => null, 'page' => null])) ?>">All</a>
      <?php foreach ($statuses as $k => $l): ?><a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(query_with(['status' => $k, 'page' => null])) ?>"><?= $l ?> <span class="chip-count"><?= $counts[$k] ?? 0 ?></span></a><?php endforeach; ?>
    </div></div>
    <form method="get" class="filter-bar">
      <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
      <div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Order no., customer or customer PO"></div>
      <select class="form-control" name="customer" onchange="this.form.submit()"><option value="">All customers</option><?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $cust === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['business_name']) ?></option><?php endforeach; ?></select>
      <?php if (!$own): ?><select class="form-control" name="rep" onchange="this.form.submit()"><option value="">All reps</option><?php foreach ($reps as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $rep === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['full_name']) ?></option><?php endforeach; ?></select><?php endif; ?>
    </form>
  </div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Rep</th><th style="width:150px;">Dispatched</th><th>Payment</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): $pct = $o['units'] ? min(100, round($o['disp'] / $o['units'] * 100)) : 0; ?>
      <tr>
        <td class="strong"><a href="<?= e(app_url('/sales/order_view.php?id=' . $o['id'])) ?>"><?= e($o['so_no']) ?></a><?= $o['customer_po_ref'] ? '<div class="cell-sub">PO ' . e($o['customer_po_ref']) . '</div>' : '' ?></td>
        <td><a href="<?= e(app_url('/sales/customer_view.php?id=' . $o['customer_id'])) ?>"><?= e($o['business_name']) ?></a></td>
        <td class="text-secondary nowrap"><?= e(fmt_date($o['order_date'])) ?></td>
        <td class="text-secondary"><?= e($o['rep'] ?? '—') ?></td>
        <td><?php if (in_array($o['status'], ['confirmed', 'partially_fulfilled', 'dispatched', 'completed'], true)): ?><div class="text-sm"><?= fmt_qty($o['disp']) ?> / <?= fmt_qty($o['units']) ?></div><div class="meter mt-1 <?= $pct >= 100 ? '' : 'warn' ?>"><span style="width:<?= $pct ?>%"></span></div><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
        <td><span class="badge badge-<?= $o['payment_type'] === 'cash' ? 'success' : 'info' ?>"><?= $o['payment_type'] === 'cash' ? 'Cash' : 'Credit' ?></span></td>
        <td class="num strong"><?= format_money($o['total']) ?></td>
        <td><?= status_badge($o['status']) ?></td>
        <td class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/sales/order_view.php?id=' . $o['id'])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$orders) echo empty_state('clipboard-list', 'No orders found', 'Create a sales order when a customer asks for products.'); ?></div>
  <?php render_pagination($page, $perPage, $total); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
