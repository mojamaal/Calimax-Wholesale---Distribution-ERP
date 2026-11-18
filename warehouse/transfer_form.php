<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('inventory.transfer');

$pdo = db();
$whs = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY id')->fetchAll();
$errors = [];
if (is_post()) {
    verify_csrf();
    $lines = []; $serials = [];
    foreach (json_decode($_POST['lines_json'] ?? '[]', true) ?: [] as $l) {
        if (($l['tracking'] ?? '') === 'serial') { if (!empty($l['serials'])) $serials[(int) $l['product_id']] = array_map('intval', $l['serials']); }
        elseif ((int) ($l['qty'] ?? 0) > 0) $lines[] = ['product_id' => (int) $l['product_id'], 'batch_id' => (int) ($l['batch_id'] ?? 0), 'qty' => (int) $l['qty']];
    }
    try {
        $id = transfer_create(input_int($_POST, 'from_wh'), input_int($_POST, 'to_wh'), input_date($_POST, 'transfer_date', date('Y-m-d')), input($_POST, 'notes'), $lines, $serials, input($_POST, 'action') === 'send');
        flash('success', input($_POST, 'action') === 'send' ? 'Transfer sent. The destination warehouse can receive it now.' : 'Transfer saved as draft.');
        redirect('/warehouse/transfer_view.php?id=' . $id);
    } catch (RuntimeException $e) { $errors[] = $e->getMessage(); }
}
$pageTitle = 'New transfer';
require __DIR__ . '/../includes/header.php';
page_header('New stock transfer', 'Choose the warehouses, then add products. Batch and serial-tracked items are picked by batch / serial number.', '<a class="btn btn-secondary" href="' . e(app_url('/warehouse/transfers.php')) . '">Cancel</a>', [['Transfers', '/warehouse/transfers.php'], ['New', null]]);
foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<form method="post" id="trForm"><?= csrf_field() ?><input type="hidden" name="lines_json" id="lines_json"><input type="hidden" name="action" id="act" value="draft">
  <div class="card mb-4"><div class="form-row">
    <div class="form-group"><label>From warehouse</label><select class="form-control" name="from_wh" id="from" required><?php foreach ($whs as $w): ?><option value="<?= (int) $w['id'] ?>" <?= (int) ($_POST['from_wh'] ?? 0) === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>To warehouse</label><select class="form-control" name="to_wh" required><?php foreach ($whs as $i => $w): ?><option value="<?= (int) $w['id'] ?>" <?= (int) ($_POST['to_wh'] ?? 0) === (int) $w['id'] || (!isset($_POST['to_wh']) && $i === 1) ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Date</label><input class="form-control" type="date" name="transfer_date" value="<?= date('Y-m-d') ?>"></div>
    <div class="form-group" style="flex:2"><label>Notes <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="notes" maxlength="255" placeholder="e.g. Weekly replenishment"></div></div></div>
  <div class="card flush"><div class="card-head"><div><h3>Items to transfer</h3><div class="sub">Search the source warehouse’s stock.</div></div><div style="position:relative;width:min(420px,100%);"><input class="form-control" type="text" id="search" placeholder="Search product to add…" autocomplete="off"><div class="search-results" id="results" hidden></div></div></div>
    <div id="lines" style="padding:0 16px 16px;"></div><div id="empty"><?= empty_state('package-search', 'No items yet', 'Search for a product above.') ?></div></div>
  <div class="action-bar"><span class="text-muted text-sm">“Send now” takes the goods out of the source warehouse immediately.</span><div class="flex gap-2"><button class="btn btn-secondary" type="submit" data-no-busy onclick="document.getElementById('act').value='draft'">Save draft</button><button class="btn btn-primary btn-lg" type="submit" data-no-busy onclick="document.getElementById('act').value='send'"><?= icon('send', 16) ?> Send now</button></div></div>
</form>
<script>
(function () {
  const esc = CalimaxUI.esc, from = document.getElementById('from'), box = document.getElementById('lines'), res = document.getElementById('results'), search = document.getElementById('search');
  let lines = [];
  function render() {
    document.getElementById('empty').hidden = lines.length > 0;
    box.innerHTML = lines.map((l, i) => {
      let body = '';
      if (l.tracking === 'serial') body = '<div class="text-sm text-secondary mb-2">Tick the units to transfer (' + l.serials.length + ' selected):</div><div class="chip-list">' + l.avail.map((s) => '<label class="sn-chip" style="cursor:pointer;' + (s.status !== 'available' ? 'opacity:.45;' : '') + '"><input type="checkbox" data-i="' + i + '" data-sid="' + s.id + '" ' + (l.serials.includes(s.id) ? 'checked' : '') + (s.status !== 'available' ? ' disabled' : '') + '> ' + esc(s.serial_no) + '</label>').join('') + '</div>';
      else if (l.tracking === 'batch') body = l.batches.filter((b) => b.on_hand > 0 && b.status === 'active').map((b) => '<div class="alloc-row"><div class="strong mono">' + esc(b.batch_no) + '<div class="cell-sub">' + (b.expiry ? 'Expires ' + esc(b.expiry) : 'No expiry') + '</div></div><div class="num text-sm">On hand <strong>' + b.on_hand.toLocaleString() + '</strong></div><input class="form-control num-input" type="number" min="0" max="' + b.on_hand + '" data-i="' + i + '" data-b="' + b.id + '" value="' + (l.q[b.id] || '') + '" placeholder="Qty"><span></span><span></span></div>').join('') || '<div class="text-muted text-sm">No transferable stock in this warehouse.</div>';
      else body = '<div class="flex items-center gap-3"><label class="mb-0">Quantity</label><input class="form-control num-input" style="max-width:160px;" type="number" min="0" max="' + l.saleable + '" data-i="' + i + '" data-q value="' + (l.qty || '') + '"><span class="text-sm text-secondary">On hand <strong>' + l.saleable.toLocaleString() + '</strong> ' + esc(l.base_unit) + '</span></div>';
      return '<div class="item-block mt-3"><div class="ib-head"><div class="ib-title">' + esc(l.name) + ' <span class="text-muted mono">' + esc(l.code) + '</span> ' + (l.tracking !== 'none' ? '<span class="tag ' + l.tracking + '">' + l.tracking + '</span>' : '') + '</div><button type="button" class="btn btn-secondary btn-sm" data-rm="' + i + '">Remove</button></div><div class="ib-body">' + body + '</div></div>';
    }).join('');
    sync();
  }
  function sync() {
    const out = [];
    lines.forEach((l) => {
      if (l.tracking === 'serial') out.push({ product_id: l.id, tracking: 'serial', serials: l.serials });
      else if (l.tracking === 'batch') Object.keys(l.q).forEach((b) => { if (+l.q[b] > 0) out.push({ product_id: l.id, tracking: 'batch', batch_id: +b, qty: +l.q[b] }); });
      else out.push({ product_id: l.id, tracking: 'none', qty: +l.qty || 0 });
    });
    document.getElementById('lines_json').value = JSON.stringify(out);
  }
  box.addEventListener('input', (e) => { const i = e.target.dataset.i; if (i === undefined) return; if (e.target.dataset.b) lines[i].q[e.target.dataset.b] = +e.target.value || 0; else if (e.target.dataset.q !== undefined) lines[i].qty = +e.target.value || 0; sync(); });
  box.addEventListener('change', (e) => { if (e.target.dataset.sid) { const l = lines[e.target.dataset.i], id = +e.target.dataset.sid; l.serials = e.target.checked ? [...new Set([...l.serials, id])] : l.serials.filter((x) => x !== id); render(); } });
  box.addEventListener('click', (e) => { const b = e.target.closest('[data-rm]'); if (b) { lines.splice(+b.dataset.rm, 1); render(); } });
  from.addEventListener('change', () => { lines = []; render(); });
  let t = null;
  search.addEventListener('input', () => { clearTimeout(t); const q = search.value.trim(); if (!q) { res.hidden = true; return; } t = setTimeout(() => fetch(APP.url + 'api/stock_lookup.php?q=' + encodeURIComponent(q) + '&warehouse_id=' + from.value).then((r) => r.json()).then((list) => {
    res.innerHTML = list.length ? '' : '<div class="search-empty">No products found.</div>';
    list.forEach((p) => { const row = document.createElement('div'); row.className = 'search-item'; row.innerHTML = '<span class="si-main"><div class="si-title">' + esc(p.name) + '</div><div class="si-sub">' + esc(p.code) + '</div></span><span class="si-meta">' + p.saleable.toLocaleString() + ' on hand</span>';
      row.onclick = () => { if (!lines.find((l) => l.id === p.id)) lines.push({ id: p.id, code: p.code, name: p.name, tracking: p.tracking, base_unit: p.base_unit, saleable: p.saleable, batches: p.batches, avail: p.serials, serials: [], q: {}, qty: 0 }); res.hidden = true; search.value = ''; render(); }; res.appendChild(row); });
    res.hidden = false; }), 200); });
  document.addEventListener('click', (e) => { if (e.target !== search && !res.contains(e.target)) res.hidden = true; });
  document.getElementById('trForm').addEventListener('submit', (e) => { sync(); if (document.getElementById('lines_json').value === '[]') { e.preventDefault(); CalimaxUI.toast('Add at least one item with a quantity.', 'error'); } });
  render();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
