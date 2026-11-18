<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('catalog.view');

$pdo = db();
$q = input($_GET, 'q'); $cat = input_int($_GET, 'cat'); $track = input($_GET, 'track'); $stockF = input($_GET, 'stock');
$page = max(1, input_int($_GET, 'page', 1)); $perPage = 40;
$showCost = can('products.view_cost');

$where = 'p.is_active = 1'; $params = [];
if ($q !== '') { $where .= ' AND (p.name LIKE :q1 OR p.code LIKE :q2 OR p.barcode = :q3 OR p.brand LIKE :q4)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => $q, ':q4' => "%$q%"]; }
if ($cat) { $where .= ' AND p.category_id = :cat'; $params[':cat'] = $cat; }
if (in_array($track, ['none', 'batch', 'serial'], true)) { $where .= ' AND p.tracking = :tr'; $params[':tr'] = $track; }
$sal = sql_saleable('p'); $res = sql_reserved('p');
$having = $stockF === 'low' ? 'HAVING p.reorder_level > 0 AND (saleable - reserved) <= p.reorder_level AND (saleable - reserved) > 0' : ($stockF === 'out' ? 'HAVING (saleable - reserved) <= 0' : '');
$base = "FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE $where";
$list = $pdo->prepare("SELECT p.*, c.name AS category, $sal AS saleable, $res AS reserved $base $having ORDER BY p.name");
$list->execute($params);
$all = $list->fetchAll();
$total = count($all);
$rows = array_slice($all, ($page - 1) * $perPage, $perPage);
$cats = $pdo->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$packs = [];
foreach ($pdo->query('SELECT product_id, name, factor FROM product_units ORDER BY factor')->fetchAll() as $u) $packs[$u['product_id']][] = $u['name'] . ' = ' . $u['factor'];

$pageTitle = 'Products';
require __DIR__ . '/../includes/header.php';
page_header('Catalogue', 'Every product, its packaging, tracking method and wholesale prices.', can('catalog.manage') ? '<a class="btn btn-primary" href="' . e(app_url('/catalog/product_form.php')) . '">' . icon('plus', 16) . ' New product</a>' : '');
nav_catalog();
?>
<div class="card flush">
  <div style="padding:16px 16px 0;">
    <form method="get" class="filter-bar">
      <div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, code, barcode or brand"></div>
      <select class="form-control" name="cat" onchange="this.form.submit()"><option value="">All categories</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $cat === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
      <select class="form-control" name="track" onchange="this.form.submit()"><option value="">Any tracking</option><option value="none" <?= $track === 'none' ? 'selected' : '' ?>>Quantity only</option><option value="batch" <?= $track === 'batch' ? 'selected' : '' ?>>Batch / lot</option><option value="serial" <?= $track === 'serial' ? 'selected' : '' ?>>Serial number</option></select>
      <div class="chip-group"><a class="chip <?= $stockF === '' ? 'active' : '' ?>" href="<?= e(query_with(['stock' => null, 'page' => null])) ?>">All</a><a class="chip <?= $stockF === 'low' ? 'active' : '' ?>" href="<?= e(query_with(['stock' => 'low', 'page' => null])) ?>">Low stock</a><a class="chip <?= $stockF === 'out' ? 'active' : '' ?>" href="<?= e(query_with(['stock' => 'out', 'page' => null])) ?>">Out of stock</a></div>
    </form>
  </div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Product</th><th>Category</th><th>Packaging</th><th>Tracking</th><?php if ($showCost): ?><th class="num">Avg cost</th><?php endif; ?><th class="num">Default price</th><th class="num">Available</th><th class="num">Reserved</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): $avail = (int) $p['saleable'] - (int) $p['reserved']; $lowF = $p['reorder_level'] > 0 && $avail <= $p['reorder_level']; ?>
      <tr>
        <td><a class="strong" href="<?= e(app_url('/catalog/product_view.php?id=' . $p['id'])) ?>"><?= e($p['name']) ?></a><div class="cell-sub mono"><?= e($p['code']) ?><?= $p['brand'] ? ' · ' . e($p['brand']) : '' ?></div></td>
        <td class="text-secondary"><?= e($p['category'] ?? '—') ?></td>
        <td class="text-secondary text-sm"><?= e($p['base_unit']) ?><?= isset($packs[$p['id']]) ? '<div class="cell-sub">' . e(implode(', ', $packs[$p['id']])) . '</div>' : '' ?></td>
        <td><?= $p['tracking'] === 'none' ? '<span class="text-muted text-sm">Quantity</span>' : '<span class="tag ' . $p['tracking'] . '">' . e($p['tracking']) . '</span>' . ($p['track_expiry'] ? ' <span class="tag exp">expiry</span>' : '') ?></td>
        <?php if ($showCost): ?><td class="num text-secondary"><?= format_money($p['avg_cost']) ?></td><?php endif; ?>
        <td class="num"><?= format_money($p['default_price']) ?></td>
        <td class="num <?= $avail <= 0 ? 'stock-out' : ($lowF ? 'stock-low' : 'stock-ok') ?>"><?= fmt_qty($avail) ?></td>
        <td class="num text-secondary"><?= fmt_qty($p['reserved']) ?></td>
        <td class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/catalog/product_view.php?id=' . $p['id'])) ?>">View</a><?php if (can('catalog.manage')): ?><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/catalog/product_form.php?id=' . $p['id'])) ?>">Edit</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows) echo empty_state('package', 'No products found', 'Try a different search or add a new product.'); ?></div>
  <?php render_pagination($page, $perPage, $total); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
