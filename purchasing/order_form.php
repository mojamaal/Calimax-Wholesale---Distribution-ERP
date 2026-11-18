<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.create');

$pdo = db();
$id = input_int($_GET, 'id'); $po = null;
if ($id) { $s = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id'); $s->execute([':id' => $id]); $po = $s->fetch();
    if (!$po || !in_array($po['status'], ['draft', 'rejected'], true)) { flash('error', 'Only draft purchase orders can be edited.'); redirect($po ? '/purchasing/order_view.php?id=' . $id : '/purchasing/orders.php'); } }
$suppliers = $pdo->query('SELECT id, name, payment_terms_days FROM suppliers WHERE is_active = 1 ORDER BY name')->fetchAll();
$whs = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY id')->fetchAll();
$errors = [];

if (is_post()) {
    verify_csrf();
    $items = json_decode($_POST['items_json'] ?? '[]', true) ?: [];
    $d = ['supplier_id' => input_int($_POST, 'supplier_id'), 'warehouse_id' => input_int($_POST, 'warehouse_id'), 'order_date' => input_date($_POST, 'order_date', date('Y-m-d')), 'expected_date' => input_date($_POST, 'expected_date'), 'notes' => input($_POST, 'notes')];
    try {
        $pid = po_save($po['id'] ?? null, $d, $items);
        if (input($_POST, 'action') === 'submit') { $st = po_submit($pid); flash($st === 'approved' ? 'success' : 'info', $st === 'approved' ? 'Purchase order approved. Send it to the supplier.' : 'Purchase order sent for approval — it is above the approval threshold.'); }
        else flash('success', 'Draft saved.');
        redirect('/purchasing/order_view.php?id=' . $pid);
    } catch (RuntimeException $e) { $errors[] = $e->getMessage(); }
    $prefillSrc = $items; $po = array_merge($po ?? [], $d);
}
$lineSrc = [];
if (isset($prefillSrc)) $lineSrc = $prefillSrc;
elseif ($po) { $s = $pdo->prepare('SELECT * FROM purchase_order_items WHERE po_id = :id ORDER BY id'); $s->execute([':id' => $po['id']]); foreach ($s->fetchAll() as $r) $lineSrc[] = ['product_id' => $r['product_id'], 'pack_name' => $r['pack_name'], 'pack_factor' => $r['pack_factor'], 'pack_qty' => $r['qty'] / $r['pack_factor'], 'unit_cost' => (float) $r['unit_cost'] * $r['pack_factor']]; }
elseif (input_int($_GET, 'product_id')) $lineSrc[] = ['product_id' => input_int($_GET, 'product_id'), 'pack_factor' => 0, 'pack_qty' => 1];
elseif (input($_GET, 'suggest') === '1') foreach (low_stock_products(60) as $l) $lineSrc[] = ['product_id' => $l['id'], 'pack_factor' => 0, 'pack_qty' => 0, 'suggest' => max(1, (int) $l['reorder_level'] * 2 - (int) $l['available'])];
$prefill = [];
foreach ($lineSrc as $l) {
    $ps = $pdo->prepare('SELECT * FROM products WHERE id = :id'); $ps->execute([':id' => (int) $l['product_id']]); $p = $ps->fetch(); if (!$p) continue;
    $u = $pdo->prepare('SELECT name, factor FROM product_units WHERE product_id = :id ORDER BY factor'); $u->execute([':id' => $p['id']]);
    $packs = array_merge([['name' => $p['base_unit'], 'factor' => 1]], array_map(fn($x) => ['name' => $x['name'], 'factor' => (int) $x['factor']], $u->fetchAll()));
    $factor = (int) ($l['pack_factor'] ?? 0); $big = end($packs);
    if ($factor <= 0) { $factor = (int) $big['factor']; $l['pack_name'] = $big['name']; $l['unit_cost'] = (float) $p['avg_cost'] * $factor; $l['pack_qty'] = !empty($l['suggest']) ? max(1, (int) ceil($l['suggest'] / $factor)) : ($l['pack_qty'] ?: 1); }
    $prefill[] = ['product_id' => (int) $p['id'], 'code' => $p['code'], 'name' => $p['name'], 'tracking' => $p['tracking'], 'base_unit' => $p['base_unit'], 'packs' => $packs, 'pack_name' => $l['pack_name'], 'pack_factor' => $factor, 'pack_qty' => (float) $l['pack_qty'],
        'unit_price' => (float) $l['unit_cost'] / $factor, 'list_price' => 0, 'break_pct' => 0, 'discount_pct' => 0, 'tax_rate' => (float) $p['tax_rate'], 'stock' => stock_summary((int) $p['id'])['physical'], 'min_order_qty' => 0, 'source' => '', 'cost' => (float) $p['avg_cost']];
}
$editing = $po && isset($po['id']);
$supSel = (int) ($po['supplier_id'] ?? input_int($_GET, 'supplier_id'));
$threshold = (float) setting('po_approval_threshold', '0');
$pageTitle = $editing ? 'Edit ' . $po['po_no'] : 'New purchase order';
$pageScripts = [app_url('/assets/js/lines.js') . '?v=1'];
require __DIR__ . '/../includes/header.php';
page_header($editing ? 'Edit ' . $po['po_no'] : 'New purchase order', 'A purchase order requests goods from a supplier. It does not change stock — stock increases when the delivery is received.', '<a class="btn btn-secondary" href="' . e(app_url($editing ? '/purchasing/order_view.php?id=' . $po['id'] : '/purchasing/orders.php')) . '">Cancel</a>', [['Purchase orders', '/purchasing/orders.php'], [$editing ? $po['po_no'] : 'New order', null]]);
foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<form method="post" id="poForm"><?= csrf_field() ?><input type="hidden" name="items_json" id="items_json"><input type="hidden" name="action" id="act" value="draft">
  <div class="card mb-4"><div class="form-row">
    <div class="form-group" style="flex:2"><label>Supplier</label><select class="form-control" name="supplier_id" required><option value="">Select supplier…</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $supSel === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> · <?= (int) $s['payment_terms_days'] ?>d terms</option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Deliver to warehouse</label><select class="form-control" name="warehouse_id"><?php foreach ($whs as $w): ?><option value="<?= (int) $w['id'] ?>" <?= (int) ($po['warehouse_id'] ?? 0) === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Order date</label><input class="form-control" type="date" name="order_date" value="<?= e($po['order_date'] ?? date('Y-m-d')) ?>"></div>
    <div class="form-group"><label>Expected delivery</label><input class="form-control" type="date" name="expected_date" value="<?= e($po['expected_date'] ?? date('Y-m-d', strtotime('+5 days'))) ?>"></div></div>
    <div class="form-group mb-0"><label>Notes to supplier <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="notes" maxlength="255" value="<?= e($po['notes'] ?? '') ?>"></div></div>
  <div class="card flush"><div class="card-head"><div><h3>Items to order</h3><div class="sub">Quantities are entered in cartons / boxes where available. Costs are per selected unit.</div></div>
    <div class="flex gap-2 flex-wrap"><div style="position:relative;width:min(380px,100%);"><input class="form-control" type="text" id="itemSearch" placeholder="Search products to add…" autocomplete="off"><div class="search-results" id="searchResults" hidden></div></div><button class="btn btn-secondary" type="button" onclick="location.href='?suggest=1'"><?= icon('alert-triangle', 15) ?> Add low-stock items</button></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th class="num">On hand</th><th>Unit</th><th>Qty</th><th>Cost / unit</th><th class="num">Line total</th><th></th></tr></thead><tbody id="itemsBody"></tbody></table></div>
    <div id="emptyItems"><?= empty_state('package-search', 'No items yet', 'Search above, or add everything that needs restocking.') ?></div>
    <div class="pagination" style="align-items:flex-start;"><span class="text-muted text-sm" id="lineCount"></span><div class="totals-box" id="totals"></div></div></div>
  <div class="action-bar"><span class="text-muted text-sm"><?= $threshold > 0 ? 'Orders of ' . format_money($threshold) . ' or more need approval from Accounts.' : 'Orders are approved immediately.' ?></span><div class="flex gap-2"><button class="btn btn-secondary" type="submit" data-no-busy onclick="document.getElementById('act').value='draft'">Save draft</button><button class="btn btn-primary btn-lg" type="submit" data-no-busy onclick="document.getElementById('act').value='submit'"><?= icon('send', 16) ?> Submit order</button></div></div>
</form>
<script>
(function () {
  const ed = CalimaxLines.LineEditor({ mode: 'purchase', body: document.getElementById('itemsBody'), search: document.getElementById('itemSearch'), results: document.getElementById('searchResults'), jsonInput: document.getElementById('items_json'), totalsEl: document.getElementById('totals'),
    emptyEl: document.getElementById('emptyItems'), canEditPrice: true, items: <?= js_json($prefill) ?>, onChange: (t) => { document.getElementById('lineCount').textContent = t.lines + ' line(s) · ' + t.units.toLocaleString() + ' units'; } });
  document.getElementById('poForm').addEventListener('submit', (e) => { if (!ed.items().length) { e.preventDefault(); CalimaxUI.toast('Add at least one product.', 'error'); } });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
