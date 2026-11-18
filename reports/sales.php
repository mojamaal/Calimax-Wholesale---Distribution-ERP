<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reports.view');

$pdo = db();
$period = resolve_report_period('this_month');
$from = $period['from']; $to = $period['to'];
$f = ['warehouse_id' => input_int($_GET, 'wh'), 'customer_id' => input_int($_GET, 'customer'), 'product_id' => input_int($_GET, 'product'), 'rep_id' => input_int($_GET, 'rep')];
$dims = ['customer' => 'By customer', 'product' => 'By product', 'category' => 'By category', 'rep' => 'By sales rep', 'warehouse' => 'By warehouse', 'month' => 'By month', 'day' => 'By day'];
$dim = isset($dims[input($_GET, 'by')]) ? input($_GET, 'by') : 'customer';
$showCost = can('products.view_cost');
$rows = sales_grouped($dim, $f, $from, $to);
$tot = sales_totals($f, $from, $to);
if (input($_GET, 'export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="sales-by-' . $dim . '-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fputcsv($o, [ucfirst($dim), 'Invoices', 'Units', 'Net sales', 'Total incl tax'] + ($showCost ? [5 => 'Cost', 6 => 'Gross profit'] : []));
    foreach ($rows as $r) fputcsv($o, array_merge([$r['label'], $r['invoices'], $r['qty'], $r['net'], $r['total']], $showCost ? [$r['cogs'], $r['profit']] : [])); exit;
}
$max = max(1, ...array_map(fn($r) => (float) $r['net'], $rows ?: [['net' => 1]]));
$customers = $pdo->query('SELECT id, business_name FROM customers ORDER BY business_name')->fetchAll(); $products = $pdo->query('SELECT id, name FROM products ORDER BY name')->fetchAll();
$reps = $pdo->query("SELECT id, full_name FROM users WHERE role = 'sales_rep' ORDER BY full_name")->fetchAll(); $whs = $pdo->query('SELECT id, name FROM warehouses ORDER BY name')->fetchAll();
$sel = fn($n, $opts, $cur, $label, $lk) => '<select class="form-control" name="' . $n . '" onchange="this.form.submit()"><option value="">' . $label . '</option>' . implode('', array_map(fn($o) => '<option value="' . (int) $o['id'] . '"' . ((int) $cur === (int) $o['id'] ? ' selected' : '') . '>' . e($o[$lk]) . '</option>', $opts)) . '</select>';
$pageTitle = 'Sales report';
require __DIR__ . '/../includes/header.php';
page_header('Sales analysis', 'Net sales (excluding tax) from invoices' . ($showCost ? ', with cost and margin.' : '.'), '<a class="btn btn-secondary" href="' . e(query_with(['export' => 'csv'])) . '">' . icon('download', 16) . ' Export CSV</a>');
nav_reports();
?>
<div class="card tight mb-4"><?php render_period_filter($period, ['by' => $dim, 'wh' => $f['warehouse_id'] ?: '', 'customer' => $f['customer_id'] ?: '', 'product' => $f['product_id'] ?: '', 'rep' => $f['rep_id'] ?: '']); ?>
  <form method="get" class="scope-bar"><?php foreach (['preset' => $period['preset'] ?: 'custom', 'from' => $from, 'to' => $to, 'by' => $dim] as $k => $v) echo '<input type="hidden" name="' . $k . '" value="' . e($v) . '">'; ?><label>Filter</label><?= $sel('wh', $whs, $f['warehouse_id'], 'All warehouses', 'name') ?><?= $sel('customer', $customers, $f['customer_id'], 'All customers', 'business_name') ?><?= $sel('product', $products, $f['product_id'], 'All products', 'name') ?><?= $sel('rep', $reps, $f['rep_id'], 'All reps', 'full_name') ?></form></div>
<div class="grid grid-4 mb-4"><?= kpi('Net sales', format_money_short($tot['net']), 'banknote') ?><?= kpi('Invoices', number_format((int) $tot['invoices']), 'receipt', 'blue', (int) $tot['customers'] . ' customer(s)') ?><?= kpi('Tax', format_money_short($tot['tax']), 'percent', 'gray') ?><?php if ($showCost): ?><?= kpi('Gross profit', format_money_short($tot['gross_profit']), 'trending-up', 'green', $tot['net'] > 0 ? number_format($tot['gross_profit'] / $tot['net'] * 100, 1) . '% margin' : '') ?><?php endif; ?></div>
<div class="chip-group mb-4"><?php foreach ($dims as $k => $l): ?><a class="chip <?= $dim === $k ? 'active' : '' ?>" href="<?= e(query_with(['by' => $k])) ?>"><?= e($l) ?></a><?php endforeach; ?></div>
<div class="card flush"><div class="table-wrap"><table class="data-table"><thead><tr><th><?= e(ucfirst($dim)) ?></th><th class="num">Invoices</th><th class="num">Units</th><th style="width:180px;">Share</th><th class="num">Net sales</th><?php if ($showCost): ?><th class="num">Cost</th><th class="num">Gross profit</th><th class="num">Margin</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td class="strong"><?= $dim === 'customer' ? '<a href="' . e(app_url('/sales/customer_view.php?id=' . $r['k'])) . '">' . e($r['label']) . '</a>' : ($dim === 'product' ? '<a href="' . e(app_url('/catalog/product_view.php?id=' . $r['k'])) . '">' . e($r['label']) . '</a>' : e($r['label'])) ?></td><td class="num"><?= (int) $r['invoices'] ?></td><td class="num"><?= fmt_qty($r['qty']) ?></td><td><div class="meter"><span style="width:<?= round($r['net'] / $max * 100) ?>%"></span></div></td><td class="num strong"><?= format_money($r['net']) ?></td><?php if ($showCost): ?><td class="num text-secondary"><?= format_money($r['cogs']) ?></td><td class="num <?= $r['profit'] >= 0 ? '' : 'text-error' ?>"><?= format_money($r['profit']) ?></td><td class="num text-secondary"><?= $r['net'] > 0 ? number_format($r['profit'] / $r['net'] * 100, 1) . '%' : '—' ?></td><?php endif; ?></tr><?php endforeach; ?></tbody>
<tfoot><tr><td>Total</td><td class="num"><?= (int) $tot['invoices'] ?></td><td></td><td></td><td class="num"><?= format_money($tot['net']) ?></td><?php if ($showCost): ?><td class="num"><?= format_money($tot['cogs']) ?></td><td class="num"><?= format_money($tot['gross_profit']) ?></td><td class="num"><?= $tot['net'] > 0 ? number_format($tot['gross_profit'] / $tot['net'] * 100, 1) . '%' : '—' ?></td><?php endif; ?></tr></tfoot></table>
<?php if (!$rows) echo empty_state('trending-up', 'No sales in this period'); ?></div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
