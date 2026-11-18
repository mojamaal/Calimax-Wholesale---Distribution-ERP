<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('sales.create');

$pdo = db();
$me = (int) current_user()['id']; $own = !can('sales.view_all');
$id = input_int($_GET, 'id'); $so = null;
if ($id) {
    $s = $pdo->prepare('SELECT * FROM sales_orders WHERE id = :id'); $s->execute([':id' => $id]); $so = $s->fetch();
    if (!$so || !in_array($so['status'], ['draft', 'rejected'], true)) { flash('error', 'Only draft orders can be edited.'); redirect($so ? '/sales/order_view.php?id=' . $id : '/sales/orders.php'); }
    if ($own && (int) $so['rep_id'] !== $me) deny_access();
}
$errors = [];
$custSql = 'SELECT c.id, c.business_name, c.code, c.payment_terms_days, c.credit_limit, cc.name AS category FROM customers c LEFT JOIN customer_categories cc ON cc.id = c.category_id WHERE c.is_active = 1' . ($own ? ' AND c.rep_id = ' . $me : '') . ' ORDER BY c.business_name';
$customers = $pdo->query($custSql)->fetchAll();
$warehouses = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY id')->fetchAll();

if (is_post()) {
    verify_csrf();
    $items = json_decode($_POST['items_json'] ?? '[]', true) ?: [];
    $d = ['customer_id' => input_int($_POST, 'customer_id'), 'warehouse_id' => input_int($_POST, 'warehouse_id'), 'order_date' => input_date($_POST, 'order_date', date('Y-m-d')), 'required_date' => input_date($_POST, 'required_date'),
        'payment_type' => input($_POST, 'payment_type') === 'cash' ? 'cash' : 'credit', 'customer_po_ref' => input($_POST, 'customer_po_ref'), 'notes' => input($_POST, 'notes')];
    try {
        $soId = so_save($so['id'] ?? null, $d, $items);
        if (input($_POST, 'action') === 'submit') {
            $st = so_submit($soId);
            flash($st === 'confirmed' ? 'success' : 'info', $st === 'confirmed' ? 'Order confirmed — stock is reserved and the warehouse can start picking.' : 'Order sent to the Sales Manager for approval: it needs an exception (see the reasons on the order).');
        } else flash('success', 'Draft saved.');
        redirect('/sales/order_view.php?id=' . $soId);
    } catch (RuntimeException $e) { $errors[] = $e->getMessage(); }
    catch (Throwable $e) { error_log($e->getMessage()); $errors[] = 'Something went wrong while saving the order.'; }
    $prefillItems = $items; $so = array_merge($so ?? [], $d);
}

// Prefill lines for edit / re-render
$prefill = [];
$custId = (int) ($so['customer_id'] ?? input_int($_GET, 'customer_id'));
$whId = (int) ($so['warehouse_id'] ?? ($warehouses[0]['id'] ?? 0));
$lineSrc = [];
if ($so && isset($so['id']) && !isset($prefillItems)) {
    $s = $pdo->prepare('SELECT * FROM sales_order_items WHERE so_id = :id ORDER BY id'); $s->execute([':id' => $so['id']]);
    foreach ($s->fetchAll() as $r) $lineSrc[] = ['product_id' => $r['product_id'], 'pack_name' => $r['pack_name'], 'pack_factor' => $r['pack_factor'], 'pack_qty' => $r['qty'] / $r['pack_factor'], 'unit_price' => (float) $r['unit_price'] * $r['pack_factor'], 'discount_pct' => $r['discount_pct']];
} elseif (isset($prefillItems)) $lineSrc = $prefillItems;
foreach ($lineSrc as $l) {
    $ps = $pdo->prepare('SELECT * FROM products WHERE id = :id'); $ps->execute([':id' => (int) $l['product_id']]); $p = $ps->fetch(); if (!$p) continue;
    $u = $pdo->prepare('SELECT name, factor FROM product_units WHERE product_id = :id ORDER BY factor'); $u->execute([':id' => $p['id']]);
    $packs = array_merge([['name' => $p['base_unit'], 'factor' => 1]], array_map(fn($x) => ['name' => $x['name'], 'factor' => (int) $x['factor']], $u->fetchAll()));
    $factor = max(1, (int) $l['pack_factor']); $qtyBase = (int) round((float) $l['pack_qty'] * $factor);
    $r = resolve_price((int) $p['id'], $custId ?: null, max(1, $qtyBase));
    $prefill[] = ['product_id' => (int) $p['id'], 'code' => $p['code'], 'name' => $p['name'], 'tracking' => $p['tracking'], 'base_unit' => $p['base_unit'], 'packs' => $packs, 'pack_name' => $l['pack_name'], 'pack_factor' => $factor, 'pack_qty' => (float) $l['pack_qty'],
        'unit_price' => (float) $l['unit_price'] / $factor, 'list_price' => $r['price'], 'break_pct' => $r['break_pct'], 'discount_pct' => (float) $l['discount_pct'], 'tax_rate' => (float) $p['tax_rate'], 'stock' => stock_summary((int) $p['id'], $whId ?: null)['available'],
        'min_order_qty' => (int) $p['min_order_qty'], 'source' => $r['source'], 'cost' => (float) $p['avg_cost']];
}
$editing = $so && isset($so['id']);
$pageTitle = $editing ? 'Edit ' . $so['so_no'] : 'New sales order';
$pageScripts = [app_url('/assets/js/lines.js') . '?v=1'];
require __DIR__ . '/../includes/header.php';
page_header($editing ? 'Edit ' . $so['so_no'] : 'New sales order', 'Pick the customer, add products — prices, discounts, stock and credit are checked for you.', '<a class="btn btn-secondary" href="' . e(app_url($editing ? '/sales/order_view.php?id=' . $so['id'] : '/sales/orders.php')) . '">Cancel</a>', [['Sales orders', '/sales/orders.php'], [$editing ? $so['so_no'] : 'New order', null]]);
foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<?php if ($editing && !empty($so['decision_note']) && $so['status'] === 'rejected'): ?><div class="callout bad mb-4"><strong>Rejected by the Sales Manager:</strong> <?= e($so['decision_note']) ?></div><?php endif; ?>
<form method="post" id="soForm"><?= csrf_field() ?><input type="hidden" name="items_json" id="items_json"><input type="hidden" name="action" id="formAction" value="draft">
  <div class="layout-2-1 mb-4">
    <div class="card">
      <div class="steps wf mb-3"><div class="step current"><span class="n">1</span>Customer &amp; terms</div><div class="step"><span class="n">2</span>Products</div><div class="step"><span class="n">3</span>Review &amp; confirm</div></div>
      <div class="form-row">
        <div class="form-group" style="flex:2"><label>Customer</label>
          <select class="form-control" name="customer_id" id="customer" required><option value="">Select customer…</option><?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>" data-terms="<?= (int) $c['payment_terms_days'] ?>" data-limit="<?= (float) $c['credit_limit'] ?>" <?= $custId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['business_name']) ?> — <?= e($c['category'] ?? 'no category') ?></option><?php endforeach; ?></select>
          <div class="form-hint"><?php if (can('customers.create')): ?><a class="link" href="<?= e(app_url('/sales/customer_form.php')) ?>" target="_blank">Register a new customer</a><?php endif; ?></div></div>
        <div class="form-group"><label>Dispatch from</label><select class="form-control" name="warehouse_id" id="warehouse"><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $whId === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Order date</label><input class="form-control" type="date" name="order_date" value="<?= e($so['order_date'] ?? date('Y-m-d')) ?>"></div>
        <div class="form-group"><label>Delivery needed by</label><input class="form-control" type="date" name="required_date" value="<?= e($so['required_date'] ?? date('Y-m-d', strtotime('+2 days'))) ?>"></div>
        <div class="form-group"><label>Customer PO ref <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="customer_po_ref" maxlength="60" value="<?= e($so['customer_po_ref'] ?? '') ?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Payment</label>
          <div class="segmented"><label><input type="radio" name="payment_type" value="credit" <?= ($so['payment_type'] ?? 'credit') === 'credit' ? 'checked' : '' ?>><span>On credit</span></label><label><input type="radio" name="payment_type" value="cash" <?= ($so['payment_type'] ?? 'credit') === 'cash' ? 'checked' : '' ?>><span>Cash on delivery</span></label></div></div>
        <div class="form-group" style="flex:2"><label>Notes <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="notes" maxlength="255" value="<?= e($so['notes'] ?? '') ?>" placeholder="Delivery instructions, special requests…"></div>
      </div>
    </div>
    <div id="creditBox"><div class="credit-box"><div class="text-muted text-sm">Choose a customer to see their credit position.</div></div></div>
  </div>

  <div class="card flush">
    <div class="card-head">
      <div><h3>Products</h3><div class="sub">Search by name, code or barcode. Choose carton / box / unit for each line.</div></div>
      <div style="position:relative;width:min(420px,100%);"><input class="form-control" type="text" id="itemSearch" placeholder="Search products to add…" autocomplete="off"><div class="search-results" id="searchResults" hidden></div></div>
    </div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Product</th><th class="num">Available</th><th>Unit</th><th>Qty</th><th>Price / unit</th><th>Disc. %</th><th class="num">Line total</th><th></th></tr></thead>
      <tbody id="itemsBody"></tbody>
    </table></div>
    <div id="emptyItems"><?= empty_state('package-search', 'No products yet', 'Search above to add the first product.') ?></div>
    <div class="pagination" style="align-items:flex-start;"><span class="text-muted text-sm" id="lineCount"></span><div class="totals-box" id="totals"></div></div>
  </div>
  <div class="action-bar">
    <span class="text-muted text-sm" id="approvalHint">Orders within the customer’s credit and your discount allowance are confirmed instantly.</span>
    <div class="flex gap-2"><button type="submit" class="btn btn-secondary" data-no-busy onclick="document.getElementById('formAction').value='draft'">Save draft</button><button type="submit" class="btn btn-primary btn-lg" data-no-busy onclick="document.getElementById('formAction').value='submit'"><?= icon('check', 16) ?> Confirm order</button></div>
  </div>
</form>
<script>
(function () {
  const customer = document.getElementById('customer'), warehouse = document.getElementById('warehouse');
  const payType = () => document.querySelector('input[name=payment_type]:checked').value;
  let total = 0, lastTimer = null;
  const ed = CalimaxLines.LineEditor({
    mode: 'sale', body: document.getElementById('itemsBody'), search: document.getElementById('itemSearch'), results: document.getElementById('searchResults'), jsonInput: document.getElementById('items_json'),
    totalsEl: document.getElementById('totals'), emptyEl: document.getElementById('emptyItems'), canEditPrice: <?= can('sales.override_price') ? 'true' : 'false' ?>,
    getCustomer: () => customer.value, getWarehouse: () => warehouse.value, items: <?= js_json($prefill) ?>,
    onChange: (t) => { total = t.total; document.getElementById('lineCount').textContent = t.lines + ' line(s) · ' + t.units.toLocaleString() + ' units'; clearTimeout(lastTimer); lastTimer = setTimeout(credit, 250); }
  });
  const box = document.getElementById('creditBox'), money = CalimaxUI.money;
  function credit() {
    if (!customer.value) return;
    fetch(APP.url + 'api/credit_check.php?customer_id=' + customer.value + '&total=' + total + '&type=' + payType() + '<?= $editing ? '&so_id=' . (int) $so['id'] : '' ?>').then((r) => r.json()).then((c) => {
      if (c.none) return;
      const cash = payType() === 'cash';
      let h = '<div class="credit-box ' + (c.reasons.length ? 'over' : '') + '"><div class="text-sm fw-700 mb-2">' + CalimaxUI.esc(c.customer) + ' · ' + CalimaxUI.esc(c.category || 'No category') + '</div>';
      if (cash) h += '<div class="cb-row"><span>Payment</span><strong>Cash on delivery</strong></div><div class="text-sm text-muted mt-2">Cash orders skip the credit check.</div>';
      else {
        h += '<div class="cb-row"><span>Credit limit</span><strong>' + (c.limit > 0 ? money(c.limit) : 'None') + '</strong></div><div class="cb-row"><span>Outstanding balance</span><strong>' + money(c.outstanding) + '</strong></div>'
          + (c.open_orders > 0 ? '<div class="cb-row"><span>Open orders (not invoiced)</span><strong>' + money(c.open_orders) + '</strong></div>' : '')
          + '<div class="cb-row"><span>This order</span><strong>' + money(c.new) + '</strong></div><div class="cb-row total"><span>Exposure after order</span><strong class="' + (c.exceeds ? 'text-error' : 'text-success') + '">' + money(c.exposure) + '</strong></div>';
        if (c.limit > 0) h += '<div class="meter mt-2 ' + (c.exceeds ? 'bad' : '') + '"><span style="width:' + Math.min(100, c.exposure / c.limit * 100) + '%"></span></div>';
        h += '<div class="text-sm text-muted mt-2">Terms: ' + (c.terms ? c.terms + ' days' : 'cash') + '</div>';
        if (c.reasons.length) h += '<div class="callout bad mt-3"><strong>Needs manager approval</strong><ul>' + c.reasons.map((r) => '<li>' + CalimaxUI.esc(r) + '</li>').join('') + '</ul></div>';
      }
      box.innerHTML = h + '</div>';
      document.getElementById('approvalHint').textContent = (!cash && c.reasons.length) ? 'This order breaks a credit rule — it will be sent to the Sales Manager for approval.' : 'Orders within the customer’s credit and your discount allowance are confirmed instantly.';
    });
  }
  customer.addEventListener('change', () => {
    const o = customer.selectedOptions[0];
    if (o && o.dataset.terms === '0') document.querySelector('input[name=payment_type][value=cash]').checked = true;
    else if (o && o.value) document.querySelector('input[name=payment_type][value=credit]').checked = true;
    ed.reprice(); credit();
  });
  warehouse.addEventListener('change', () => { ed.items().forEach((it) => { fetch(APP.url + 'api/product_search.php?q=' + encodeURIComponent(it.code) + '&mode=sale&customer_id=' + customer.value + '&warehouse_id=' + warehouse.value).then((r) => r.json()).then((l) => { const p = l.find((x) => x.id == it.product_id); if (p) { it.stock = p.stock; ed.render(); } }); }); });
  document.querySelectorAll('input[name=payment_type]').forEach((r) => r.addEventListener('change', credit));
  document.getElementById('soForm').addEventListener('submit', (e) => { if (!customer.value) { e.preventDefault(); CalimaxUI.toast('Select the customer.', 'error'); } else if (!ed.items().length) { e.preventDefault(); CalimaxUI.toast('Add at least one product.', 'error'); } });
  credit();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
