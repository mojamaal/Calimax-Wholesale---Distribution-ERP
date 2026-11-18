<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('inventory.view');

$pdo = db();
$wh = input_int($_GET, 'wh'); $q = input($_GET, 'q'); $cat = input_int($_GET, 'cat'); $filter = input($_GET, 'filter'); $view = input($_GET, 'view', 'product');
$showCost = can('products.view_cost');
$whs = $pdo->query('SELECT id, name, code FROM warehouses WHERE is_active = 1 ORDER BY id')->fetchAll();
$cats = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

// One row per product with per-warehouse saleable quantities + totals.
$where = 'p.is_active = 1'; $params = [];
if ($q !== '') { $where .= ' AND (p.name LIKE :q1 OR p.code LIKE :q2)'; $params += [':q1' => "%$q%", ':q2' => "%$q%"]; }
if ($cat) { $where .= ' AND p.category_id = :c'; $params[':c'] = $cat; }
$cols = '';
foreach ($whs as $w) $cols .= ', ' . sql_saleable('p', (int) $w['id']) . ' AS wh_' . (int) $w['id'];
$st = $pdo->prepare('SELECT p.id, p.code, p.name, p.base_unit, p.tracking, p.reorder_level, p.avg_cost, ' . sql_saleable('p') . ' AS saleable, ' . sql_reserved('p') . ' AS reserved,
    (SELECT COALESCE(SUM(quarantine),0) FROM stock WHERE product_id = p.id) AS quarantine, (SELECT COALESCE(SUM(damaged),0) FROM stock WHERE product_id = p.id) AS damaged ' . $cols . ' FROM products p WHERE ' . $where . ' ORDER BY p.name');
$st->execute($params);
$rows = $st->fetchAll();
if ($wh) { // single-warehouse view overrides totals
    $rows = array_map(function ($r) use ($wh) { $s = stock_summary((int) $r['id'], $wh); $r['saleable'] = $s['saleable']; $r['reserved'] = $s['reserved']; $r['quarantine'] = $s['quarantine']; $r['damaged'] = $s['damaged']; return $r; }, $rows);
}
$rows = array_values(array_filter($rows, function ($r) use ($filter) {
    $avail = $r['saleable'] - $r['reserved'];
    return match ($filter) { 'low' => $r['reorder_level'] > 0 && $avail <= $r['reorder_level'], 'out' => $avail <= 0, 'quarantine' => ($r['quarantine'] + $r['damaged']) > 0, default => ($r['saleable'] + $r['quarantine'] + $r['damaged']) > 0 || $r['reorder_level'] > 0 };
}));
$val = inventory_valuation($wh ?: null);
$valBy = []; foreach ($val['rows'] as $v) $valBy[$v['product_id']] = ($valBy[$v['product_id']] ?? 0) + $v['value'];
$whTotals = []; foreach ($val['rows'] as $v) { $whTotals[$v['warehouse_id']] = ($whTotals[$v['warehouse_id']] ?? ['v' => 0, 'u' => 0]); $whTotals[$v['warehouse_id']]['v'] += $v['value']; $whTotals[$v['warehouse_id']]['u'] += $v['qty']; }
$lowCount = count(low_stock_products(0, $wh ?: null));

$pageTitle = 'Stock by warehouse';
require __DIR__ . '/../includes/header.php';
page_header('Stock', 'Live stock by warehouse: what is physically here, what is reserved for orders and what is free to sell.', (can('inventory.transfer') ? '<a class="btn btn-secondary" href="' . e(app_url('/warehouse/transfer_form.php')) . '">' . icon('arrow-left-right', 16) . ' Transfer stock</a>' : '') . (can('purchase.create') ? '<a class="btn btn-primary" href="' . e(app_url('/purchasing/order_form.php?suggest=1')) . '">' . icon('file-text', 16) . ' Reorder low stock</a>' : ''));
nav_warehouse();
?>
<div class="grid grid-<?= min(5, count($whs) + 1) ?> mb-4">
  <?= kpi('All warehouses', format_money_short($val['total'] - ($wh ? 0 : 0)), 'warehouse', 'purple', number_format($val['units']) . ' units at cost' . (!$wh && $val['in_transit'] > 0 ? ' · ' . format_money_short($val['in_transit']) . ' in transit' : ''), '/warehouse/stock.php') ?>
  <?php foreach ($whs as $w): ?><?= kpi($w['name'], format_money_short($whTotals[$w['id']]['v'] ?? 0), 'building', $wh === (int) $w['id'] ? 'green' : 'gray', number_format($whTotals[$w['id']]['u'] ?? 0) . ' units', '/warehouse/stock.php?wh=' . $w['id']) ?><?php endforeach; ?>
</div>
<div class="card flush">
  <div style="padding:16px 16px 0;">
    <form method="get" class="filter-bar">
      <div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Product name or code"></div>
      <select class="form-control" name="wh" onchange="this.form.submit()"><option value="">All warehouses</option><?php foreach ($whs as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $wh === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select>
      <select class="form-control" name="cat" onchange="this.form.submit()"><option value="">All categories</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $cat === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
      <div class="chip-group"><a class="chip <?= $filter === '' ? 'active' : '' ?>" href="<?= e(query_with(['filter' => null])) ?>">In stock</a><a class="chip <?= $filter === 'low' ? 'active' : '' ?>" href="<?= e(query_with(['filter' => 'low'])) ?>">Low stock <span class="chip-count"><?= $lowCount ?></span></a><a class="chip <?= $filter === 'out' ? 'active' : '' ?>" href="<?= e(query_with(['filter' => 'out'])) ?>">Out of stock</a><a class="chip <?= $filter === 'quarantine' ? 'active' : '' ?>" href="<?= e(query_with(['filter' => 'quarantine'])) ?>">Quarantine / damaged</a></div>
    </form>
  </div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Product</th><?php if (!$wh): foreach ($whs as $w): ?><th class="num"><?= e($w['code']) ?></th><?php endforeach; endif; ?><th class="num">Saleable</th><th class="num">Reserved</th><th class="num">Available</th><th class="num">Quarantine</th><th class="num">Damaged</th><?php if ($showCost): ?><th class="num">Value (cost)</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $avail = $r['saleable'] - $r['reserved']; $low = $r['reorder_level'] > 0 && $avail <= $r['reorder_level']; ?>
      <tr>
        <td><a class="strong" href="<?= e(app_url('/catalog/product_view.php?id=' . $r['id'])) ?>"><?= e($r['name']) ?></a><div class="cell-sub"><span class="mono"><?= e($r['code']) ?></span> · <?= e($r['base_unit']) ?><?= $r['tracking'] !== 'none' ? ' <span class="tag ' . $r['tracking'] . '">' . e($r['tracking']) . '</span>' : '' ?><?= $r['reorder_level'] ? ' · reorder at ' . fmt_qty($r['reorder_level']) : '' ?></div></td>
        <?php if (!$wh): foreach ($whs as $w): ?><td class="num text-secondary"><?= (int) $r['wh_' . $w['id']] ? fmt_qty($r['wh_' . $w['id']]) : '—' ?></td><?php endforeach; endif; ?>
        <td class="num"><?= fmt_qty($r['saleable']) ?></td><td class="num text-secondary"><?= (int) $r['reserved'] ? fmt_qty($r['reserved']) : '—' ?></td>
        <td class="num strong <?= $avail <= 0 ? 'stock-out' : ($low ? 'stock-low' : 'stock-ok') ?>"><?= fmt_qty($avail) ?></td>
        <td class="num <?= $r['quarantine'] ? 'text-warning strong' : 'text-muted' ?>"><?= (int) $r['quarantine'] ? fmt_qty($r['quarantine']) : '—' ?></td><td class="num <?= $r['damaged'] ? 'text-error strong' : 'text-muted' ?>"><?= (int) $r['damaged'] ? fmt_qty($r['damaged']) : '—' ?></td>
        <?php if ($showCost): ?><td class="num"><?= format_money($valBy[$r['id']] ?? 0) ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows) echo empty_state('warehouse', 'No stock matches', 'Try another filter or receive goods against a purchase order.'); ?></div>
</div>
<div class="callout mt-4 text-sm"><strong>Saleable</strong> = in good condition and sellable (excludes expired, quarantined and recalled batches). <strong>Available</strong> = saleable minus stock reserved for confirmed orders. <strong>Quarantine</strong> = returned or rejected goods awaiting inspection.</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
