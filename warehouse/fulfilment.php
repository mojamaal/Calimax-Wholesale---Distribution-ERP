<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('delivery.view');

$pdo = db();
$wh = input_int($_GET, 'wh');
if (is_post() && can_any(['delivery.manage', 'delivery.fulfil'])) {
    verify_csrf();
    try {
        $soId = input_int($_POST, 'so_id');
        $qtys = []; foreach (so_line_balances($soId) as $l) $qtys[$l['id']] = $l['to_plan'];
        $dn = delivery_create($soId, $qtys);
        flash('success', 'Delivery note created — pick the goods now.');
        redirect('/sales/delivery_view.php?id=' . $dn);
    } catch (RuntimeException $e) { flash('error', $e->getMessage()); redirect('/warehouse/fulfilment.php'); }
}
$w = $wh ? ' AND d.warehouse_id = ' . $wh : ''; $ws = $wh ? ' AND so.warehouse_id = ' . $wh : '';
$ready = $pdo->query("SELECT so.*, c.business_name, wh.code AS wh_code, (SELECT COALESCE(SUM(qty - qty_dispatched - qty_cancelled),0) FROM sales_order_items WHERE so_id = so.id) AS remaining,
    (SELECT COUNT(*) FROM sales_order_items WHERE so_id = so.id) AS n_lines FROM sales_orders so JOIN customers c ON c.id = so.customer_id JOIN warehouses wh ON wh.id = so.warehouse_id
    WHERE so.status IN ('confirmed','partially_fulfilled') AND NOT EXISTS (SELECT 1 FROM deliveries d WHERE d.so_id = so.id AND d.status IN ('pending_pick','picked','packed')) $ws ORDER BY so.required_date IS NULL, so.required_date, so.id")->fetchAll();
function del_rows(string $status, string $w): array {
    return db()->query("SELECT d.*, so.so_no, c.business_name, wh.code AS wh_code, dr.name AS driver, (SELECT COALESCE(SUM(IF(d.status = 'pending_pick', qty_planned, qty_picked)),0) FROM delivery_items WHERE delivery_id = d.id) AS units,
        (SELECT COUNT(*) FROM delivery_items WHERE delivery_id = d.id) AS n_lines FROM deliveries d JOIN sales_orders so ON so.id = d.so_id JOIN customers c ON c.id = d.customer_id JOIN warehouses wh ON wh.id = d.warehouse_id LEFT JOIN drivers dr ON dr.id = d.driver_id
        WHERE d.status IN ($status) $w ORDER BY d.planned_date IS NULL, d.planned_date, d.id")->fetchAll();
}
$toPick = del_rows("'pending_pick'", $w); $packing = del_rows("'picked','packed'", $w); $transit = del_rows("'dispatched'", $w);
$whs = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name')->fetchAll();
$today = date('Y-m-d');

$pageTitle = 'Fulfilment queue';
require __DIR__ . '/../includes/header.php';
page_header('Warehouse fulfilment', 'Your work list: release confirmed orders, pick, pack, dispatch and confirm delivery.', '');
nav_warehouse();
?>
<form method="get" class="filter-bar"><select class="form-control" name="wh" onchange="this.form.submit()"><option value="">All warehouses</option><?php foreach ($whs as $x): ?><option value="<?= (int) $x['id'] ?>" <?= $wh === (int) $x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></form>
<div class="pipeline mb-4">
  <a href="#ready"><div class="pn"><?= count($ready) ?></div><div class="pl">Orders to release</div></a><a href="#pick"><div class="pn"><?= count($toPick) ?></div><div class="pl">To pick</div></a><a href="#pack"><div class="pn"><?= count($packing) ?></div><div class="pl">Picked — to dispatch</div></a><a href="#transit"><div class="pn"><?= count($transit) ?></div><div class="pl">In transit</div></a>
</div>

<div class="card flush mb-4" id="ready"><div class="card-head"><div><h3>1 · Confirmed orders — create delivery note</h3><div class="sub">Stock is already reserved for these orders.</div></div></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Order</th><th>Customer</th><th>Needed by</th><th>Warehouse</th><th class="num">Units left</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($ready as $o): $late = $o['required_date'] && $o['required_date'] < $today; ?>
    <tr><td class="strong"><a href="<?= e(app_url('/sales/order_view.php?id=' . $o['id'])) ?>"><?= e($o['so_no']) ?></a></td><td><?= e($o['business_name']) ?></td><td class="nowrap <?= $late ? 'text-error fw-700' : 'text-secondary' ?>"><?= e(fmt_date($o['required_date'])) ?><?= $late ? ' · late' : '' ?></td><td class="text-secondary"><?= e($o['wh_code']) ?></td><td class="num"><?= fmt_qty($o['remaining']) ?></td><td><?= status_badge($o['status']) ?></td>
      <td class="actions"><?php if (can_any(['delivery.manage', 'delivery.fulfil'])): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="so_id" value="<?= (int) $o['id'] ?>"><button class="btn btn-primary btn-sm" type="submit">Create delivery &amp; pick</button></form><?php endif; ?><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/sales/order_view.php?id=' . $o['id'])) ?>">Partial…</a></td></tr>
  <?php endforeach; ?></tbody></table><?php if (!$ready) echo empty_state('check-circle-2', 'Nothing waiting', 'Every confirmed order already has a delivery note.'); ?></div></div>

<?php foreach ([['pick', '2 · To pick', 'Pick the goods and confirm quantities, batches and serial numbers.', $toPick, 'Pick'], ['pack', '3 · Picked — pack and dispatch', 'Check the packed goods and hand them to the driver.', $packing, 'Pack / dispatch'], ['transit', '4 · In transit — awaiting delivery confirmation', 'Confirm when the customer has received the goods (record any shortage).', $transit, 'Confirm']] as [$anchor, $title, $sub, $rows, $btn]): ?>
<div class="card flush mb-4" id="<?= $anchor ?>"><div class="card-head"><div><h3><?= e($title) ?></h3><div class="sub"><?= e($sub) ?></div></div></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Delivery note</th><th>Order</th><th>Customer</th><th>Warehouse</th><th>Planned</th><th class="num">Lines</th><th class="num">Units</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $d): $late = $d['planned_date'] && $d['planned_date'] < $today && $d['status'] !== 'dispatched'; ?>
    <tr><td class="strong"><a href="<?= e(app_url('/sales/delivery_view.php?id=' . $d['id'])) ?>"><?= e($d['dn_no']) ?></a></td><td><a href="<?= e(app_url('/sales/order_view.php?id=' . $d['so_id'])) ?>"><?= e($d['so_no']) ?></a></td><td><?= e($d['business_name']) ?></td><td class="text-secondary"><?= e($d['wh_code']) ?></td><td class="nowrap <?= $late ? 'text-error fw-700' : 'text-secondary' ?>"><?= e(fmt_date($d['planned_date'])) ?></td><td class="num"><?= (int) $d['n_lines'] ?></td><td class="num"><?= fmt_qty($d['units']) ?></td><td><?= status_badge($d['status']) ?><?= $d['driver'] ? '<div class="cell-sub">' . e($d['driver']) . '</div>' : '' ?></td>
      <td class="actions"><a class="btn btn-primary btn-sm" href="<?= e(app_url('/sales/delivery_view.php?id=' . $d['id'])) ?>"><?= e($btn) ?></a></td></tr>
  <?php endforeach; ?></tbody></table><?php if (!$rows) echo empty_state('inbox', 'Nothing here'); ?></div></div>
<?php endforeach; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
