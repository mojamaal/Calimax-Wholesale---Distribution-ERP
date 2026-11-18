<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reports.view');

$pdo = db();
$wh = input_int($_GET, 'wh'); $view = input($_GET, 'view') === 'product' ? 'product' : 'warehouse';
$val = inventory_valuation($wh ?: null);
$showCost = can('products.view_cost');
$byWh = []; $byProd = []; $byCat = [];
$catOf = $pdo->query('SELECT p.id, COALESCE(c.name, \'Uncategorised\') AS cat FROM products p LEFT JOIN categories c ON c.id = p.category_id')->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($val['rows'] as $r) {
    $byWh[$r['warehouse']] = ($byWh[$r['warehouse']] ?? ['units' => 0, 'value' => 0, 'products' => 0]); $byWh[$r['warehouse']]['units'] += $r['qty']; $byWh[$r['warehouse']]['value'] += $r['value']; $byWh[$r['warehouse']]['products']++;
    $k = $r['product_id']; $byProd[$k] = ($byProd[$k] ?? ['name' => $r['name'], 'code' => $r['code'], 'unit' => $r['base_unit'], 'on_hand' => 0, 'quarantine' => 0, 'damaged' => 0, 'value' => 0]);
    foreach (['on_hand', 'quarantine', 'damaged', 'value'] as $c) $byProd[$k][$c] += $r[$c];
    $cat = $catOf[$r['product_id']] ?? 'Uncategorised'; $byCat[$cat] = ($byCat[$cat] ?? 0) + $r['value'];
}
uasort($byProd, fn($a, $b) => $b['value'] <=> $a['value']); arsort($byCat);
$maxCat = max(1, ...array_values($byCat ?: [1]));
$low = low_stock_products(0, $wh ?: null);
$exp = expiring_batches((int) setting('expiry_warning_days', '90'), $wh ?: null);
$expValue = 0; foreach ($exp as $b) $expValue += ((int) $b['on_hand'] + (int) $b['quarantine']) * (float) $b['unit_cost'];
$damagedValue = 0; foreach ($val['rows'] as $r) $damagedValue += 0;
$blocked = (int) $pdo->query("SELECT COALESCE(SUM(s.on_hand),0) FROM stock s JOIN batches b ON b.id = s.batch_id WHERE b.status <> 'active'")->fetchColumn();
$whs = $pdo->query('SELECT id, name FROM warehouses ORDER BY name')->fetchAll();
$glInv = account_balance('1200');
if (input($_GET, 'export') === 'csv') { header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="inventory-valuation-' . date('Ymd') . '.csv"'); $o = fopen('php://output', 'w'); fputcsv($o, ['Code', 'Product', 'Warehouse', 'Saleable', 'Quarantine', 'Damaged', 'Value (cost)']); foreach ($val['rows'] as $r) fputcsv($o, [$r['code'], $r['name'], $r['warehouse'], $r['on_hand'], $r['quarantine'], $r['damaged'], $r['value']]); exit; }
$pageTitle = 'Inventory report';
require __DIR__ . '/../includes/header.php';
page_header('Inventory report', 'Stock value, expiry exposure and replenishment needs across all warehouses.', '<a class="btn btn-secondary" href="' . e(query_with(['export' => 'csv'])) . '">' . icon('download', 16) . ' Export CSV</a>');
nav_reports();
?>
<form method="get" class="filter-bar"><select class="form-control" name="wh" onchange="this.form.submit()"><option value="">All warehouses</option><?php foreach ($whs as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $wh === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></form>
<div class="grid grid-5 mb-4"><?= kpi('Inventory value', $showCost ? format_money_short($val['total']) : '—', 'warehouse', 'purple', number_format($val['units']) . ' units' . (!$wh && $val['in_transit'] > 0 ? ' · incl. ' . format_money_short($val['in_transit']) . ' in transit' : '')) ?><?= kpi('GL inventory', $showCost && can('reports.finance') ? format_money_short($glInv) : '—', 'book-open', 'gray', !$wh ? (abs($glInv - $val['total']) < 0.01 ? 'Matches the stock records ✓' : 'Differs from stock records') : 'All warehouses') ?><?= kpi('Low-stock products', (string) count($low), 'alert-triangle', count($low) ? 'amber' : 'gray', 'At or below reorder level') ?><?= kpi('Expiring ≤ ' . (int) setting('expiry_warning_days', '90') . 'd', (string) count($exp), 'calendar-clock', count($exp) ? 'red' : 'gray', $showCost ? format_money_short($expValue) . ' at cost' : '', '/trace/expiry.php') ?><?= kpi('Blocked units', number_format($blocked), 'ban', $blocked ? 'red' : 'gray', 'Quarantined, recalled or expired batches') ?></div>
<div class="grid grid-2 mb-4">
  <div class="card flush"><div class="card-head"><h3>Value by warehouse</h3></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Warehouse</th><th class="num">Products</th><th class="num">Units</th><?php if ($showCost): ?><th class="num">Value</th><?php endif; ?></tr></thead><tbody><?php foreach ($byWh as $n => $w): ?><tr><td class="strong"><?= e($n) ?></td><td class="num"><?= (int) $w['products'] ?></td><td class="num"><?= fmt_qty($w['units']) ?></td><?php if ($showCost): ?><td class="num strong"><?= format_money($w['value']) ?></td><?php endif; ?></tr><?php endforeach; ?><?php if (!$wh && $val['in_transit'] > 0): ?><tr><td class="strong">In transit</td><td class="num">—</td><td class="num"><?= fmt_qty($val['in_transit_units']) ?></td><?php if ($showCost): ?><td class="num strong"><?= format_money($val['in_transit']) ?></td><?php endif; ?></tr><?php endif; ?></tbody></table></div></div>
  <div class="card"><div class="card-head"><h3>Value by category</h3></div><?php foreach ($byCat as $n => $v): ?><div class="hbar-row"><span><?= e($n) ?></span><span class="hbar-track"><span class="hbar-fill" style="display:block;width:<?= round($v / $maxCat * 100) ?>%"></span></span><span class="hbar-val"><?= $showCost ? format_money_short($v) : '' ?></span></div><?php endforeach; ?></div>
</div>
<div class="card flush mb-4"><div class="card-head"><div><h3>Stock valuation by product</h3><div class="sub">Valued at cost — average cost, batch cost or serial cost depending on how each product is tracked</div></div></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th class="num">Saleable</th><th class="num">Quarantine</th><th class="num">Damaged</th><?php if ($showCost): ?><th class="num">Value (cost)</th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($byProd as $pid => $r): ?><tr><td><a class="strong" href="<?= e(app_url('/catalog/product_view.php?id=' . $pid)) ?>"><?= e($r['name']) ?></a><div class="cell-sub mono"><?= e($r['code']) ?> · <?= e($r['unit']) ?></div></td><td class="num"><?= fmt_qty($r['on_hand']) ?></td><td class="num <?= $r['quarantine'] ? 'text-warning' : 'text-muted' ?>"><?= $r['quarantine'] ? fmt_qty($r['quarantine']) : '—' ?></td><td class="num <?= $r['damaged'] ? 'text-error' : 'text-muted' ?>"><?= $r['damaged'] ? fmt_qty($r['damaged']) : '—' ?></td><?php if ($showCost): ?><td class="num strong"><?= format_money($r['value']) ?></td><?php endif; ?></tr><?php endforeach; ?></tbody>
  <?php if ($showCost): ?><tfoot><tr><td>Total</td><td colspan="3"></td><td class="num"><?= format_money($val['total']) ?></td></tr></tfoot><?php endif; ?></table></div></div>
<div class="card flush"><div class="card-head"><h3>Low-stock products</h3><?php if (can('purchase.create')): ?><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/purchasing/order_form.php?suggest=1')) ?>">Create purchase order</a><?php endif; ?></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th class="num">Reorder level</th><th class="num">Saleable</th><th class="num">Reserved</th><th class="num">Available</th></tr></thead><tbody><?php foreach ($low as $l): ?><tr><td class="strong"><a href="<?= e(app_url('/catalog/product_view.php?id=' . $l['id'])) ?>"><?= e($l['name']) ?></a></td><td class="num"><?= fmt_qty($l['reorder_level']) ?></td><td class="num"><?= fmt_qty($l['saleable']) ?></td><td class="num text-secondary"><?= fmt_qty($l['reserved']) ?></td><td class="num strong <?= $l['available'] <= 0 ? 'stock-out' : 'stock-low' ?>"><?= fmt_qty($l['available']) ?></td></tr><?php endforeach; ?></tbody></table><?php if (!$low) echo empty_state('check-circle-2', 'All stock levels are healthy'); ?></div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
