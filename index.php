<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!can('dashboard.view')) redirect(home_path_for(current_user()));

$pdo = db();
$period = resolve_report_period('last_30');
$from = $period['from']; $to = $period['to'];
$f = ['warehouse_id' => input_int($_GET, 'wh'), 'customer_id' => input_int($_GET, 'customer'), 'product_id' => input_int($_GET, 'product'), 'rep_id' => input_int($_GET, 'rep')];
$filtered = (bool) array_filter($f);
$canFinance = can('reports.finance');

$tot = sales_totals($f, $from, $to);
$days = (strtotime($to) - strtotime($from)) / 86400 + 1;
$prevTo = date('Y-m-d', strtotime($from . ' -1 day')); $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));
$prev = sales_totals($f, $prevFrom, $prevTo);
$trend = (float) $prev['net'] > 0 ? ((float) $tot['net'] - (float) $prev['net']) / (float) $prev['net'] * 100 : null;
$collected = collections_total($from, $to, $f);
$ar = receivables_total(); $arOver = receivables_overdue();
$ap = payables_total(); $apOver = payables_overdue();
$pnl = gl_pnl($from, $to);
$cashPos = cash_position();
$val = inventory_valuation($f['warehouse_id'] ?: null, $f['product_id'] ?: null);

// Order pipeline
$cnt = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
$pipe = [
    ['Awaiting approval', $cnt("SELECT COUNT(*) FROM sales_orders WHERE status = 'pending_approval'"), '/sales/orders.php?status=pending_approval', 'shield-alert'],
    ['Confirmed — to fulfil', $cnt("SELECT COUNT(*) FROM sales_orders WHERE status = 'confirmed'"), '/sales/orders.php?status=confirmed', 'clipboard-check'],
    ['Partially fulfilled', $cnt("SELECT COUNT(*) FROM sales_orders WHERE status = 'partially_fulfilled'"), '/sales/orders.php?status=partially_fulfilled', 'package-open'],
    ['Awaiting delivery', $cnt("SELECT COUNT(*) FROM deliveries WHERE status IN ('pending_pick','picked','packed','dispatched')"), '/sales/deliveries.php?filter=open', 'truck'],
    ['Delivered, not invoiced', $cnt("SELECT COUNT(*) FROM deliveries d WHERE d.status = 'delivered' AND EXISTS (SELECT 1 FROM delivery_items di WHERE di.delivery_id = d.id AND di.qty_accepted > di.qty_invoiced)"), '/sales/deliveries.php?filter=uninvoiced', 'receipt'],
    ['Completed (period)', $cnt("SELECT COUNT(*) FROM sales_orders WHERE status = 'completed' AND order_date BETWEEN " . $pdo->quote($from) . ' AND ' . $pdo->quote($to)), '/sales/orders.php?status=completed', 'check-circle-2'],
];

// Trend chart (daily net sales)
$daily = [];
foreach (sales_grouped('day', $f, $from, $to) as $r) $daily[$r['k']] = $r;
$series = [];
$span = min($days, 62);
for ($i = $span - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime($to . " -$i days")); $series[] = ['d' => $d, 'v' => (float) ($daily[$d]['net'] ?? 0), 'c' => (int) ($daily[$d]['invoices'] ?? 0)]; }
$chart = ['title' => 'Daily sales', 'labels' => array_map(fn($x) => date('j M', strtotime($x['d'])), $series), 'values' => array_map(fn($x) => round($x['v'], 2), $series),
    'tips' => array_map(fn($x) => date('D, j M Y', strtotime($x['d'])), $series), 'sub' => array_map(fn($x) => $x['c'] . ' invoice(s)', $series)];

$topCust = sales_grouped('customer', $f, $from, $to, 6);
$topProd = sales_grouped('product', $f, $from, $to, 6);
$byRep = sales_grouped('rep', $f, $from, $to, 8);
$maxC = max(1, ...array_map(fn($r) => (float) $r['net'], $topCust ?: [['net' => 1]]));
$maxP = max(1, ...array_map(fn($r) => (float) $r['net'], $topProd ?: [['net' => 1]]));
$maxR = max(1, ...array_map(fn($r) => (float) $r['net'], $byRep ?: [['net' => 1]]));

$low = low_stock_products(6, $f['warehouse_id'] ?: null);
$expiring = expiring_batches((int) setting('expiry_warning_days', '90'), $f['warehouse_id'] ?: null, 6);
$overdueInv = array_slice(array_filter(ar_open_invoices(), fn($r) => (int) $r['days_overdue'] > 0), 0, 5);
$whVal = [];
foreach ($val['rows'] as $r) { $k = $r['warehouse']; $whVal[$k] = ($whVal[$k] ?? ['units' => 0, 'value' => 0]); $whVal[$k]['units'] += $r['qty']; $whVal[$k]['value'] += $r['value']; }

$customers = $pdo->query('SELECT id, business_name FROM customers WHERE is_active = 1 ORDER BY business_name')->fetchAll();
$products = $pdo->query('SELECT id, name FROM products WHERE is_active = 1 ORDER BY name')->fetchAll();
$reps = $pdo->query("SELECT id, full_name FROM users WHERE role = 'sales_rep' ORDER BY full_name")->fetchAll();
$whs = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name')->fetchAll();

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
page_header($greeting . ', ' . explode(' ', current_user()['full_name'])[0], 'Business overview for <strong>' . e($period['label']) . '</strong> (' . e(fmt_date($from)) . ' – ' . e(fmt_date($to)) . ')' . ($filtered ? ' · <span class="text-warning">filtered view</span>' : ''),
    (can('sales.create') ? '<a class="btn btn-primary" href="' . e(app_url('/sales/order_form.php')) . '">' . icon('plus', 16) . ' New sales order</a>' : '') . (can('reports.view') ? '<a class="btn btn-secondary" href="' . e(app_url('/reports/index.php')) . '">' . icon('bar-chart-3', 16) . ' Reports</a>' : ''));
$sel = fn($name, $opts, $cur, $label) => '<select class="form-control" name="' . $name . '" onchange="this.form.submit()"><option value="">' . $label . '</option>' . implode('', array_map(fn($o) => '<option value="' . (int) $o['id'] . '"' . ((int) $cur === (int) $o['id'] ? ' selected' : '') . '>' . e($o['business_name'] ?? $o['full_name'] ?? $o['name']) . '</option>', $opts)) . '</select>';
?>
<div class="card tight mb-4">
  <?php render_period_filter($period, ['wh' => $f['warehouse_id'] ?: '', 'customer' => $f['customer_id'] ?: '', 'product' => $f['product_id'] ?: '', 'rep' => $f['rep_id'] ?: '']); ?>
  <form method="get" class="scope-bar">
    <input type="hidden" name="preset" value="<?= e($period['preset'] ?: 'custom') ?>"><input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
    <label>Filter by</label>
    <?= $sel('wh', $whs, $f['warehouse_id'], 'All warehouses') ?><?= $sel('customer', $customers, $f['customer_id'], 'All customers') ?><?= $sel('product', $products, $f['product_id'], 'All products') ?><?= $sel('rep', $reps, $f['rep_id'], 'All sales reps') ?>
    <?php if ($filtered): ?><a class="btn btn-ghost btn-sm" href="<?= e(query_with(['wh' => null, 'customer' => null, 'product' => null, 'rep' => null])) ?>">Clear filters</a><?php endif; ?>
  </form>
</div>

<div class="grid grid-5 mb-4">
  <div class="kpi-card"><div class="kpi-top"><span class="kpi-label">Total sales</span><span class="icon-tile sm"><?= icon('banknote', 16) ?></span></div>
    <div class="kpi-value"><?= format_money_short($tot['net']) ?></div>
    <div class="kpi-desc"><?= $trend === null ? (int) $tot['invoices'] . ' invoice(s)' : '<span class="kpi-trend ' . ($trend < 0 ? 'negative' : '') . '">' . ($trend >= 0 ? '▲' : '▼') . ' ' . number_format(abs($trend), 1) . '%</span> vs previous period' ?></div></div>
  <?= kpi('Collected', format_money_short($collected), 'hand-coins', 'green', 'Customer payments received', '/sales/payments.php' . query_with([])) ?>
  <?= kpi('Customers owe us', format_money_short($ar), 'wallet', 'amber', format_money_short($arOver) . ' overdue', '/finance/receivables.php') ?>
  <?= kpi('Gross profit', $filtered ? format_money_short($tot['gross_profit']) : format_money_short($pnl['gross_profit']), 'percent', 'green', ($filtered ? 'Invoice margin' : number_format($pnl['gross_margin'], 1) . '% margin') . ' after cost of goods') ?>
  <?= kpi('Inventory value', format_money_short($val['total']), 'warehouse', 'purple', number_format($val['units']) . ' units at cost', '/reports/inventory.php') ?>
</div>
<?php if ($canFinance): ?>
<div class="grid grid-5 mb-4">
  <?= kpi('Expenses', $filtered ? '—' : format_money_short($pnl['opex_total']), 'receipt-text', 'amber', 'Rent, salaries, fuel, transport…', '/finance/expenses.php') ?>
  <div class="kpi-card"><div class="kpi-top"><span class="kpi-label">Net profit</span><span class="icon-tile sm <?= $pnl['net_profit'] >= 0 ? 'green' : 'red' ?>"><?= icon('scale', 16) ?></span></div>
    <div class="kpi-value <?= $filtered ? '' : ($pnl['net_profit'] >= 0 ? 'tone-pos' : 'tone-neg') ?>"><?= $filtered ? '—' : format_money_short($pnl['net_profit']) ?></div><div class="kpi-desc"><?= $filtered ? 'Not available for a filtered view' : 'After COGS & operating expenses' ?></div></div>
  <?= kpi('Cash + bank', format_money_short($cashPos['cash'] + $cashPos['bank']), 'landmark', 'green', 'Cash ' . format_money_short($cashPos['cash']) . ' · Bank ' . format_money_short($cashPos['bank']), '/finance/cashbook.php') ?>
  <?= kpi('We owe suppliers', format_money_short($ap), 'file-clock', 'red', format_money_short($apOver) . ' overdue', '/finance/payables.php') ?>
  <?= kpi('Orders in period', number_format((int) $tot['invoices']), 'receipt', 'blue', number_format((int) $tot['customers']) . ' active customer(s)', '/sales/invoices.php' . query_with([])) ?>
</div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-head"><div><h3>Order pipeline</h3><div class="sub">Where every customer order is right now</div></div></div>
  <div class="pipeline">
    <?php foreach ($pipe as [$l, $n, $href, $ic]): ?><a href="<?= e(app_url($href)) ?>"><div class="pn"><?= $n ?></div><div class="pl"><?= e($l) ?></div></a><?php endforeach; ?>
  </div>
</div>

<div class="layout-2-1 mb-4">
  <div class="card">
    <div class="card-head"><div><h3>Sales trend</h3><div class="sub"><?= e($period['label']) ?> · net of tax</div></div><a class="btn-ghost btn-sm" href="<?= e(app_url('/reports/sales.php' . query_with([]))) ?>">Sales report →</a></div>
    <div class="chart-wrap" data-chart='<?= e(json_encode($chart)) ?>'></div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Top customers</h3></div>
    <?php foreach ($topCust as $r): ?><div class="hbar-row"><a class="strong" href="<?= e(app_url('/sales/customer_view.php?id=' . $r['k'])) ?>"><?= e($r['label']) ?></a><span class="hbar-track"><span class="hbar-fill" style="display:block;width:<?= round($r['net'] / $maxC * 100) ?>%"></span></span><span class="hbar-val"><?= format_money_short($r['net']) ?></span></div><?php endforeach; ?>
    <?php if (!$topCust) echo empty_state('users', 'No sales yet', 'Customers appear here once invoices are issued.'); ?>
  </div>
</div>

<div class="grid grid-3 mb-4">
  <div class="card">
    <div class="card-head"><h3>Sales by product</h3></div>
    <?php foreach ($topProd as $r): ?><div class="hbar-row"><span class="strong" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($r['label']) ?></span><span class="hbar-track"><span class="hbar-fill" style="display:block;width:<?= round($r['net'] / $maxP * 100) ?>%"></span></span><span class="hbar-val"><?= format_money_short($r['net']) ?></span></div><?php endforeach; ?>
    <?php if (!$topProd) echo '<div class="text-muted text-sm">No sales in this period.</div>'; ?>
  </div>
  <div class="card">
    <div class="card-head"><h3>Sales by representative</h3><?php if (can('reps.view')): ?><a class="btn-ghost btn-sm" href="<?= e(app_url('/reps/index.php')) ?>">Performance →</a><?php endif; ?></div>
    <?php foreach ($byRep as $r): ?><div class="hbar-row"><span class="strong"><?= e($r['label']) ?></span><span class="hbar-track"><span class="hbar-fill" style="display:block;width:<?= round($r['net'] / $maxR * 100) ?>%"></span></span><span class="hbar-val"><?= format_money_short($r['net']) ?></span></div><?php endforeach; ?>
    <?php if (!$byRep) echo '<div class="text-muted text-sm">No sales in this period.</div>'; ?>
  </div>
  <div class="card">
    <div class="card-head"><h3>Stock by warehouse</h3><a class="btn-ghost btn-sm" href="<?= e(app_url('/warehouse/stock.php')) ?>">Details →</a></div>
    <div class="list-rows">
      <?php foreach ($whVal as $name => $w): ?><div><span><span class="strong"><?= e($name) ?></span><div class="cell-sub"><?= number_format($w['units']) ?> units</div></span><strong><?= format_money_short($w['value']) ?></strong></div><?php endforeach; ?>
      <?php if ($val['in_transit'] > 0 && !$f['warehouse_id']): ?><div><span><span class="strong">In transit</span><div class="cell-sub"><?= number_format($val['in_transit_units']) ?> units between warehouses</div></span><strong><?= format_money_short($val['in_transit']) ?></strong></div><?php endif; ?>
    </div>
  </div>
</div>

<div class="grid grid-3">
  <div class="card">
    <div class="card-head"><h3>Low stock</h3><a class="btn-ghost btn-sm" href="<?= e(app_url('/warehouse/stock.php?filter=low')) ?>">All →</a></div>
    <div class="list-rows">
      <?php foreach ($low as $l): ?><a href="<?= e(app_url('/catalog/product_view.php?id=' . $l['id'])) ?>"><span><span class="strong"><?= e($l['name']) ?></span><div class="cell-sub">Reorder at <?= fmt_qty($l['reorder_level']) ?></div></span><span class="badge badge-<?= $l['available'] <= 0 ? 'error' : 'warning' ?>"><?= $l['available'] <= 0 ? 'Out' : fmt_qty($l['available']) . ' left' ?></span></a><?php endforeach; ?>
      <?php if (!$low) echo '<div class="text-muted text-sm">All stock levels are healthy.</div>'; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Expiring batches</h3><a class="btn-ghost btn-sm" href="<?= e(app_url('/trace/expiry.php')) ?>">Monitor →</a></div>
    <div class="list-rows">
      <?php foreach ($expiring as $b): ?><a href="<?= e(app_url('/trace/batch_view.php?id=' . $b['id'])) ?>"><span><span class="strong"><?= e($b['product_name']) ?></span> <span class="text-muted mono"><?= e($b['batch_no']) ?></span><div class="cell-sub"><?= fmt_qty($b['on_hand']) ?> <?= e($b['base_unit']) ?> · <?= e(fmt_date($b['expiry_date'])) ?></div></span><?= expiry_badge($b['expiry_date']) ?></a><?php endforeach; ?>
      <?php if (!$expiring) echo '<div class="text-muted text-sm">No batches are close to expiry.</div>'; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Overdue invoices</h3><a class="btn-ghost btn-sm" href="<?= e(app_url('/sales/invoices.php?filter=overdue')) ?>">All →</a></div>
    <div class="list-rows">
      <?php foreach ($overdueInv as $o): ?><a href="<?= e(app_url('/sales/invoice_view.php?id=' . $o['id'])) ?>"><span><span class="strong"><?= e($o['business_name']) ?></span><div class="cell-sub"><?= e($o['inv_no']) ?> · <?= (int) $o['days_overdue'] ?> days overdue</div></span><strong class="text-error"><?= format_money_short($o['outstanding']) ?></strong></a><?php endforeach; ?>
      <?php if (!$overdueInv) echo '<div class="text-muted text-sm">No overdue invoices.</div>'; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
