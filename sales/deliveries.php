<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('delivery.view');

$pdo = db();
$me = (int) current_user()['id']; $own = !can('sales.view_all');
$status = input($_GET, 'status'); $filter = input($_GET, 'filter'); $q = input($_GET, 'q'); $wh = input_int($_GET, 'wh');
$page = max(1, input_int($_GET, 'page', 1)); $perPage = 40;
$statuses = ['pending_pick' => 'To pick', 'picked' => 'Picked', 'packed' => 'Packed', 'dispatched' => 'Dispatched', 'delivered' => 'Delivered', 'failed' => 'Failed', 'cancelled' => 'Cancelled'];

$scope = '1=1'; $sp = [];
if ($own) { $scope .= ' AND so.rep_id = :me'; $sp[':me'] = $me; }
$where = $scope; $params = $sp;
if (isset($statuses[$status])) { $where .= ' AND d.status = :st'; $params[':st'] = $status; }
if ($filter === 'open') $where .= " AND d.status IN ('pending_pick','picked','packed','dispatched')";
if ($filter === 'uninvoiced') $where .= " AND d.status = 'delivered' AND EXISTS (SELECT 1 FROM delivery_items di WHERE di.delivery_id = d.id AND di.qty_accepted > di.qty_invoiced)";
if ($filter === 'discrepancy') $where .= ' AND d.has_discrepancy = 1';
if ($q !== '') { $where .= ' AND (d.dn_no LIKE :q1 OR so.so_no LIKE :q2 OR c.business_name LIKE :q3)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%"]; }
if ($wh) { $where .= ' AND d.warehouse_id = :wh'; $params[':wh'] = $wh; }
$from = "FROM deliveries d JOIN sales_orders so ON so.id = d.so_id JOIN customers c ON c.id = d.customer_id JOIN warehouses w ON w.id = d.warehouse_id LEFT JOIN drivers dr ON dr.id = d.driver_id LEFT JOIN vehicles v ON v.id = d.vehicle_id WHERE $where";
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
$st = $pdo->prepare("SELECT d.*, so.so_no, c.business_name, w.code AS wh_code, dr.name AS driver, v.reg_no, (SELECT COALESCE(SUM(IF(d.status = 'pending_pick', qty_planned, qty_picked)),0) FROM delivery_items WHERE delivery_id = d.id) AS units
    $from ORDER BY FIELD(d.status,'pending_pick','picked','packed','dispatched','delivered','failed','cancelled'), d.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params); $rows = $st->fetchAll();
$counts = [];
$cs = $pdo->prepare("SELECT d.status, COUNT(*) n FROM deliveries d JOIN sales_orders so ON so.id = d.so_id WHERE $scope GROUP BY d.status"); $cs->execute($sp);
foreach ($cs->fetchAll() as $r) $counts[$r['status']] = (int) $r['n'];
$uninv = (int) $pdo->query("SELECT COUNT(*) FROM deliveries d WHERE d.status = 'delivered' AND EXISTS (SELECT 1 FROM delivery_items di WHERE di.delivery_id = d.id AND di.qty_accepted > di.qty_invoiced)")->fetchColumn();
$whs = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name')->fetchAll();

$pageTitle = 'Deliveries';
require __DIR__ . '/../includes/header.php';
page_header('Deliveries', 'A delivery note is created from a confirmed order. It is picked, packed, dispatched and confirmed by the customer — each step is recorded.');
nav_sales();
?>
<div class="card flush">
  <div style="padding:16px 16px 0;">
    <div class="filter-bar"><div class="chip-group">
      <a class="chip <?= $status === '' && $filter === '' ? 'active' : '' ?>" href="<?= e(query_with(['status' => null, 'filter' => null, 'page' => null])) ?>">All</a>
      <?php foreach ($statuses as $k => $l): ?><a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(query_with(['status' => $k, 'filter' => null, 'page' => null])) ?>"><?= $l ?> <span class="chip-count"><?= $counts[$k] ?? 0 ?></span></a><?php endforeach; ?>
      <a class="chip <?= $filter === 'uninvoiced' ? 'active' : '' ?>" href="<?= e(query_with(['filter' => 'uninvoiced', 'status' => null, 'page' => null])) ?>">Not invoiced <span class="chip-count"><?= $uninv ?></span></a>
      <a class="chip <?= $filter === 'discrepancy' ? 'active' : '' ?>" href="<?= e(query_with(['filter' => 'discrepancy', 'status' => null, 'page' => null])) ?>">Shortages</a>
    </div></div>
    <form method="get" class="filter-bar">
      <?php foreach (['status' => $status, 'filter' => $filter] as $k => $v) if ($v) echo '<input type="hidden" name="' . $k . '" value="' . e($v) . '">'; ?>
      <div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Delivery note, order or customer"></div>
      <select class="form-control" name="wh" onchange="this.form.submit()"><option value="">All warehouses</option><?php foreach ($whs as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $wh === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select>
    </form>
  </div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Delivery note</th><th>Order</th><th>Customer</th><th>Warehouse</th><th>Planned</th><th class="num">Units</th><th>Driver / vehicle</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $d): $late = in_array($d['status'], ['pending_pick', 'picked', 'packed'], true) && $d['planned_date'] && $d['planned_date'] < date('Y-m-d'); ?>
      <tr>
        <td class="strong"><a href="<?= e(app_url('/sales/delivery_view.php?id=' . $d['id'])) ?>"><?= e($d['dn_no']) ?></a></td>
        <td><a href="<?= e(app_url('/sales/order_view.php?id=' . $d['so_id'])) ?>"><?= e($d['so_no']) ?></a></td>
        <td><?= e($d['business_name']) ?></td>
        <td class="text-secondary"><?= e($d['wh_code']) ?></td>
        <td class="nowrap <?= $late ? 'text-error fw-700' : 'text-secondary' ?>"><?= e(fmt_date($d['planned_date'])) ?><?= $late ? ' · late' : '' ?></td>
        <td class="num"><?= fmt_qty($d['units']) ?></td>
        <td class="text-secondary text-sm"><?= e($d['driver'] ?? '—') ?><?= $d['reg_no'] ? '<div class="cell-sub">' . e($d['reg_no']) . '</div>' : '' ?></td>
        <td><?= status_badge($d['status']) ?><?= $d['has_discrepancy'] ? ' <span class="badge badge-warning">Shortage</span>' : '' ?></td>
        <td class="actions"><a class="btn btn-<?= in_array($d['status'], ['pending_pick', 'picked', 'packed', 'dispatched'], true) ? 'primary' : 'secondary' ?> btn-sm" href="<?= e(app_url('/sales/delivery_view.php?id=' . $d['id'])) ?>"><?= ['pending_pick' => 'Pick', 'picked' => 'Pack / dispatch', 'packed' => 'Dispatch', 'dispatched' => 'Confirm'][$d['status']] ?? 'View' ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows) echo empty_state('truck', 'No deliveries', 'Delivery notes appear here once confirmed orders are released to the warehouse.'); ?></div>
  <?php render_pagination($page, $perPage, $total); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
