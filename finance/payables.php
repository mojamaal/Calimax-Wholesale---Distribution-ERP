<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('finance.view');

$pdo = db();
$bills = ap_open_bills();
$labels = ageing_labels(); $bySup = []; $totals = array_fill_keys(array_keys($labels), 0.0);
foreach ($bills as $b) {
    $s = &$bySup[$b['supplier_id']]; if (!$s) $s = ['name' => $b['supplier_name'], 'b' => array_fill_keys(array_keys($labels), 0.0), 'total' => 0.0, 'oldest' => 0, 'n' => 0, 'next' => $b['due_date']];
    $s['b'][$b['bucket']] += (float) $b['outstanding']; $s['total'] += (float) $b['outstanding']; $s['oldest'] = max($s['oldest'], (int) $b['days_overdue']); $s['n']++; $totals[$b['bucket']] += (float) $b['outstanding']; unset($s);
}
uasort($bySup, fn($a, $b) => $b['total'] <=> $a['total']);
$grand = array_sum($totals);
$dueWeek = (float) $pdo->query("SELECT COALESCE(SUM(total - paid_amount),0) FROM supplier_invoices WHERE status IN ('unpaid','partial') AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
$glAp = account_balance('2000'); $grni = account_balance('2100');
$pageTitle = 'Payables';
require __DIR__ . '/../includes/header.php';
page_header('Accounts payable', 'What you owe suppliers, by due date — plan payments before they become overdue.', '<button class="btn btn-secondary" onclick="window.print()">' . icon('printer', 16) . ' Print</button>' . (can('supplier.pay') ? '<a class="btn btn-primary" href="' . e(app_url('/purchasing/payment_form.php')) . '">' . icon('banknote', 16) . ' Pay supplier</a>' : ''));
nav_finance();
?>
<div class="grid grid-4 mb-4"><?= kpi('We owe suppliers', format_money_short(payables_total()), 'file-clock', 'red', count($bySup) . ' supplier(s) · GL control ' . format_money_short($glAp)) ?><?= kpi('Overdue', format_money_short($grand - $totals['current']), 'clock-alert', 'red', 'Past the due date') ?><?= kpi('Due in 7 days', format_money_short($dueWeek), 'calendar-clock', 'amber', 'Plan these payments') ?><?= kpi('Received, not invoiced', format_money_short($grni), 'package-check', 'blue', 'Goods awaiting a supplier invoice', '/purchasing/receipts.php?filter=uninvoiced') ?></div>
<div class="card mb-4"><div class="card-head"><h3>Ageing summary</h3></div><div class="age-bar mb-3"><?php foreach (array_keys($labels) as $i => $k): if ($totals[$k] > 0): ?><span class="age-<?= $i ?>" style="width:<?= $totals[$k] / max($grand, 1) * 100 ?>%"></span><?php endif; endforeach; ?></div><div class="age-legend"><?php foreach ($labels as $k => $l): $i = array_search($k, array_keys($labels)); ?><span><i class="age-<?= $i ?>"></i><?= e($l) ?> <strong><?= format_money_short($totals[$k]) ?></strong></span><?php endforeach; ?></div></div>
<div class="card flush"><div class="table-wrap"><table class="data-table"><thead><tr><th>Supplier</th><?php foreach ($labels as $l): ?><th class="num"><?= e($l) ?></th><?php endforeach; ?><th class="num">Total</th><th>Next due</th><th></th></tr></thead><tbody>
<?php foreach ($bySup as $sid => $s): ?><tr><td><a class="strong" href="<?= e(app_url('/purchasing/supplier_view.php?id=' . $sid)) ?>"><?= e($s['name']) ?></a><div class="cell-sub"><?= (int) $s['n'] ?> invoice(s)</div></td><?php foreach (array_keys($labels) as $i => $k): ?><td class="num <?= $s['b'][$k] > 0 && $i >= 1 ? 'text-error' : ($s['b'][$k] > 0 ? '' : 'text-muted') ?>"><?= $s['b'][$k] > 0 ? format_money($s['b'][$k]) : '—' ?></td><?php endforeach; ?><td class="num strong"><?= format_money($s['total']) ?></td><td class="text-secondary nowrap"><?= e(fmt_date($s['next'])) ?></td><td class="actions"><?php if (can('supplier.pay')): ?><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/purchasing/payment_form.php?supplier_id=' . $sid)) ?>">Pay</a><?php endif; ?></td></tr><?php endforeach; ?></tbody>
<tfoot><tr><td>Total</td><?php foreach (array_keys($labels) as $k): ?><td class="num"><?= format_money($totals[$k]) ?></td><?php endforeach; ?><td class="num"><?= format_money($grand) ?></td><td colspan="2"></td></tr></tfoot></table>
<?php if (!$bySup) echo empty_state('check-circle-2', 'Nothing owed', 'All supplier invoices are paid.'); ?></div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
