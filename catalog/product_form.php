<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('catalog.manage');

$pdo = db();
$id = input_int($_GET, 'id');
$p = null;
if ($id) { $s = $pdo->prepare('SELECT * FROM products WHERE id = :id'); $s->execute([':id' => $id]); $p = $s->fetch(); if (!$p) { flash('error', 'Product not found.'); redirect('/catalog/products.php'); } }
$cats = $pdo->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$custCats = $pdo->query('SELECT id, name FROM customer_categories ORDER BY id')->fetchAll();
$hasMovement = $p ? (int) $pdo->query('SELECT COUNT(*) FROM stock_movements WHERE product_id = ' . (int) $p['id'])->fetchColumn() > 0 : false;
$canPrice = can('pricing.manage');
$errors = [];
$units = $p ? $pdo->query('SELECT name, factor FROM product_units WHERE product_id = ' . (int) $p['id'] . ' ORDER BY factor')->fetchAll() : [['name' => 'Carton', 'factor' => 24]];
$tier = $p ? $pdo->query('SELECT category_id, price FROM product_prices WHERE product_id = ' . (int) $p['id'])->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$breaks = $p ? $pdo->query('SELECT min_qty, discount_pct FROM product_price_breaks WHERE product_id = ' . (int) $p['id'] . ' ORDER BY min_qty')->fetchAll() : [];

if (is_post()) {
    verify_csrf();
    $name = input($_POST, 'name'); $code = strtoupper(preg_replace('/[^A-Z0-9._-]/i', '', input($_POST, 'code')));
    $base = input($_POST, 'base_unit', 'Unit'); $tracking = in_array(input($_POST, 'tracking'), ['none', 'batch', 'serial'], true) ? input($_POST, 'tracking') : 'none';
    $trackExp = $tracking === 'batch' && isset($_POST['track_expiry']) ? 1 : 0;
    $tax = max(0, min(100, input_float($_POST, 'tax_rate'))); $moq = max(1, input_int($_POST, 'min_order_qty', 1)); $reorder = max(0, input_int($_POST, 'reorder_level'));
    $warranty = $tracking === 'serial' ? max(0, input_int($_POST, 'warranty_months')) : 0;
    $defPrice = max(0, input_float($_POST, 'default_price')); $cost = max(0, input_float($_POST, 'avg_cost'));
    $catId = input_int($_POST, 'category_id') ?: null;
    $newUnits = []; $seen = [strtolower($base) => 1];
    foreach (($_POST['unit_name'] ?? []) as $i => $un) {
        $un = trim((string) $un); $f = (int) ($_POST['unit_factor'][$i] ?? 0);
        if ($un === '' && !$f) continue;
        if ($un === '' || $f < 2) { $errors[] = 'Each packaging unit needs a name and a quantity of 2 or more (e.g. Carton = 24).'; break; }
        if (isset($seen[strtolower($un)])) { $errors[] = "Packaging name \"$un\" is used twice."; break; }
        $seen[strtolower($un)] = 1; $newUnits[] = [$un, $f];
    }
    $newBreaks = [];
    foreach (($_POST['break_qty'] ?? []) as $i => $bq) {
        $bq = (int) $bq; $bp = (float) ($_POST['break_pct'][$i] ?? 0);
        if ($bq > 0 && $bp > 0) { if ($bp >= 100) { $errors[] = 'A bulk discount must be below 100%.'; break; } $newBreaks[$bq] = $bp; }
    }
    if ($name === '') $errors[] = 'Enter the product name.';
    if ($code === '') $code = strtoupper(substr(preg_replace('/[^A-Z]/i', '', $name), 0, 4)) . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT);
    $dup = $pdo->prepare('SELECT COUNT(*) FROM products WHERE code = :c AND id <> :id'); $dup->execute([':c' => $code, ':id' => (int) ($p['id'] ?? 0)]);
    if ((int) $dup->fetchColumn()) $errors[] = "Product code $code is already used.";
    if ($p && $tracking !== $p['tracking'] && $hasMovement) $errors[] = 'The tracking method cannot change once the product has stock history.';
    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $vals = [':n' => $name, ':c' => $code, ':b' => input($_POST, 'barcode') ?: null, ':d' => input($_POST, 'description') ?: null, ':cat' => $catId, ':br' => input($_POST, 'brand') ?: null, ':u' => $base, ':t' => $tracking, ':te' => $trackExp,
                ':tx' => $tax, ':dp' => $defPrice, ':mo' => $moq, ':rl' => $reorder, ':w' => $warranty];
            if ($p) {
                $pdo->prepare('UPDATE products SET name=:n, code=:c, barcode=:b, description=:d, category_id=:cat, brand=:br, base_unit=:u, tracking=:t, track_expiry=:te, tax_rate=:tx, default_price=:dp, min_order_qty=:mo, reorder_level=:rl, warranty_months=:w WHERE id=:id')->execute($vals + [':id' => $p['id']]);
                if (!$hasMovement) $pdo->prepare('UPDATE products SET avg_cost = :c WHERE id = :id')->execute([':c' => $cost, ':id' => $p['id']]);
                $pid = (int) $p['id'];
                if ((float) $p['default_price'] !== $defPrice && $canPrice) log_change('price_change', 'Default wholesale price', format_money($p['default_price']), format_money($defPrice), 'product', $pid, $name);
                if ((float) $p['tax_rate'] !== $tax) log_change('product_update', 'Tax rate', $p['tax_rate'] . '%', $tax . '%', 'product', $pid, $name);
            } else {
                $pdo->prepare('INSERT INTO products (name, code, barcode, description, category_id, brand, base_unit, tracking, track_expiry, tax_rate, default_price, min_order_qty, reorder_level, warranty_months, avg_cost, created_by) VALUES (:n,:c,:b,:d,:cat,:br,:u,:t,:te,:tx,:dp,:mo,:rl,:w,:ac,:cb)')
                    ->execute($vals + [':ac' => $cost, ':cb' => current_user()['id']]);
                $pid = (int) $pdo->lastInsertId();
            }
            $pdo->prepare('DELETE FROM product_units WHERE product_id = :p')->execute([':p' => $pid]);
            foreach ($newUnits as [$un, $f]) $pdo->prepare('INSERT INTO product_units (product_id, name, factor) VALUES (:p,:n,:f)')->execute([':p' => $pid, ':n' => $un, ':f' => $f]);
            if ($canPrice) {
                foreach ($custCats as $cc) {
                    $np = input_float($_POST['tier'] ?? [], (string) $cc['id'], -1); $old = $tier[$cc['id']] ?? null;
                    if ($np > 0) {
                        $pdo->prepare('INSERT INTO product_prices (product_id, category_id, price) VALUES (:p,:c,:pr) ON DUPLICATE KEY UPDATE price = :pr2')->execute([':p' => $pid, ':c' => $cc['id'], ':pr' => $np, ':pr2' => $np]);
                        if ($p && (float) $old !== $np) log_change('price_change', $cc['name'] . ' price', $old === null ? null : format_money($old), format_money($np), 'product', $pid, $name);
                    } else {
                        $pdo->prepare('DELETE FROM product_prices WHERE product_id = :p AND category_id = :c')->execute([':p' => $pid, ':c' => $cc['id']]);
                        if ($old !== null) log_change('price_change', $cc['name'] . ' price', format_money($old), 'removed', 'product', $pid, $name);
                    }
                }
                $pdo->prepare('DELETE FROM product_price_breaks WHERE product_id = :p')->execute([':p' => $pid]);
                foreach ($newBreaks as $bq => $bp) $pdo->prepare('INSERT INTO product_price_breaks (product_id, min_qty, discount_pct) VALUES (:p,:q,:d)')->execute([':p' => $pid, ':q' => $bq, ':d' => $bp]);
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); error_log($e->getMessage()); $errors[] = 'Could not save the product.'; }
        if (!$errors) {
            log_activity($p ? 'product_update' : 'product_create', ($p ? 'Updated ' : 'Created ') . "$name ($code) · " . $tracking . ' tracking', 'product', $pid);
            flash('success', "$name saved.");
            redirect('/catalog/product_view.php?id=' . $pid);
        }
    }
    $p = array_merge($p ?? [], ['name' => $name, 'code' => $code, 'barcode' => input($_POST, 'barcode'), 'description' => input($_POST, 'description'), 'category_id' => $catId, 'brand' => input($_POST, 'brand'), 'base_unit' => $base, 'tracking' => $tracking,
        'track_expiry' => $trackExp, 'tax_rate' => $tax, 'default_price' => $defPrice, 'min_order_qty' => $moq, 'reorder_level' => $reorder, 'warranty_months' => $warranty, 'avg_cost' => $cost]);
    $units = array_map(fn($u) => ['name' => $u[0], 'factor' => $u[1]], $newUnits);
    $breaks = array_map(fn($q, $d) => ['min_qty' => $q, 'discount_pct' => $d], array_keys($newBreaks), $newBreaks);
}
$v = fn($k, $d = '') => e((string) ($p[$k] ?? $d));
$editing = $p && isset($p['id']);
$pageTitle = $editing ? 'Edit ' . $p['name'] : 'New product';
require __DIR__ . '/../includes/header.php';
page_header($editing ? 'Edit product' : 'New product', 'Define how the product is bought, stored, tracked and priced.', '<a class="btn btn-secondary" href="' . e(app_url($editing ? '/catalog/product_view.php?id=' . $p['id'] : '/catalog/products.php')) . '">Cancel</a>', [['Catalogue', '/catalog/products.php'], [$editing ? $p['name'] : 'New product', null]]);
foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<form method="post">
  <?= csrf_field() ?>
  <div class="grid grid-2 mb-4">
    <div class="card">
      <h3 class="mb-4">Product details</h3>
      <div class="form-group"><label>Product name</label><input class="form-control" type="text" name="name" value="<?= $v('name') ?>" required maxlength="150" autofocus placeholder="e.g. Coca-Cola 1L"></div>
      <div class="form-row">
        <div class="form-group"><label>Product code</label><input class="form-control mono" type="text" name="code" value="<?= $v('code') ?>" maxlength="40" placeholder="Auto if blank"></div>
        <div class="form-group"><label>Barcode <span class="label-opt">(optional)</span></label><input class="form-control mono" type="text" name="barcode" value="<?= $v('barcode') ?>" maxlength="60"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Category</label><select class="form-control" name="category_id"><option value="">—</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($p['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Brand</label><input class="form-control" type="text" name="brand" value="<?= $v('brand') ?>" maxlength="100"></div>
      </div>
      <div class="form-group mb-0"><label>Description <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="description" value="<?= $v('description') ?>" maxlength="255"></div>
    </div>
    <div class="card">
      <h3 class="mb-4">Units &amp; packaging</h3>
      <div class="form-row">
        <div class="form-group"><label>Base unit (smallest sellable)</label><input class="form-control" type="text" name="base_unit" id="baseUnit" value="<?= $v('base_unit', 'Unit') ?>" required maxlength="30" placeholder="Bottle, Pack, Bag, Unit"></div>
        <div class="form-group"><label>Minimum order qty <span class="label-opt">(base units)</span></label><input class="form-control" type="number" min="1" name="min_order_qty" value="<?= $v('min_order_qty', '1') ?>"></div>
      </div>
      <label>Packaging conversions</label>
      <div id="unitRows"></div>
      <button class="btn btn-secondary btn-sm mt-2" type="button" id="addUnit"><?= icon('plus', 14) ?> Add packaging</button>
      <div class="form-hint mt-2">Example: 1 Carton = 24 Bottle. A customer ordering 10 cartons commits 240 bottles of stock.</div>
    </div>
  </div>

  <div class="card mb-4">
    <h3 class="mb-2">How is this product tracked?</h3>
    <div class="option-cards">
      <?php foreach (['none' => ['Quantity only', 'Counted as a quantity per warehouse. Best for fast-moving goods without expiry or serials.'], 'batch' => ['Batch / lot', 'Each delivery is a batch with its own expiry. Enables FEFO picking, expiry alerts and recall tracing.'], 'serial' => ['Serial number', 'Every unit is individually identified: its supplier, warehouse, customer, invoice, warranty and history.']] as $k => [$t, $d]): ?>
        <label class="option-card"><input type="radio" name="tracking" value="<?= $k ?>" <?= ($p['tracking'] ?? 'none') === $k ? 'checked' : '' ?> <?= $editing && $hasMovement && ($p['tracking'] ?? '') !== $k ? 'disabled' : '' ?>><span class="oc-body"><span class="oc-title"><?= $t ?></span><span class="oc-desc"><?= $d ?></span></span></label>
      <?php endforeach; ?>
    </div>
    <?php if ($editing && $hasMovement): ?><div class="form-hint mt-2">The tracking method is locked because this product already has stock history.</div><?php endif; ?>
    <div class="form-row mt-4">
      <div class="form-group" id="expRow"><label class="check"><input type="checkbox" name="track_expiry" <?= !empty($p['track_expiry']) ? 'checked' : '' ?>> Products have an expiry date</label><div class="form-hint">Expiry is mandatory when receiving and drives FEFO and expiry alerts.</div></div>
      <div class="form-group" id="warRow"><label>Warranty (months)</label><input class="form-control" type="number" min="0" name="warranty_months" value="<?= $v('warranty_months', '0') ?>"><div class="form-hint">Starts from the delivery date of each serial number.</div></div>
    </div>
  </div>

  <div class="grid grid-2 mb-4">
    <div class="card">
      <h3 class="mb-4">Cost, tax &amp; stock levels</h3>
      <div class="form-row">
        <div class="form-group"><label>Purchase cost <span class="label-opt">per base unit</span></label><input class="form-control" type="number" step="0.01" min="0" name="avg_cost" value="<?= $v('avg_cost', '0') ?>" <?= $editing && $hasMovement ? 'readonly' : '' ?>><div class="form-hint"><?= $editing && $hasMovement ? 'Average cost is updated automatically from goods received.' : 'Opening cost; later maintained from purchases.' ?></div></div>
        <div class="form-group"><label><?= e(setting('tax_name', 'VAT')) ?> rate (%)</label><input class="form-control" type="number" step="0.01" min="0" max="100" name="tax_rate" value="<?= $v('tax_rate', '0') ?>"></div>
      </div>
      <div class="form-group mb-0"><label>Reorder level <span class="label-opt">(base units, all warehouses)</span></label><input class="form-control" type="number" min="0" name="reorder_level" value="<?= $v('reorder_level', '0') ?>"><div class="form-hint">You’ll get a low-stock alert when available stock falls to this level. 0 = no alert.</div></div>
    </div>
    <div class="card">
      <h3 class="mb-2">Wholesale selling prices <span class="label-opt">per base unit, excluding tax</span></h3>
      <?php if (!$canPrice): ?><div class="callout mb-3">Prices can only be changed by a Sales Manager or the owner.</div><?php endif; ?>
      <div class="form-group"><label>Default price <span class="label-opt">(customers with no category price)</span></label><input class="form-control" type="number" step="0.01" min="0" name="default_price" id="defPrice" value="<?= $v('default_price', '0') ?>" <?= $canPrice ? '' : 'readonly' ?>><div class="form-hint" id="packPreview"></div></div>
      <label>Price by customer category</label>
      <?php foreach ($custCats as $cc): ?>
        <div class="input-group mb-2"><span class="addon" style="border:1px solid var(--border-strong);border-right:0;border-radius:var(--radius-sm) 0 0 var(--radius-sm);min-width:170px;"><?= e($cc['name']) ?></span><input class="form-control" style="border-radius:0 var(--radius-sm) var(--radius-sm) 0;" type="number" step="0.01" min="0" name="tier[<?= (int) $cc['id'] ?>]" value="<?= isset($tier[$cc['id']]) ? e((string) $tier[$cc['id']]) : '' ?>" placeholder="Default price" <?= $canPrice ? '' : 'readonly' ?>></div>
      <?php endforeach; ?>
      <label class="mt-3">Bulk quantity discounts</label>
      <div id="breakRows"></div>
      <?php if ($canPrice): ?><button class="btn btn-secondary btn-sm mt-2" type="button" id="addBreak"><?= icon('plus', 14) ?> Add bulk discount</button><?php endif; ?>
      <div class="form-hint mt-2">Orders of at least the quantity (in base units) get the discount automatically. Negotiated customer prices are set on the customer.</div>
    </div>
  </div>
  <div class="action-bar"><span class="text-muted text-sm">Changes to prices are recorded in the audit log.</span><button type="submit" class="btn btn-primary btn-lg"><?= icon('save', 16) ?> Save product</button></div>
</form>
<script>
(function () {
  const esc = CalimaxUI.esc, canPrice = <?= $canPrice ? 'true' : 'false' ?>;
  const units = <?= js_json(array_map(fn($u) => ['n' => $u['name'], 'f' => (int) $u['factor']], $units)) ?>;
  const breaks = <?= js_json(array_map(fn($b) => ['q' => (int) $b['min_qty'], 'd' => (float) $b['discount_pct']], $breaks)) ?>;
  const ur = document.getElementById('unitRows'), br = document.getElementById('breakRows');
  const urow = (u) => '<div class="input-group mb-2"><span class="addon" style="border:1px solid var(--border-strong);border-right:0;border-radius:var(--radius-sm) 0 0 var(--radius-sm);">1</span><input class="form-control" style="border-radius:0;" name="unit_name[]" value="' + esc(u.n) + '" placeholder="Carton"><span class="addon" style="border-radius:0;border-right:0;">=</span><input class="form-control" type="number" min="2" name="unit_factor[]" value="' + (u.f || '') + '" placeholder="24"><span class="addon base-lbl"></span><button type="button" class="btn btn-secondary" data-rm style="border-radius:0 var(--radius-sm) var(--radius-sm) 0;">&times;</button></div>';
  const brow = (b) => '<div class="input-group mb-2"><span class="addon" style="border:1px solid var(--border-strong);border-right:0;border-radius:var(--radius-sm) 0 0 var(--radius-sm);">From</span><input class="form-control" style="border-radius:0;" type="number" min="1" name="break_qty[]" value="' + (b.q || '') + '" placeholder="240"' + (canPrice ? '' : ' readonly') + '><span class="addon" style="border-radius:0;border-right:0;">units →</span><input class="form-control" type="number" step="0.01" min="0" max="99.99" name="break_pct[]" value="' + (b.d || '') + '" placeholder="2"' + (canPrice ? '' : ' readonly') + '><span class="addon">% off</span>' + (canPrice ? '<button type="button" class="btn btn-secondary" data-rm style="border-radius:0 var(--radius-sm) var(--radius-sm) 0;">&times;</button>' : '') + '</div>';
  ur.innerHTML = units.map(urow).join(''); br.innerHTML = breaks.map(brow).join('');
  document.getElementById('addUnit').onclick = () => { ur.insertAdjacentHTML('beforeend', urow({ n: '', f: '' })); sync(); };
  const ab = document.getElementById('addBreak'); if (ab) ab.onclick = () => br.insertAdjacentHTML('beforeend', brow({ q: '', d: '' }));
  document.addEventListener('click', (e) => { const b = e.target.closest('[data-rm]'); if (b) b.parentElement.remove(); });
  function sync() {
    const base = document.getElementById('baseUnit').value || 'unit';
    document.querySelectorAll('.base-lbl').forEach((x) => { x.textContent = base; });
    const rows = [...ur.querySelectorAll('.input-group')].map((r) => ({ n: r.querySelector('[name="unit_name[]"]').value, f: +r.querySelector('[name="unit_factor[]"]').value }));
    const big = rows.filter((r) => r.n && r.f > 1).sort((a, b) => b.f - a.f)[0], dp = +document.getElementById('defPrice').value || 0;
    document.getElementById('packPreview').textContent = big ? '= ' + CalimaxUI.money(dp * big.f) + ' per ' + big.n + ' (' + big.f + ' ' + base + ')' : '';
  }
  ['input', 'change'].forEach((ev) => document.addEventListener(ev, sync)); sync();
  function tracking() {
    const t = document.querySelector('input[name=tracking]:checked').value;
    document.getElementById('expRow').hidden = t !== 'batch'; document.getElementById('warRow').hidden = t !== 'serial';
  }
  document.querySelectorAll('input[name=tracking]').forEach((r) => r.addEventListener('change', tracking)); tracking();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
