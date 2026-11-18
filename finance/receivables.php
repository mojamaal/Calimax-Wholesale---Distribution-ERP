<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('finance.view');

$pdo = db();
$rep = input_int($_GET, 'rep'); $cat = input_int($_GET, 'cat');
$inv = ar_open_invoices(null, $rep ?: null);
$labels = ageing_labels(); $byCust = []; $totals = array_fill_keys(array_keys($labels), 0.0);
foreach ($inv as $i) {
    if ($cat && (int) ($pdo->query('SELECT category_id FROM customers WHERE id = ' . (int) $i['customer_id'])->fetchColumn()) !== $cat) continue;
    $c = &$byCust[$i['customer_id']]; if (!$c) $c = ['name' => $i['business_name'], 'rep' => $i['rep_name'], 'limit' => $i['credit_limit'], 'b' => array_fill_keys(array_keys($labels), 0.0), 'total' => 0.0, 'oldest' => 0, 'n' => 0];
    $c['b'][$i['bucket']] += (float) $i['outstanding']; $c['total'] += (float) $i['outstanding']; $c['oldest'] = max($c['oldest'], (int) $i['days_overdue']); $c['n']++; $totals[$i['bucket']] += (float) $i['outstanding']; unset($c);
}
uasort($byCust, fn($a, $b) => $b['total'] <=> $a['total']);
$grand = array_sum($totals);
$advance = (float) $pdo->query('SELECT COALESCE(SUM(amount - allocated),0) FROM customer_payments')->fetchColumn();
$reps = $pdo->query("SELECT id, full_name FROM users WHERE role = 'sales_rep' ORDER BY full_name")->fetchAll();
$cats = $pdo->query('SELECT id, name FROM customer_categories ORDER BY id')->fetchAll();
$glAr = account_balance('1100');
$pageTitle = 'Receivables';
require __DIR__ . '/../includes/header.php';
page_header('Accounts receivable', 'Who owes you money, how much and for how long — customer ageing report.', '<button class="btn btn-secondary" onclick="window.print()">' . icon('printer', 16) . ' Print</button>');
nav_finance();
?>
<div class="grid grid-4 mb-4"><?= kpi('Total outstanding', format_money_short($grand), 'wallet', 'amber', count($byCust) . ' customer(s) · GL control ' . format_money_short($glAr)) ?><?= kpi('Overdue', format_money_short($grand - $totals['current']), 'clock-alert', 'red', $grand ? number_format(($grand - $totals['current']) / $grand * 100, 1) . '% of receivables' : '—') ?><?= kpi('90+ days', format_money_short($totals['d90p']), 'alert-octagon', $totals['d90p'] ? 'red' : 'gray', 'Highest collection risk') ?><?= kpi('Advances held', format_money_short($advance), 'hand-coins', 'green', 'Received, not yet applied') ?></div>
<div class="card mb-4"><div class="card-head"><h3>Ageing summary</h3></div><div class="age-bar mb-3"><?php foreach (array_keys($labels) as $i => $k): if ($totals[$k] > 0): ?><span class="age-<?= $i ?>" style="width:<?= $totals[$k] / max($grand, 1) * 100 ?>%"></span><?php endif; endforeach; ?></div><div class="age-legend"><?php foreach ($labels as $k => $l): $i = array_search($k, array_keys($labels)); ?><span><i class="age-<?= $i ?>"></i><?= e($l) ?> <strong><?= format_money_short($totals[$k]) ?></strong></span><?php endforeach; ?></div></div>
<div class="card flush">
  <div style="padding:16px 16px 0;"><form method="get" class="filter-bar"><select class="form-control" name="rep" onchange="this.form.submit()"><option value="">All sales reps</option><?php foreach ($reps as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $rep === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['full_name']) ?></option><?php endforeach; ?></select><select class="form-control" name="cat" onchange="this.form.submit()"><option value="">All customer categories</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $cat === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></form></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Customer</th><th>Rep</th><?php foreach ($labels as $l): ?><th class="num"><?= e($l) ?></th><?php endforeach; ?><th class="num">Total</th><th>Oldest</th></tr></thead><tbody>
  <?php foreach ($byCust as $cid => $c): ?><tr><td><a class="strong" href="<?= e(app_url('/sales/customer_view.php?id=' . $cid)) ?>"><?= e($c['name']) ?></a><div class="cell-sub"><?= (int) $c['n'] ?> invoice(s)<?= $c['limit'] > 0 && $c['total'] > $c['limit'] ? ' · <span class="text-error">over credit limit</span>' : '' ?></div></td><td class="text-secondary"><?= e($c['rep'] ?? '—') ?></td>
    <?php foreach (array_keys($labels) as $i => $k): ?><td class="num <?= $c['b'][$k] > 0 && $i >= 2 ? 'text-error' : ($c['b'][$k] > 0 ? '' : 'text-muted') ?>"><?= $c['b'][$k] > 0 ? format_money($c['b'][$k]) : '—' ?></td><?php endforeach; ?>
    <td class="num strong"><?= format_money($c['total']) ?></td><td class="nowrap <?= $c['oldest'] > 0 ? 'text-error' : 'text-muted' ?>"><?= $c['oldest'] > 0 ? (int) $c['oldest'] . ' days' : 'Not due' ?></td></tr><?php endforeach; ?></tbody>
  <tfoot><tr><td colspan="2">Total</td><?php foreach (array_keys($labels) as $k): ?><td class="num"><?= format_money($totals[$k]) ?></td><?php endforeach; ?><td class="num"><?= format_money($grand) ?></td><td></td></tr></tfoot></table>
  <?php if (!$byCust) echo empty_state('check-circle-2', 'No receivables', 'Every customer invoice is settled.'); ?></div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
