<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('catalog.view');

$pdo = db();
$canEdit = can('pricing.manage'); $showCost = can('products.view_cost');
$cats = $pdo->query('SELECT id, name FROM customer_categories ORDER BY id')->fetchAll();
$q = input($_GET, 'q'); $catF = input_int($_GET, 'cat');

if (is_post() && $canEdit) {
    verify_csrf();
    $changed = 0;
    $ps = $pdo->query('SELECT id, name, default_price FROM products WHERE is_active = 1')->fetchAll();
    $existing = [];
    foreach ($pdo->query('SELECT product_id, category_id, price FROM product_prices') as $r) $existing[$r['product_id']][$r['category_id']] = (float) $r['price'];
    $pdo->beginTransaction();
    try {
        foreach ($ps as $p) {
            $pid = (int) $p['id'];
            if (!isset($_POST['default'][$pid])) continue;
            $nd = round(max(0, (float) $_POST['default'][$pid]), 2);
            if (abs($nd - (float) $p['default_price']) > 0.0049) { $pdo->prepare('UPDATE products SET default_price = :d WHERE id = :id')->execute([':d' => $nd, ':id' => $pid]); log_change('price_change', 'Default wholesale price', format_money($p['default_price']), format_money($nd), 'product', $pid, $p['name']); $changed++; }
            foreach ($cats as $c) {
                $raw = $_POST['price'][$pid][$c['id']] ?? ''; $old = $existing[$pid][$c['id']] ?? null;
                if ($raw === '' || !is_numeric($raw) || (float) $raw <= 0) {
                    if ($old !== null) { $pdo->prepare('DELETE FROM product_prices WHERE product_id = :p AND category_id = :c')->execute([':p' => $pid, ':c' => $c['id']]); log_change('price_change', $c['name'] . ' price', format_money($old), 'removed', 'product', $pid, $p['name']); $changed++; }
                    continue;
                }
                $np = round((float) $raw, 2);
                if ($old === null || abs($np - $old) > 0.0049) {
                    $pdo->prepare('INSERT INTO product_prices (product_id, category_id, price) VALUES (:p,:c,:pr) ON DUPLICATE KEY UPDATE price = :pr2')->execute([':p' => $pid, ':c' => $c['id'], ':pr' => $np, ':pr2' => $np]);
                    log_change('price_change', $c['name'] . ' price', $old === null ? null : format_money($old), format_money($np), 'product', $pid, $p['name']); $changed++;
                }
            }
        }
        $pdo->commit();
        flash('success', $changed ? "$changed price(s) updated and recorded in the audit log." : 'No price changes to save.');
    } catch (Throwable $e) { $pdo->rollBack(); flash('error', 'Could not save prices.'); }
    redirect('/catalog/pricing.php' . query_with([]));
}

$where = 'p.is_active = 1'; $params = [];
if ($q !== '') { $where .= ' AND (p.name LIKE :q1 OR p.code LIKE :q2)'; $params += [':q1' => "%$q%", ':q2' => "%$q%"]; }
if ($catF) { $where .= ' AND p.category_id = :c'; $params[':c'] = $catF; }
$s = $pdo->prepare("SELECT p.*, (SELECT MAX(factor) FROM product_units pu WHERE pu.product_id = p.id) AS big_pack, (SELECT name FROM product_units pu WHERE pu.product_id = p.id ORDER BY factor DESC LIMIT 1) AS big_name FROM products p WHERE $where ORDER BY p.name");
$s->execute($params); $products = $s->fetchAll();
$tier = [];
foreach ($pdo->query('SELECT product_id, category_id, price FROM product_prices') as $r) $tier[$r['product_id']][$r['category_id']] = $r['price'];
$productCats = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$negotiatedCount = $pdo->query('SELECT COUNT(*) FROM customer_prices')->fetchColumn();

$pageTitle = 'Price lists';
require __DIR__ . '/../includes/header.php';
page_header('Price lists', 'Wholesale selling price per customer category, all in one grid. Prices are per base unit, excluding tax.', $canEdit ? '<button class="btn btn-primary" type="submit" form="priceForm">' . icon('save', 16) . ' Save changes</button>' : '');
nav_catalog();
?>
<?php if (!$canEdit): ?><div class="callout mb-4">You can view prices here. Only a Sales Manager or the owner can change them.</div><?php endif; ?>
<div class="callout info mb-4"><strong>How pricing works:</strong> a customer pays their own negotiated price if one exists (<?= (int) $negotiatedCount ?> set on customers), otherwise the price for their customer category, otherwise the default price. Bulk-quantity discounts then apply automatically on category / default prices.</div>
<form method="get" class="filter-bar">
  <div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search product"></div>
  <select class="form-control" name="cat" onchange="this.form.submit()"><option value="">All categories</option><?php foreach ($productCats as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $catF === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
</form>
<form method="post" id="priceForm"><?= csrf_field() ?>
<div class="card flush"><div class="table-wrap"><table class="data-table">
  <thead><tr><th>Product</th><?php if ($showCost): ?><th class="num">Avg cost</th><?php endif; ?><th class="num" style="width:130px;">Default</th><?php foreach ($cats as $c): ?><th class="num" style="width:130px;"><?= e($c['name']) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($products as $p): $pid = (int) $p['id']; ?>
    <tr>
      <td><a class="strong" href="<?= e(app_url('/catalog/product_view.php?id=' . $pid)) ?>"><?= e($p['name']) ?></a><div class="cell-sub">per <?= e($p['base_unit']) ?><?= $p['big_pack'] ? ' · ' . e($p['big_name']) . ' = ' . (int) $p['big_pack'] : '' ?></div></td>
      <?php if ($showCost): ?><td class="num text-secondary"><?= number_format($p['avg_cost'], 2) ?></td><?php endif; ?>
      <td><input class="form-control num-input" type="number" step="0.01" min="0" name="default[<?= $pid ?>]" value="<?= e((string) $p['default_price']) ?>" <?= $canEdit ? '' : 'readonly' ?>></td>
      <?php foreach ($cats as $c): ?><td><input class="form-control num-input" type="number" step="0.01" min="0" name="price[<?= $pid ?>][<?= (int) $c['id'] ?>]" value="<?= isset($tier[$pid][$c['id']]) ? e((string) $tier[$pid][$c['id']]) : '' ?>" placeholder="default" <?= $canEdit ? '' : 'readonly' ?>></td><?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table><?php if (!$products) echo empty_state('tags', 'No products match'); ?></div></div>
<?php if ($canEdit && $products): ?><div class="action-bar"><span class="text-muted text-sm">Leave a category price empty to use the default price. Every change is written to the audit log.</span><button class="btn btn-primary btn-lg" type="submit"><?= icon('save', 16) ?> Save changes</button></div><?php endif; ?>
</form>
<?php require __DIR__ . '/../includes/footer.php'; ?>
