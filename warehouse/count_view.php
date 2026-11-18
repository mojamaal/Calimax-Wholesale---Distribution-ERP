<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('inventory.view');

$pdo = db();
$id = input_int($_GET, 'id');
$s = $pdo->prepare('SELECT sc.*, w.name AS wh, u.full_name AS who, ap.full_name AS approver FROM stock_counts sc JOIN warehouses w ON w.id = sc.warehouse_id LEFT JOIN users u ON u.id = sc.created_by LEFT JOIN users ap ON ap.id = sc.approved_by WHERE sc.id = :id');
$s->execute([':id' => $id]); $c = $s->fetch();
if (!$c) { flash('error', 'Count not found.'); redirect('/warehouse/counts.php'); }
if (is_post()) {
    verify_csrf(); $a = input($_POST, 'action');
    try {
        if (in_array($a, ['save', 'submit'], true) && can('inventory.count') && $c['status'] === 'open') {
            foreach ($_POST['counted'] ?? [] as $iid => $v) { $pdo->prepare('UPDATE stock_count_items SET counted_qty = :q WHERE id = :id AND count_id = :c')->execute([':q' => $v === '' ? null : max(0, (int) $v), ':id' => (int) $iid, ':c' => $id]); }
            if ($a === 'submit') {
                $miss = (int) $pdo->query("SELECT COUNT(*) FROM stock_count_items WHERE count_id = $id AND counted_qty IS NULL")->fetchColumn();
                if ($miss && !isset($_POST['confirm_blank'])) throw new RuntimeException("$miss line(s) have no counted quantity. Tick “Treat blanks as not counted” to submit anyway.");
                $pdo->prepare("UPDATE stock_counts SET status = 'submitted', submitted_at = NOW() WHERE id = :id")->execute([':id' => $id]); log_activity('stock_count_submit', 'Submitted ' . $c['count_no'], 'stock_count', $id); flash('success', 'Count submitted for approval.');
            } else flash('success', 'Progress saved.');
        } elseif ($a === 'approve' && can('inventory.approve')) { $adj = count_approve($id); flash('success', $adj ? 'Count approved — variances applied as a stock adjustment.' : 'Count approved — no variances.'); }
        elseif ($a === 'reopen' && can('inventory.approve') && $c['status'] === 'submitted') { $pdo->prepare("UPDATE stock_counts SET status = 'open' WHERE id = :id")->execute([':id' => $id]); flash('success', 'Count re-opened for recounting.'); }
        elseif ($a === 'cancel' && can('inventory.count') && in_array($c['status'], ['open', 'submitted'], true)) { $pdo->prepare("UPDATE stock_counts SET status = 'cancelled' WHERE id = :id")->execute([':id' => $id]); flash('success', 'Count cancelled.'); }
    } catch (RuntimeException $e) { flash('error', $e->getMessage()); }
    redirect('/warehouse/count_view.php?id=' . $id);
}
$items = $pdo->prepare('SELECT sci.*, p.name, p.code, p.base_unit, p.avg_cost, b.batch_no, b.expiry_date FROM stock_count_items sci JOIN products p ON p.id = sci.product_id LEFT JOIN batches b ON b.id = sci.batch_id WHERE sci.count_id = :c ORDER BY p.name, b.expiry_date');
$items->execute([':c' => $id]); $items = $items->fetchAll();
$counted = count(array_filter($items, fn($i) => $i['counted_qty'] !== null)); $var = 0; $varVal = 0;
foreach ($items as $i) if ($i['counted_qty'] !== null && (int) $i['counted_qty'] !== (int) $i['system_qty']) { $var++; $varVal += ((int) $i['counted_qty'] - (int) $i['system_qty']) * (float) $i['avg_cost']; }
$editable = $c['status'] === 'open' && can('inventory.count');
$pageTitle = $c['count_no'];
require __DIR__ . '/../includes/header.php';
page_header($c['count_no'], e($c['wh']) . ' · ' . status_badge($c['status']), '', [['Stock counts', '/warehouse/counts.php'], [$c['count_no'], null]]);
echo workflow_steps(['open' => 'Counting', 'submitted' => 'Submitted', 'approved' => 'Approved & adjusted'], $c['status'] === 'cancelled' ? 'open' : $c['status'], $c['status'] === 'cancelled');
if ($c['status'] === 'submitted') echo next_step('clipboard-check', $var . ' variance(s) awaiting approval', 'Estimated value effect <strong>' . format_money($varVal) . '</strong>. Approving applies the differences as a stock adjustment.', can('inventory.approve') ? '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="approve"><button class="btn btn-primary" type="submit">' . icon('check', 16) . ' Approve &amp; adjust stock</button></form><form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="reopen"><button class="btn btn-secondary" type="submit">Recount</button></form>' : '', 'warn');
elseif ($c['status'] === 'open') echo next_step('list-checks', 'Counting in progress', 'Enter the quantity you counted for each line. “System” is the quantity the computer expects.', '', 'info');
elseif ($c['status'] === 'approved') echo next_step('check-circle-2', 'Count approved', 'Approved by ' . e($c['approver'] ?? '') . ' on ' . e(fmt_datetime($c['approved_at'])) . '.', '', 'good');
?>
<form method="post"><?= csrf_field() ?>
<div class="stat-strip"><div><div class="sl">Lines</div><div class="sv"><?= count($items) ?></div></div><div><div class="sl">Counted</div><div class="sv"><?= $counted ?></div></div><div><div class="sl">Variances</div><div class="sv <?= $var ? 'text-warning' : '' ?>"><?= $var ?></div></div><div><div class="sl">Variance value</div><div class="sv <?= $varVal < 0 ? 'text-error' : '' ?>"><?= format_money_short($varVal) ?></div></div></div>
<div class="card flush"><div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Batch</th><th class="num">System</th><th class="num" style="width:160px;">Counted</th><th class="num">Variance</th></tr></thead><tbody>
<?php foreach ($items as $i): $v = $i['counted_qty'] === null ? null : (int) $i['counted_qty'] - (int) $i['system_qty']; ?>
  <tr class="<?= $v ? 'row-warn' : '' ?>"><td><span class="strong"><?= e($i['name']) ?></span><div class="cell-sub mono"><?= e($i['code']) ?> · <?= e($i['base_unit']) ?></div></td><td class="mono text-sm"><?= e($i['batch_no'] ?? '—') ?><?= $i['expiry_date'] ? '<div class="cell-sub">exp ' . e(fmt_date($i['expiry_date'])) . '</div>' : '' ?></td><td class="num"><?= fmt_qty($i['system_qty']) ?></td>
  <td class="num"><?php if ($editable): ?><input class="form-control num-input" type="number" min="0" name="counted[<?= (int) $i['id'] ?>]" value="<?= $i['counted_qty'] === null ? '' : (int) $i['counted_qty'] ?>"><?php else: ?><?= $i['counted_qty'] === null ? '—' : fmt_qty($i['counted_qty']) ?><?php endif; ?></td>
  <td class="num strong <?= $v === null ? 'text-muted' : ($v < 0 ? 'text-error' : ($v > 0 ? 'text-success' : 'text-muted')) ?>"><?= $v === null ? '—' : ($v > 0 ? '+' : '') . fmt_qty($v) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php if ($editable): ?><div class="action-bar"><label class="check"><input type="checkbox" name="confirm_blank"> Treat blanks as not counted</label><div class="flex gap-2"><button class="btn btn-secondary" type="submit" name="action" value="save" data-no-busy>Save progress</button><button class="btn btn-primary btn-lg" type="submit" name="action" value="submit" data-no-busy><?= icon('send', 16) ?> Submit for approval</button></div></div><?php endif; ?>
</form>
<?php require __DIR__ . '/../includes/footer.php'; ?>
