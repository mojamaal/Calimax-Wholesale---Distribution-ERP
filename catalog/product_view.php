<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('catalog.view');

$pdo = db();
$id = input_int($_GET, 'id');
$s = $pdo->prepare('SELECT p.*, c.name AS category FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = :id'); $s->execute([':id' => $id]); $p = $s->fetch();
if (!$p) { flash('error', 'Product not found.'); redirect('/catalog/products.php'); }
$showCost = can('products.view_cost');
$sum = stock_summary($id);
$byWh = stock_by_warehouse($id);
$units = $pdo->query('SELECT name, factor FROM product_units WHERE product_id = ' . $id . ' ORDER BY factor')->fetchAll();
$prices = $pdo->query('SELECT cc.name, pp.price FROM customer_categories cc LEFT JOIN product_prices pp ON pp.category_id = cc.id AND pp.product_id = ' . $id . ' ORDER BY cc.id')->fetchAll();
$breaks = $pdo->query('SELECT min_qty, discount_pct FROM product_price_breaks WHERE product_id = ' . $id . ' ORDER BY min_qty')->fetchAll();
$negotiated = $pdo->query('SELECT c.id, c.business_name, cp.price FROM customer_prices cp JOIN customers c ON c.id = cp.customer_id WHERE cp.product_id = ' . $id . ' ORDER BY c.business_name')->fetchAll();
$batches = $p['tracking'] === 'batch' ? $pdo->query("SELECT b.*, SUM(s.on_hand) oh, SUM(s.quarantine) qr, SUM(s.damaged) dm FROM batches b LEFT JOIN stock s ON s.batch_id = b.id WHERE b.product_id = $id GROUP BY b.id HAVING (oh + qr + dm) > 0 OR b.status = 'recalled' ORDER BY b.expiry_date IS NULL, b.expiry_date, b.id")->fetchAll() : [];
$serialStats = $p['tracking'] === 'serial' ? $pdo->query("SELECT status, COUNT(*) c FROM serial_numbers WHERE product_id = $id GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$moves = $pdo->query("SELECT sm.*, w.code AS wh FROM stock_movements sm JOIN warehouses w ON w.id = sm.warehouse_id WHERE sm.product_id = $id ORDER BY sm.id DESC LIMIT 12")->fetchAll();
$types = movement_types();
$valRows = inventory_valuation(null, $id);

$pageTitle = $p['name'];
require __DIR__ . '/../includes/header.php';
page_header($p['name'], '<span class="mono">' . e($p['code']) . '</span>' . ($p['brand'] ? ' · ' . e($p['brand']) : '') . ' · ' . e($p['category'] ?? 'Uncategorised'),
    (can('purchase.create') ? '<a class="btn btn-secondary" href="' . e(app_url('/purchasing/order_form.php?product_id=' . $id)) . '">' . icon('file-text', 16) . ' Order from supplier</a>' : '')
    . (can('catalog.manage') ? '<a class="btn btn-primary" href="' . e(app_url('/catalog/product_form.php?id=' . $id)) . '">' . icon('pencil', 16) . ' Edit</a>' : ''), [['Catalogue', '/catalog/products.php'], [$p['name'], null]]);
?>
<div class="stat-strip">
  <div><div class="sl">Available to sell</div><div class="sv <?= $sum['available'] <= 0 ? 'text-error' : '' ?>"><?= fmt_qty($sum['available']) ?></div></div>
  <div><div class="sl">Reserved (orders)</div><div class="sv"><?= fmt_qty($sum['reserved']) ?></div></div>
  <div><div class="sl">Saleable on hand</div><div class="sv"><?= fmt_qty($sum['saleable']) ?></div></div>
  <div><div class="sl">Blocked / quarantine</div><div class="sv <?= ($sum['blocked'] + $sum['quarantine']) ? 'text-warning' : '' ?>"><?= fmt_qty($sum['blocked'] + $sum['quarantine']) ?></div></div>
  <div><div class="sl">Damaged</div><div class="sv"><?= fmt_qty($sum['damaged']) ?></div></div>
  <?php if ($showCost): ?><div><div class="sl">Stock value (cost)</div><div class="sv"><?= format_money_short($valRows['total']) ?></div></div><?php endif; ?>
</div>

<div class="layout-2-1 mb-4">
  <div class="card flush">
    <div class="card-head"><h3>Stock by warehouse</h3><span class="text-muted text-sm">Unit: <?= e($p['base_unit']) ?></span></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Warehouse</th><th class="num">Saleable</th><th class="num">Reserved</th><th class="num">Available</th><th class="num">Quarantine</th><th class="num">Damaged</th></tr></thead>
      <tbody>
      <?php foreach ($byWh as $w): ?><tr><td class="strong"><a href="<?= e(app_url('/warehouse/stock.php?wh=' . $w['warehouse_id'] . '&q=' . urlencode($p['code']))) ?>"><?= e($w['name']) ?></a></td><td class="num"><?= fmt_qty($w['saleable']) ?></td><td class="num text-secondary"><?= fmt_qty($w['reserved']) ?></td><td class="num strong <?= $w['available'] <= 0 ? 'stock-out' : '' ?>"><?= fmt_qty($w['available']) ?></td><td class="num"><?= fmt_qty($w['quarantine']) ?: '—' ?></td><td class="num"><?= fmt_qty($w['damaged']) ?: '—' ?></td></tr><?php endforeach; ?>
      </tbody>
      <tfoot><tr><td>Total</td><td class="num"><?= fmt_qty($sum['saleable']) ?></td><td class="num"><?= fmt_qty($sum['reserved']) ?></td><td class="num"><?= fmt_qty($sum['available']) ?></td><td class="num"><?= fmt_qty($sum['quarantine']) ?></td><td class="num"><?= fmt_qty($sum['damaged']) ?></td></tr></tfoot>
    </table></div>
  </div>
  <div class="card">
    <h3 class="mb-3">Product facts</h3>
    <dl class="desc-list">
      <dt>Tracking</dt><dd><?= $p['tracking'] === 'none' ? 'Quantity only' : '<span class="tag ' . $p['tracking'] . '">' . e($p['tracking']) . '</span>' . ($p['track_expiry'] ? ' <span class="tag exp">expiry</span>' : '') ?></dd>
      <dt>Base unit</dt><dd><?= e($p['base_unit']) ?></dd>
      <?php foreach ($units as $u): ?><dt>1 <?= e($u['name']) ?></dt><dd><?= (int) $u['factor'] ?> <?= e($p['base_unit']) ?></dd><?php endforeach; ?>
      <dt>Min. order</dt><dd><?= fmt_qty($p['min_order_qty']) ?> <?= e($p['base_unit']) ?></dd>
      <dt>Reorder level</dt><dd><?= $p['reorder_level'] ? fmt_qty($p['reorder_level']) : '—' ?></dd>
      <dt><?= e(setting('tax_name', 'VAT')) ?></dt><dd><?= rtrim(rtrim(number_format($p['tax_rate'], 2), '0'), '.') ?>%</dd>
      <?php if ($p['tracking'] === 'serial'): ?><dt>Warranty</dt><dd><?= (int) $p['warranty_months'] ? (int) $p['warranty_months'] . ' months' : 'None' ?></dd><?php endif; ?>
      <?php if ($showCost): ?><dt>Average cost</dt><dd><?= format_money($p['avg_cost']) ?></dd><?php endif; ?>
      <?php if ($p['barcode']): ?><dt>Barcode</dt><dd class="mono"><?= e($p['barcode']) ?></dd><?php endif; ?>
    </dl>
  </div>
</div>

<div class="grid grid-2 mb-4">
  <div class="card flush">
    <div class="card-head"><h3>Wholesale prices</h3><span class="text-muted text-sm">per <?= e($p['base_unit']) ?><?= $units ? ' · per ' . e(end($units)['name']) . ' in brackets' : '' ?></span></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Customer type</th><th class="num">Price</th><?php if ($showCost): ?><th class="num">Margin</th><?php endif; ?></tr></thead>
      <tbody>
      <?php $big = $units ? (int) end($units)['factor'] : 1; $rows = array_merge([['name' => 'Default', 'price' => $p['default_price']]], $prices);
      foreach ($rows as $r): $pr = $r['price'] ?? $p['default_price']; $m = $pr > 0 && $p['avg_cost'] > 0 ? ($pr - $p['avg_cost']) / $pr * 100 : null; ?>
        <tr><td><?= e($r['name']) ?><?= $r['price'] === null ? ' <span class="text-muted text-sm">(uses default)</span>' : '' ?></td><td class="num strong"><?= format_money($pr) ?><?= $big > 1 ? '<div class="cell-sub">' . format_money($pr * $big) . '</div>' : '' ?></td><?php if ($showCost): ?><td class="num <?= $m !== null && $m < 5 ? 'text-error' : 'text-secondary' ?>"><?= $m === null ? '—' : number_format($m, 1) . '%' ?></td><?php endif; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($breaks): ?><div style="padding:14px 18px;border-top:1px solid var(--border);"><div class="text-sm fw-700 mb-2">Bulk quantity discounts</div><?php foreach ($breaks as $b): ?><span class="badge badge-info" style="margin-right:6px;">≥ <?= fmt_qty($b['min_qty']) ?> → <?= rtrim(rtrim(number_format($b['discount_pct'], 2), '0'), '.') ?>% off</span><?php endforeach; ?></div><?php endif; ?>
    <?php if ($negotiated): ?><div style="padding:14px 18px;border-top:1px solid var(--border);"><div class="text-sm fw-700 mb-2">Negotiated customer prices</div><?php foreach ($negotiated as $n): ?><div class="summary-row"><a class="link" href="<?= e(app_url('/sales/customer_view.php?id=' . $n['id'])) ?>"><?= e($n['business_name']) ?></a><strong><?= format_money($n['price']) ?></strong></div><?php endforeach; ?></div><?php endif; ?>
  </div>

  <?php if ($p['tracking'] === 'batch'): ?>
  <div class="card flush">
    <div class="card-head"><h3>Batches</h3><a class="btn-ghost btn-sm" href="<?= e(app_url('/trace/batches.php?q=' . urlencode($p['code']))) ?>">All batches →</a></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Batch</th><th>Expiry</th><th class="num">On hand</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($batches as $b): ?><tr><td class="strong"><a href="<?= e(app_url('/trace/batch_view.php?id=' . $b['id'])) ?>"><?= e($b['batch_no']) ?></a></td><td><?= $b['expiry_date'] ? e(fmt_date($b['expiry_date'])) . '<div class="cell-sub">' . expiry_badge($b['expiry_date']) . '</div>' : '<span class="text-muted">—</span>' ?></td><td class="num"><?= fmt_qty($b['oh']) ?><?= (int) $b['qr'] ? '<div class="cell-sub">+' . fmt_qty($b['qr']) . ' quarantine</div>' : '' ?></td><td><?= status_badge($b['status']) ?></td></tr><?php endforeach; ?>
      </tbody>
    </table><?php if (!$batches) echo empty_state('layers', 'No batches in stock', 'Batches are created when goods are received.'); ?></div>
  </div>
  <?php elseif ($p['tracking'] === 'serial'): ?>
  <div class="card">
    <div class="card-head"><h3>Serial numbers</h3><a class="btn-ghost btn-sm" href="<?= e(app_url('/trace/serials.php?product=' . $id)) ?>">Browse all →</a></div>
    <div class="list-rows">
      <?php foreach (serial_status_options() as $k => $l): if (empty($serialStats[$k])) continue; ?><a href="<?= e(app_url('/trace/serials.php?product=' . $id . '&status=' . $k)) ?>"><span><?= status_badge($k) ?></span><strong><?= (int) $serialStats[$k] ?></strong></a><?php endforeach; ?>
      <?php if (!$serialStats) echo '<div class="text-muted text-sm">No serial numbers registered yet.</div>'; ?>
    </div>
  </div>
  <?php else: ?>
  <div class="card"><?= empty_state('package', 'Quantity-tracked product', 'Stock is counted per warehouse. Use batch or serial tracking for expiry, recall or warranty control.') ?></div>
  <?php endif; ?>
</div>

<div class="card flush">
  <div class="card-head"><h3>Recent stock movements</h3><a class="btn-ghost btn-sm" href="<?= e(app_url('/warehouse/movements.php?product=' . $id)) ?>">Full history →</a></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>When</th><th>Movement</th><th>Warehouse</th><th>Reference</th><th class="num">Change</th><th class="num">Balance</th></tr></thead>
    <tbody>
    <?php foreach ($moves as $m): $t = $types[$m['type']] ?? [ucwords(str_replace('_', ' ', $m['type'])), 'neutral']; ?>
      <tr><td class="nowrap text-secondary"><?= e(fmt_datetime($m['created_at'])) ?></td><td><span class="badge badge-<?= $t[1] ?>"><?= e($t[0]) ?></span><?= $m['bucket'] !== 'on_hand' ? ' <span class="text-muted text-sm">' . e($m['bucket']) . '</span>' : '' ?></td><td><?= e($m['wh']) ?></td><td class="mono text-secondary"><?= e($m['reference_no'] ?? '') ?></td><td class="num strong <?= $m['change_qty'] < 0 ? 'text-error' : 'text-success' ?>"><?= $m['change_qty'] > 0 ? '+' : '' ?><?= fmt_qty($m['change_qty']) ?></td><td class="num text-secondary"><?= fmt_qty($m['balance_after']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table><?php if (!$moves) echo empty_state('history', 'No movements yet'); ?></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
