<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('inventory.view');

$pdo = db();
$id = input_int($_GET, 'id');
$s = $pdo->prepare('SELECT sa.*, w.name AS wh, ru.full_name AS requester, au.full_name AS approver FROM stock_adjustments sa JOIN warehouses w ON w.id = sa.warehouse_id LEFT JOIN users ru ON ru.id = sa.requested_by LEFT JOIN users au ON au.id = sa.approved_by WHERE sa.id = :id');
$s->execute([':id' => $id]); $a = $s->fetch();
if (!$a) { flash('error', 'Adjustment not found.'); redirect('/warehouse/adjustments.php'); }
if (is_post() && can('inventory.approve') && $a['status'] === 'pending') {
    verify_csrf(); $act = input($_POST, 'action');
    try {
        if ($act === 'approve') { $pdo->beginTransaction(); adjustment_apply($pdo, $id, (int) current_user()['id']); $pdo->commit(); log_activity('stock_adjustment_approve', $a['adj_no'] . ' approved and applied', 'stock_adjustment', $id); flash('success', 'Adjustment approved — stock updated.'); }
        elseif ($act === 'reject') { $pdo->prepare("UPDATE stock_adjustments SET status = 'rejected', approved_by = :u, approved_at = NOW() WHERE id = :id")->execute([':u' => current_user()['id'], ':id' => $id]); log_activity('stock_adjustment_reject', $a['adj_no'] . ' rejected', 'stock_adjustment', $id); flash('success', 'Adjustment rejected. Stock is unchanged.'); }
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash('error', $e->getMessage()); }
    redirect('/warehouse/adjustment_view.php?id=' . $id);
}
$items = $pdo->prepare('SELECT sai.*, p.name, p.code, p.base_unit, b.batch_no, sn.serial_no FROM stock_adjustment_items sai JOIN products p ON p.id = sai.product_id LEFT JOIN batches b ON b.id = sai.batch_id LEFT JOIN serial_numbers sn ON sn.id = sai.serial_id WHERE sai.adjustment_id = :i ORDER BY sai.id'); $items->execute([':i' => $id]); $items = $items->fetchAll();
$estimate = 0; foreach ($items as $i) $estimate += (float) $i['qty_change'] * (float) $i['unit_cost'] * (in_array($a['reason'], ['damaged', 'restore_damaged'], true) ? 0 : 1);
$pageTitle = $a['adj_no'];
require __DIR__ . '/../includes/header.php';
page_header($a['adj_no'], e($a['wh']) . ' · ' . e(adjustment_reason_label($a['reason'])) . ' · ' . status_badge($a['status']), '', [['Adjustments', '/warehouse/adjustments.php'], [$a['adj_no'], null]]);
if ($a['status'] === 'pending') echo next_step('clipboard-check', 'Awaiting approval', 'Stock does not change until the warehouse manager approves. Estimated value effect: <strong>' . format_money($estimate) . '</strong>.', can('inventory.approve') ? '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="approve"><button class="btn btn-primary" type="submit">' . icon('check', 16) . ' Approve &amp; apply</button></form><form method="post" class="inline-form" data-confirm="Reject this adjustment?">' . csrf_field() . '<input type="hidden" name="action" value="reject"><button class="btn btn-secondary" type="submit">Reject</button></form>' : '', 'warn');
elseif ($a['status'] === 'approved') echo next_step('check-circle-2', 'Applied', 'Approved by ' . e($a['approver'] ?? '') . ' on ' . e(fmt_datetime($a['approved_at'])) . '. Value effect on inventory: <strong>' . format_money($a['value_change']) . '</strong>.', '', 'good');
else echo next_step('x-circle', 'Rejected', 'No stock was changed.', '', 'bad');
?>
<div class="layout-2-1"><div class="card flush"><div class="card-head"><h3>Items</h3></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Batch / serial</th><th class="num">Change</th><th class="num">Unit cost</th><th>Note</th></tr></thead><tbody>
<?php foreach ($items as $i): ?><tr><td><span class="strong"><?= e($i['name']) ?></span><div class="cell-sub mono"><?= e($i['code']) ?></div></td><td class="mono text-sm"><?= e($i['batch_no'] ?? '') ?><?= e($i['serial_no'] ?? '') ?><?= !$i['batch_no'] && !$i['serial_no'] ? '—' : '' ?></td><td class="num strong <?= $i['qty_change'] < 0 ? 'text-error' : 'text-success' ?>"><?= $i['qty_change'] > 0 ? '+' : '' ?><?= fmt_qty($i['qty_change']) ?></td><td class="num text-secondary"><?= format_money($i['unit_cost']) ?></td><td class="text-secondary text-sm"><?= e($i['note'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="card"><h3 class="mb-3">Details</h3><dl class="desc-list"><dt>Warehouse</dt><dd><?= e($a['wh']) ?></dd><dt>Reason</dt><dd><?= e(adjustment_reason_label($a['reason'])) ?></dd><dt>Requested by</dt><dd><?= e($a['requester'] ?? '—') ?></dd><dt>Date</dt><dd><?= e(fmt_datetime($a['created_at'])) ?></dd><?php if ($a['source'] === 'count' && $a['count_id']): ?><dt>From count</dt><dd><a class="link" href="<?= e(app_url('/warehouse/count_view.php?id=' . $a['count_id'])) ?>">View count</a></dd><?php endif; ?></dl><?php if ($a['note']): ?><div class="callout mt-3"><?= e($a['note']) ?></div><?php endif; ?></div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
