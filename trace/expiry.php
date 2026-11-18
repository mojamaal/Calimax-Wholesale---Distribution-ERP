<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('trace.view');

$pdo = db();
$crit = (int) setting('expiry_critical_days', '30'); $warn = (int) setting('expiry_warning_days', '90');
$horizon = in_array(input_int($_GET, 'days', $warn), [30, 60, 90, 120, 180], true) ? input_int($_GET, 'days', $warn) : $warn;
$wh = input_int($_GET, 'wh');
$batches = expiring_batches($horizon, $wh ?: null);
$groups = ['expired' => ['Expired — block & write off', 'error', []], 'crit' => ["Expiring within $crit days", 'error', []], 'warn' => ['Expiring within 60 days', 'warning', []], 'soon' => ["Upcoming (up to $horizon days)", 'info', []]];
foreach ($batches as $b) {
    $d = (int) $b['days_left'];
    $k = $d < 0 ? 'expired' : ($d <= $crit ? 'crit' : ($d <= 60 ? 'warn' : 'soon'));
    $groups[$k][2][] = $b;
}
$value = function (array $list) { $v = 0; foreach ($list as $b) $v += ((int) $b['on_hand'] + (int) $b['quarantine']) * (float) $b['unit_cost']; return $v; };
$whs = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name')->fetchAll();
$showCost = can('products.view_cost');

$pageTitle = 'Expiry monitor';
require __DIR__ . '/../includes/header.php';
page_header('Expiry monitor', 'Stock close to its expiry date. Ship these batches first (FEFO) — or act before the money is wasted.');
nav_trace();
?>
<form method="get" class="filter-bar">
  <div class="chip-group"><?php foreach ([30, 60, 90, 120, 180] as $d): ?><a class="chip <?= $horizon === $d ? 'active' : '' ?>" href="<?= e(query_with(['days' => $d])) ?>">Next <?= $d ?> days</a><?php endforeach; ?></div>
  <select class="form-control" name="wh" onchange="this.form.submit()"><option value="">All warehouses</option><?php foreach ($whs as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $wh === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select><input type="hidden" name="days" value="<?= $horizon ?>">
</form>
<div class="grid grid-4 mb-4">
  <?php foreach ($groups as $k => [$l, $tone, $list]): ?><?= kpi($l, (string) count($list), $k === 'expired' ? 'calendar-x' : 'calendar-clock', $tone === 'error' ? 'red' : ($tone === 'warning' ? 'amber' : 'blue'), $showCost ? format_money_short($value($list)) . ' at cost' : 'batch(es) with stock') ?><?php endforeach; ?>
</div>
<?php foreach ($groups as $k => [$l, $tone, $list]): if (!$list) continue; ?>
<div class="card flush mb-4"><div class="card-head"><h3><?= e($l) ?></h3><span class="badge badge-<?= $tone ?>"><?= count($list) ?> batch(es)</span></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Batch</th><th>Supplier</th><th>Expiry</th><th class="num">Remaining</th><?php if ($showCost): ?><th class="num">Value at risk</th><?php endif; ?><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($list as $b): ?><tr><td class="strong"><?= e($b['product_name']) ?></td><td class="mono"><a class="link" href="<?= e(app_url('/trace/batch_view.php?id=' . $b['id'])) ?>"><?= e($b['batch_no']) ?></a></td><td class="text-secondary"><?= e($b['supplier_name'] ?? '—') ?></td><td class="nowrap"><?= e(fmt_date($b['expiry_date'])) ?><div class="cell-sub"><?= expiry_badge($b['expiry_date']) ?></div></td><td class="num strong"><?= fmt_qty($b['on_hand']) ?> <span class="text-muted text-sm"><?= e($b['base_unit']) ?></span><?= (int) $b['quarantine'] ? '<div class="cell-sub">+' . fmt_qty($b['quarantine']) . ' quarantine</div>' : '' ?></td><?php if ($showCost): ?><td class="num"><?= format_money(((int) $b['on_hand'] + (int) $b['quarantine']) * (float) $b['unit_cost']) ?></td><?php endif; ?><td><?= status_badge($b['status']) ?></td>
    <td class="actions"><?php if ($k === 'expired' && can('inventory.adjust')): ?><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/warehouse/adjustment_form.php?reason=expired')) ?>">Write off</a><?php endif; ?><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/trace/batch_view.php?id=' . $b['id'])) ?>">Open</a></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php endforeach; ?>
<?php if (!$batches): ?><div class="card"><?= empty_state('check-circle-2', 'Nothing is close to expiry', 'No batch with stock expires within the next ' . $horizon . ' days.') ?></div><?php endif; ?>
<div class="callout info mt-4 text-sm"><strong>How the system protects you:</strong> expired batches are blocked automatically from picking and dispatch. Under FEFO the picking screen always suggests the earliest-expiring batch first, so near-expiry stock ships before newer stock.</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
