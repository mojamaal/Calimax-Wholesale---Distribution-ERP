<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reports.finance');

$pdo = db();
$period = resolve_report_period('this_year');
$tax = e(setting('tax_name', 'VAT'));
$rows = $pdo->prepare("SELECT DATE_FORMAT(je.entry_date,'%Y-%m') AS m,
    SUM(CASE WHEN a.code = '2200' THEN jl.credit - jl.debit ELSE 0 END) AS output_tax, SUM(CASE WHEN a.code = '1300' THEN jl.debit - jl.credit ELSE 0 END) AS input_tax
    FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id JOIN accounts a ON a.id = jl.account_id WHERE a.code IN ('2200','1300') AND je.entry_date BETWEEN :f AND :t GROUP BY m ORDER BY m");
$rows->execute([':f' => $period['from'], ':t' => $period['to']]); $rows = $rows->fetchAll();
$out = array_sum(array_column($rows, 'output_tax')); $in = array_sum(array_column($rows, 'input_tax'));
$byRate = $pdo->prepare("SELECT sii.tax_rate, SUM(sii.line_net) AS net, SUM(sii.tax_amount) AS tax FROM sales_invoice_items sii JOIN sales_invoices si ON si.id = sii.invoice_id WHERE si.status <> 'void' AND si.invoice_date BETWEEN :f AND :t GROUP BY sii.tax_rate ORDER BY sii.tax_rate");
$byRate->execute([':f' => $period['from'], ':t' => $period['to']]); $byRate = $byRate->fetchAll();
$pageTitle = 'Tax summary';
require __DIR__ . '/../includes/header.php';
page_header('Tax summary', $tax . ' collected on sales versus ' . $tax . ' paid on purchases, from the ledger.', '<button class="btn btn-secondary" onclick="window.print()">' . icon('printer', 16) . ' Print</button>');
nav_reports();
?>
<div class="card tight mb-4"><?php render_period_filter($period); ?></div>
<div class="grid grid-3 mb-4"><?= kpi('Output tax (collected)', format_money_short($out), 'arrow-up-right', 'amber', 'On sales, net of credit notes') ?><?= kpi('Input tax (paid)', format_money_short($in), 'arrow-down-left', 'blue', 'On purchases') ?><?= kpi('Net ' . strip_tags($tax) . ' payable', format_money_short($out - $in), 'landmark', $out - $in >= 0 ? 'red' : 'green', $out - $in >= 0 ? 'To pay the tax authority' : 'Refundable') ?></div>
<div class="grid grid-2"><div class="card flush"><div class="card-head"><h3>By month</h3></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Month</th><th class="num">Output tax</th><th class="num">Input tax</th><th class="num">Net payable</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td class="strong"><?= e(date('M Y', strtotime($r['m'] . '-01'))) ?></td><td class="num"><?= format_money($r['output_tax']) ?></td><td class="num"><?= format_money($r['input_tax']) ?></td><td class="num strong"><?= format_money($r['output_tax'] - $r['input_tax']) ?></td></tr><?php endforeach; ?></tbody><tfoot><tr><td>Total</td><td class="num"><?= format_money($out) ?></td><td class="num"><?= format_money($in) ?></td><td class="num"><?= format_money($out - $in) ?></td></tr></tfoot></table><?php if (!$rows) echo empty_state('percent', 'No tax postings in this period'); ?></div></div>
<div class="card flush"><div class="card-head"><h3>Sales by tax rate</h3></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Rate</th><th class="num">Net sales</th><th class="num">Tax</th></tr></thead><tbody><?php foreach ($byRate as $r): ?><tr><td class="strong"><?= rtrim(rtrim(number_format($r['tax_rate'], 2), '0'), '.') ?>%</td><td class="num"><?= format_money($r['net']) ?></td><td class="num"><?= format_money($r['tax']) ?></td></tr><?php endforeach; ?></tbody></table><?php if (!$byRate) echo empty_state('percent', 'No invoices'); ?></div></div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
