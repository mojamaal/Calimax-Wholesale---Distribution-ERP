<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.view');

$pdo = db();
$status = input($_GET, 'status'); $q = input($_GET, 'q'); $sup = input_int($_GET, 'supplier_id');
$page = max(1, input_int($_GET, 'page', 1)); $perPage = 40;
$statuses = ['draft' => 'Draft', 'pending_approval' => 'Awaiting approval', 'approved' => 'Approved', 'sent' => 'Sent', 'partially_received' => 'Part received', 'received' => 'Received', 'closed' => 'Closed', 'cancelled' => 'Cancelled', 'rejected' => 'Rejected'];
$where = '1=1'; $params = [];
if ($status === 'open') $where .= " AND po.status IN ('approved','sent','partially_received')";
elseif (isset($statuses[$status])) { $where .= ' AND po.status = :st'; $params[':st'] = $status; }
if ($q !== '') { $where .= ' AND (po.po_no LIKE :q1 OR s.name LIKE :q2)'; $params += [':q1' => "%$q%", ':q2' => "%$q%"]; }
if ($sup) { $where .= ' AND po.supplier_id = :su'; $params[':su'] = $sup; }
$from = "FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id JOIN warehouses w ON w.id = po.warehouse_id WHERE $where";
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
$st = $pdo->prepare("SELECT po.*, s.name AS supplier, w.code AS wh, (SELECT COALESCE(SUM(qty),0) FROM purchase_order_items WHERE po_id = po.id) AS q, (SELECT COALESCE(SUM(received_qty),0) FROM purchase_order_items WHERE po_id = po.id) AS r $from ORDER BY FIELD(po.status,'pending_approval','partially_received','sent','approved','draft','received','closed','rejected','cancelled'), po.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params); $rows = $st->fetchAll();
$counts = []; foreach ($pdo->query('SELECT status, COUNT(*) n FROM purchase_orders GROUP BY status') as $r) $counts[$r['status']] = (int) $r['n'];
$onOrder = (float) $pdo->query("SELECT COALESCE(SUM((poi.qty - poi.received_qty) * poi.unit_cost),0) FROM purchase_order_items poi JOIN purchase_orders p ON p.id = poi.po_id WHERE p.status IN ('approved','sent','partially_received')")->fetchColumn();
$overdue = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('approved','sent','partially_received') AND expected_date < CURDATE()")->fetchColumn();
$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();
$pageTitle = 'Purchase orders';
require __DIR__ . '/../includes/header.php';
page_header('Procurement', 'Order from suppliers, approve large purchases and receive deliveries. Stock only increases when goods are received.', can('purchase.create') ? '<a class="btn btn-primary" href="' . e(app_url('/purchasing/order_form.php')) . '">' . icon('plus', 16) . ' New purchase order</a>' : '');
nav_purchasing();
?>
<div class="grid grid-4 mb-4">
  <?= kpi('Awaiting approval', (string) ($counts['pending_approval'] ?? 0), 'file-check', ($counts['pending_approval'] ?? 0) ? 'amber' : 'gray', 'Above the approval threshold', '/purchasing/orders.php?status=pending_approval') ?>
  <?= kpi('Open orders', (string) (($counts['approved'] ?? 0) + ($counts['sent'] ?? 0) + ($counts['partially_received'] ?? 0)), 'truck', 'blue', format_money_short($onOrder) . ' still to arrive', '/purchasing/orders.php?status=open') ?>
  <?= kpi('Overdue deliveries', (string) $overdue, 'clock-alert', $overdue ? 'red' : 'gray', 'Past expected date') ?>
  <?= kpi('Part received', (string) ($counts['partially_received'] ?? 0), 'package-open', 'amber', 'Short deliveries to follow up', '/purchasing/orders.php?status=partially_received') ?>
</div>
<div class="card flush">
  <div style="padding:16px 16px 0;"><div class="filter-bar"><div class="chip-group"><a class="chip <?= $status === '' ? 'active' : '' ?>" href="<?= e(query_with(['status' => null, 'page' => null])) ?>">All</a><a class="chip <?= $status === 'open' ? 'active' : '' ?>" href="<?= e(query_with(['status' => 'open', 'page' => null])) ?>">Open</a><?php foreach (['draft', 'pending_approval', 'partially_received', 'received', 'closed', 'cancelled'] as $k): ?><a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(query_with(['status' => $k, 'page' => null])) ?>"><?= $statuses[$k] ?> <span class="chip-count"><?= $counts[$k] ?? 0 ?></span></a><?php endforeach; ?></div></div>
    <form method="get" class="filter-bar"><?php if ($status) echo '<input type="hidden" name="status" value="' . e($status) . '">'; ?><div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="PO number or supplier"></div><select class="form-control" name="supplier_id" onchange="this.form.submit()"><option value="">All suppliers</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sup === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></form></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>PO</th><th>Supplier</th><th>Ordered</th><th>Expected</th><th style="width:170px;">Received</th><th class="num">Total</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $o): $pct = $o['q'] ? round($o['r'] / $o['q'] * 100) : 0; $late = in_array($o['status'], ['approved', 'sent', 'partially_received'], true) && $o['expected_date'] && $o['expected_date'] < date('Y-m-d'); ?>
    <tr><td class="strong"><a href="<?= e(app_url('/purchasing/order_view.php?id=' . $o['id'])) ?>"><?= e($o['po_no']) ?></a><div class="cell-sub"><?= e($o['wh']) ?></div></td><td><?= e($o['supplier']) ?></td><td class="text-secondary nowrap"><?= e(fmt_date($o['order_date'])) ?></td><td class="nowrap <?= $late ? 'text-error fw-700' : 'text-secondary' ?>"><?= e(fmt_date($o['expected_date'])) ?><?= $late ? ' · overdue' : '' ?></td>
      <td><div class="text-sm"><?= fmt_qty($o['r']) ?> / <?= fmt_qty($o['q']) ?> units<?= $o['status'] === 'partially_received' ? ' · <span class="text-warning">short ' . fmt_qty($o['q'] - $o['r']) . '</span>' : '' ?></div><div class="meter mt-1 <?= $pct >= 100 ? '' : 'warn' ?>"><span style="width:<?= $pct ?>%"></span></div></td><td class="num strong"><?= format_money($o['total']) ?></td><td><?= status_badge($o['status']) ?></td>
      <td class="actions"><?php if (in_array($o['status'], ['approved', 'sent', 'partially_received'], true) && can('purchase.receive')): ?><a class="btn btn-primary btn-sm" href="<?= e(app_url('/purchasing/receive.php?po_id=' . $o['id'])) ?>">Receive</a><?php endif; ?><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/purchasing/order_view.php?id=' . $o['id'])) ?>">View</a></td></tr><?php endforeach; ?></tbody></table>
  <?php if (!$rows) echo empty_state('file-text', 'No purchase orders', 'Create a purchase order to formally request goods from a supplier.'); ?></div><?php render_pagination($page, $perPage, $total); ?></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
