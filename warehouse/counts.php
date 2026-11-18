<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('inventory.view');

$pdo = db();
if (is_post() && can('inventory.count')) {
    verify_csrf();
    try { $id = count_create(input_int($_POST, 'warehouse_id'), input($_POST, 'note')); flash('success', 'Count sheet created. Enter the quantities you count.'); redirect('/warehouse/count_view.php?id=' . $id); }
    catch (RuntimeException $e) { flash('error', $e->getMessage()); redirect('/warehouse/counts.php'); }
}
$status = input($_GET, 'status');
$where = in_array($status, ['open', 'submitted', 'approved', 'cancelled'], true) ? 'WHERE sc.status = ' . $pdo->quote($status) : '';
$rows = $pdo->query("SELECT sc.*, w.name AS wh, u.full_name AS who, (SELECT COUNT(*) FROM stock_count_items WHERE count_id = sc.id) AS n_lines, (SELECT COUNT(*) FROM stock_count_items WHERE count_id = sc.id AND counted_qty IS NOT NULL AND counted_qty <> system_qty) AS variances
    FROM stock_counts sc JOIN warehouses w ON w.id = sc.warehouse_id LEFT JOIN users u ON u.id = sc.created_by $where ORDER BY FIELD(sc.status,'submitted','open','approved','cancelled'), sc.id DESC LIMIT 60")->fetchAll();
$whs = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY id')->fetchAll();
$pageTitle = 'Stock counts';
require __DIR__ . '/../includes/header.php';
page_header('Physical stock counts', 'Count what is on the shelves and compare it with the system. Approved variances become stock adjustments automatically.', can('inventory.count') ? '<button class="btn btn-primary" type="button" data-modal-open="#newCount">' . icon('plus', 16) . ' Start a count</button>' : '');
nav_warehouse();
?>
<div class="chip-group mb-4"><a class="chip <?= $status === '' ? 'active' : '' ?>" href="<?= e(query_with(['status' => null])) ?>">All</a><?php foreach (['open' => 'In progress', 'submitted' => 'Awaiting approval', 'approved' => 'Approved'] as $k => $l): ?><a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(query_with(['status' => $k])) ?>"><?= $l ?></a><?php endforeach; ?></div>
<div class="card flush"><div class="table-wrap"><table class="data-table"><thead><tr><th>Count</th><th>Warehouse</th><th>Started</th><th>By</th><th class="num">Lines</th><th class="num">Variances</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $c): ?><tr><td class="strong"><a href="<?= e(app_url('/warehouse/count_view.php?id=' . $c['id'])) ?>"><?= e($c['count_no']) ?></a><?= $c['note'] ? '<div class="cell-sub">' . e($c['note']) . '</div>' : '' ?></td><td><?= e($c['wh']) ?></td><td class="text-secondary nowrap"><?= e(fmt_date($c['created_at'])) ?></td><td class="text-secondary"><?= e($c['who'] ?? '—') ?></td><td class="num"><?= (int) $c['n_lines'] ?></td><td class="num <?= $c['variances'] ? 'text-warning strong' : 'text-muted' ?>"><?= (int) $c['variances'] ?: '—' ?></td><td><?= status_badge($c['status']) ?></td><td class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/warehouse/count_view.php?id=' . $c['id'])) ?>">Open</a></td></tr><?php endforeach; ?></tbody></table>
<?php if (!$rows) echo empty_state('list-checks', 'No counts yet', 'Start a count to compare shelf quantities with the system.'); ?></div></div>
<?php if (can('inventory.count')): ?><div class="modal-overlay" id="newCount" hidden><div class="modal sm"><div class="modal-header"><h3>Start a stock count</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><div class="form-group"><label>Warehouse</label><select class="form-control" name="warehouse_id"><?php foreach ($whs as $w): ?><option value="<?= (int) $w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Note <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="note" maxlength="255" placeholder="e.g. Monthly cycle count"></div>
    <p class="text-secondary text-sm">A sheet is created from current stock. Serial-numbered goods are verified by scanning and are not part of quantity counts.</p><div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Create count sheet</button></div></form></div></div><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
