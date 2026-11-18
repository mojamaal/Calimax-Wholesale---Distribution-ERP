<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('inventory.view');

$pdo = db();
$id = input_int($_GET, 'id');
$s = $pdo->prepare('SELECT st.*, f.name AS from_name, t.name AS to_name, cu.full_name AS creator, su.full_name AS sender, ru.full_name AS receiver FROM stock_transfers st JOIN warehouses f ON f.id = st.from_wh JOIN warehouses t ON t.id = st.to_wh LEFT JOIN users cu ON cu.id = st.created_by LEFT JOIN users su ON su.id = st.sent_by LEFT JOIN users ru ON ru.id = st.received_by WHERE st.id = :id');
$s->execute([':id' => $id]); $t = $s->fetch();
if (!$t) { flash('error', 'Transfer not found.'); redirect('/warehouse/transfers.php'); }
$items = $pdo->prepare('SELECT sti.*, p.name, p.code, p.base_unit, b.batch_no, b.expiry_date FROM stock_transfer_items sti JOIN products p ON p.id = sti.product_id LEFT JOIN batches b ON b.id = sti.batch_id WHERE sti.transfer_id = :i ORDER BY sti.id'); $items->execute([':i' => $id]); $items = $items->fetchAll();
$serials = $pdo->prepare('SELECT sts.*, sn.serial_no, p.name FROM stock_transfer_serials sts JOIN serial_numbers sn ON sn.id = sts.serial_id JOIN products p ON p.id = sn.product_id WHERE sts.transfer_id = :i ORDER BY sn.serial_no'); $serials->execute([':i' => $id]); $serials = $serials->fetchAll();

if (is_post() && can('inventory.transfer')) {
    verify_csrf(); $a = input($_POST, 'action');
    try {
        if ($a === 'send') { transfer_send($id); flash('success', 'Transfer sent.'); }
        elseif ($a === 'receive') { transfer_receive($id, array_map('intval', $_POST['recv'] ?? []), array_map('intval', $_POST['missing'] ?? [])); flash('success', 'Transfer received into ' . $t['to_name'] . '.'); }
        elseif ($a === 'cancel' && $t['status'] === 'draft') { $pdo->prepare("UPDATE stock_transfers SET status = 'cancelled' WHERE id = :id")->execute([':id' => $id]); log_activity('transfer_create', 'Cancelled ' . $t['tr_no'], 'stock_transfer', $id); flash('success', 'Transfer cancelled.'); }
    } catch (RuntimeException $e) { flash('error', $e->getMessage()); }
    redirect('/warehouse/transfer_view.php?id=' . $id);
}
$cur = ['draft' => 'draft', 'in_transit' => 'sent', 'received' => 'received', 'cancelled' => 'draft'][$t['status']];
$pageTitle = $t['tr_no'];
require __DIR__ . '/../includes/header.php';
page_header($t['tr_no'], e($t['from_name']) . ' → ' . e($t['to_name']) . ' · ' . status_badge($t['status']), '', [['Transfers', '/warehouse/transfers.php'], [$t['tr_no'], null]]);
echo workflow_steps(['draft' => 'Prepared', 'sent' => 'Sent (in transit)', 'received' => 'Received'], $cur);
if ($t['status'] === 'draft') echo next_step('send', 'Draft transfer', 'Send it when the goods leave ' . e($t['from_name']) . '. Stock is deducted from the source on sending.', can('inventory.transfer') ? '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="send"><button class="btn btn-primary" type="submit">' . icon('send', 16) . ' Send now</button></form><form method="post" class="inline-form" data-confirm="Cancel this draft?">' . csrf_field() . '<input type="hidden" name="action" value="cancel"><button class="btn btn-secondary" type="submit">Cancel</button></form>' : '', 'info');
elseif ($t['status'] === 'in_transit') echo next_step('truck', 'In transit to ' . e($t['to_name']), 'Count the goods on arrival and confirm what was received. Any shortage is written off.', '', 'warn');
elseif ($t['status'] === 'received') echo next_step('check-circle-2', 'Transfer complete', 'Received by ' . e($t['receiver'] ?? '') . ' on ' . e(fmt_datetime($t['received_at'])) . '.', '', 'good');
$receiving = $t['status'] === 'in_transit' && can('inventory.transfer');
?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="receive">
<div class="card flush mb-4"><div class="card-head"><h3>Items</h3><span class="text-muted text-sm">Created by <?= e($t['creator'] ?? '—') ?> · <?= e(fmt_date($t['transfer_date'])) ?></span></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Batch</th><th class="num">Sent</th><th class="num" <?= $receiving ? 'style="width:160px;"' : '' ?>>Received</th></tr></thead><tbody>
  <?php foreach ($items as $i): ?><tr><td><a class="strong" href="<?= e(app_url('/catalog/product_view.php?id=' . $i['product_id'])) ?>"><?= e($i['name']) ?></a><div class="cell-sub mono"><?= e($i['code']) ?></div></td><td class="mono"><?= $i['batch_no'] ? '<a class="link" href="' . e(app_url('/trace/batch_view.php?id=' . $i['batch_id'])) . '">' . e($i['batch_no']) . '</a>' . ($i['expiry_date'] ? '<div class="cell-sub">exp ' . e(fmt_date($i['expiry_date'])) . '</div>' : '') : '—' ?></td><td class="num strong"><?= fmt_qty($i['qty_sent']) ?> <span class="text-muted text-sm"><?= e($i['base_unit']) ?></span></td>
    <td class="num"><?php if ($receiving): ?><input class="form-control num-input" type="number" min="0" max="<?= (int) $i['qty_sent'] ?>" name="recv[<?= (int) $i['id'] ?>]" value="<?= (int) $i['qty_sent'] ?>"><?php elseif ($t['status'] === 'received'): ?><span class="<?= $i['qty_received'] < $i['qty_sent'] ? 'text-error strong' : 'text-success strong' ?>"><?= fmt_qty($i['qty_received']) ?></span><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
  <?php foreach ($serials as $sn): ?><tr><td><span class="strong"><?= e($sn['name']) ?></span> <span class="tag serial">serial</span></td><td colspan="2"><a class="sn-chip" style="padding:2px 9px;" href="<?= e(app_url('/trace/serial_view.php?id=' . $sn['serial_id'])) ?>"><?= e($sn['serial_no']) ?></a></td><td class="num"><?php if ($receiving): ?><label class="check" style="justify-content:flex-end;"><input type="checkbox" name="missing[]" value="<?= (int) $sn['serial_id'] ?>"> Missing</label><?php elseif ($t['status'] === 'received'): ?><?= $sn['received'] ? '<span class="text-success strong">Received</span>' : '<span class="text-error strong">Lost</span>' ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php if ($receiving): ?><div class="action-bar"><span class="text-muted text-sm">If you receive less than was sent, the difference is written off as a transit loss.</span><button class="btn btn-primary btn-lg" type="submit"><?= icon('package-check', 16) ?> Receive into <?= e($t['to_name']) ?></button></div><?php endif; ?>
</form>
<?php if ($t['notes']): ?><div class="callout"><?= e($t['notes']) ?></div><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
