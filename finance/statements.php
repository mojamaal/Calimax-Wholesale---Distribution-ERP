<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reports.finance');

$pdo = db();
$period = resolve_report_period('this_month');
$view = in_array(input($_GET, 'view'), ['pnl', 'bs', 'tb'], true) ? input($_GET, 'view') : 'pnl';
$p = gl_pnl($period['from'], $period['to']);
$bs = account_balances(null, $period['to']);
$b = fn($c) => (float) ($bs[$c]['balance'] ?? 0);
$retained = array_sum(array_map(fn($r) => in_array($r['type'], ['income']) ? $r['balance'] : (in_array($r['type'], ['cogs', 'expense']) ? -$r['balance'] : 0), $bs));
$assets = ['1000' => 'Cash in hand', '1010' => 'Bank', '1100' => 'Accounts receivable', '1200' => 'Inventory', '1300' => 'Input tax recoverable'];
$liab = ['2000' => 'Accounts payable', '2100' => 'Goods received not invoiced', '2200' => 'Output tax payable'];
$tA = array_sum(array_map($b, array_keys($assets))); $tL = array_sum(array_map($b, array_keys($liab))); $equity = $b('3000') + $retained;
$pageTitle = 'Financial statements';
require __DIR__ . '/../includes/header.php';
page_header('Financial statements', 'Profit & loss, balance sheet and trial balance — generated from the general ledger.', '<button class="btn btn-secondary" onclick="window.print()">' . icon('printer', 16) . ' Print</button>');
nav_finance();
?>
<div class="card tight mb-4"><div class="segmented mb-3"><?php foreach (['pnl' => 'Profit & loss', 'bs' => 'Balance sheet', 'tb' => 'Trial balance'] as $k => $l): ?><label><a href="<?= e(query_with(['view' => $k])) ?>" style="display:contents;"><input type="radio" <?= $view === $k ? 'checked' : '' ?>><span><?= e($l) ?></span></a></label><?php endforeach; ?></div><?php render_period_filter($period, ['view' => $view]); ?></div>
<?php if ($view === 'pnl'): ?>
<div class="layout-2-1"><div class="card"><div class="card-head"><div><h3>Profit &amp; loss</h3><div class="sub"><?= e(fmt_date($period['from'])) ?> – <?= e(fmt_date($period['to'])) ?></div></div></div>
<table class="stmt"><tbody>
<tr class="section"><td colspan="2">Revenue</td></tr><tr class="indent"><td>Sales revenue (net of discounts)</td><td><?= format_money($p['revenue']) ?></td></tr><tr class="indent"><td>Less: sales returns &amp; allowances</td><td>(<?= format_money($p['returns']) ?>)</td></tr><tr class="sub"><td>Net sales</td><td><?= format_money($p['net_sales']) ?></td></tr>
<tr class="section"><td colspan="2">Cost of goods sold</td></tr><tr class="indent"><td>Cost of goods sold</td><td><?= format_money($p['cogs']) ?></td></tr><tr class="indent"><td>Inventory write-offs &amp; adjustments</td><td><?= format_money($p['adjustments']) ?></td></tr><tr class="indent"><td>Purchase price variance</td><td><?= format_money($p['ppv']) ?></td></tr>
<tr class="sub"><td>Gross profit <span class="text-muted">(<?= number_format($p['gross_margin'], 1) ?>%)</span></td><td class="<?= $p['gross_profit'] >= 0 ? 'tone-pos' : 'tone-neg' ?>"><?= format_money($p['gross_profit']) ?></td></tr>
<?php if (abs($p['other_income']) > 0.005): ?><tr class="indent"><td>Other income</td><td><?= format_money($p['other_income']) ?></td></tr><?php endif; ?>
<tr class="section"><td colspan="2">Operating expenses</td></tr><?php foreach ($p['opex'] as $o): ?><tr class="indent"><td><?= e($o['name']) ?></td><td><?= format_money($o['balance']) ?></td></tr><?php endforeach; ?><tr class="sub"><td>Total operating expenses</td><td><?= format_money($p['opex_total']) ?></td></tr>
<tr class="grand"><td>Net profit <span class="text-muted" style="font-size:13px;font-weight:600;">(<?= number_format($p['net_margin'], 1) ?>%)</span></td><td class="<?= $p['net_profit'] >= 0 ? 'tone-pos' : 'tone-neg' ?>"><?= format_money($p['net_profit']) ?></td></tr></tbody></table></div>
<div class="card"><h3 class="mb-3">Why profit can differ from sales</h3><div class="callout info text-sm">A distributor can have high sales but thin profit: purchase costs, discounts, transport and unpaid customer invoices all reduce what is left. Revenue here is recognised when goods are invoiced; cost of goods sold when they are dispatched.</div>
<dl class="desc-list mt-3"><dt>Invoiced (ex tax)</dt><dd><?= format_money($p['revenue']) ?></dd><dt>Still unpaid by customers</dt><dd><?= format_money(receivables_total()) ?></dd><dt>Expenses as % of net sales</dt><dd><?= $p['net_sales'] > 0 ? number_format($p['opex_total'] / $p['net_sales'] * 100, 1) . '%' : '—' ?></dd></dl></div></div>
<?php elseif ($view === 'bs'): ?>
<div class="card" style="max-width:760px;"><div class="card-head"><div><h3>Balance sheet</h3><div class="sub">As at <?= e(fmt_date($period['to'])) ?></div></div><span class="badge badge-<?= abs($tA - $tL - $equity) < 0.01 ? 'success' : 'error' ?>"><?= abs($tA - $tL - $equity) < 0.01 ? 'Balanced' : 'Out of balance' ?></span></div>
<table class="stmt"><tbody><tr class="section"><td colspan="2">Assets</td></tr><?php foreach ($assets as $c => $l): ?><tr class="indent"><td><?= e($l) ?></td><td><?= format_money($b($c)) ?></td></tr><?php endforeach; ?><tr class="sub"><td>Total assets</td><td><?= format_money($tA) ?></td></tr>
<tr class="section"><td colspan="2">Liabilities</td></tr><?php foreach ($liab as $c => $l): ?><tr class="indent"><td><?= e($l) ?></td><td><?= format_money($b($c)) ?></td></tr><?php endforeach; ?><tr class="sub"><td>Total liabilities</td><td><?= format_money($tL) ?></td></tr>
<tr class="section"><td colspan="2">Equity</td></tr><tr class="indent"><td>Owner’s capital (net of drawings)</td><td><?= format_money($b('3000')) ?></td></tr><tr class="indent"><td>Retained profit to date</td><td><?= format_money($retained) ?></td></tr><tr class="sub"><td>Total equity</td><td><?= format_money($equity) ?></td></tr>
<tr class="grand"><td>Liabilities + equity</td><td><?= format_money($tL + $equity) ?></td></tr></tbody></table></div>
<?php else: $td = 0; $tc = 0; ?>
<div class="card flush" style="max-width:860px;"><div class="card-head"><h3>Trial balance — <?= e(fmt_date($period['to'])) ?></h3></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody><?php foreach ($bs as $r): $net = (float) $r['debit'] - (float) $r['credit']; if (abs($net) < 0.005) continue; $td += $net > 0 ? $net : 0; $tc += $net < 0 ? -$net : 0; ?><tr><td><span class="mono text-secondary"><?= e($r['code']) ?></span> &nbsp;<?= e($r['name']) ?></td><td class="num"><?= $net > 0 ? format_money($net) : '' ?></td><td class="num"><?= $net < 0 ? format_money(-$net) : '' ?></td></tr><?php endforeach; ?></tbody><tfoot><tr><td>Total</td><td class="num"><?= format_money($td) ?></td><td class="num"><?= format_money($tc) ?></td></tr></tfoot></table></div></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
