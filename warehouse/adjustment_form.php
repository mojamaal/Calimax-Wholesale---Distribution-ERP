<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('inventory.adjust');

$pdo = db();
$whs = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY id')->fetchAll();
$reasons = adjustment_reasons();
$errors = [];
if (is_post()) {
    verify_csrf();
    $lines = [];
    foreach (json_decode($_POST['lines_json'] ?? '[]', true) ?: [] as $l) {
        if (($l['tracking'] ?? '') === 'serial') foreach ($l['serials'] ?? [] as $sid) $lines[] = ['product_id' => (int) $l['product_id'], 'serial_id' => (int) $sid, 'batch_id' => 0, 'qty' => 1, 'note' => $l['note'] ?? null];
        elseif ((int) ($l['qty'] ?? 0) !== 0) $lines[] = ['product_id' => (int) $l['product_id'], 'batch_id' => (int) ($l['batch_id'] ?? 0), 'qty' => (int) $l['qty'], 'note' => $l['note'] ?? null];
    }
    try {
        $id = adjustment_create(input_int($_POST, 'warehouse_id'), input($_POST, 'reason'), $lines, input($_POST, 'note'), can('inventory.approve'));
        flash('success', can('inventory.approve') ? 'Adjustment applied.' : 'Adjustment submitted — the warehouse manager must approve it before stock changes.');
        redirect('/warehouse/adjustment_view.php?id=' . $id);
    } catch (RuntimeException $e) { $errors[] = $e->getMessage(); }
}
$preReason = input($_GET, 'reason', 'damaged');
$pageTitle = 'New stock adjustment';
require __DIR__ . '/../includes/header.php';
page_header('New stock adjustment', can('inventory.approve') ? 'As warehouse manager your adjustment is applied immediately.' : 'Your adjustment is sent to the warehouse manager for approval. Stock changes only once approved.', '<a class="btn btn-secondary" href="' . e(app_url('/warehouse/adjustments.php')) . '">Cancel</a>', [['Adjustments', '/warehouse/adjustments.php'], ['New', null]]);
foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<form method="post" id="adjForm"><?= csrf_field() ?><input type="hidden" name="lines_json" id="lines_json">
  <div class="card mb-4">
    <div class="form-group"><label>Warehouse</label><select class="form-control" name="warehouse_id" id="wh" style="max-width:320px;"><?php foreach ($whs as $w): ?><option value="<?= (int) $w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
    <label>What happened?</label>
    <div class="option-cards"><?php foreach ($reasons as $k => [$l, $d, $dir, $ic]): ?><label class="option-card"><input type="radio" name="reason" value="<?= e($k) ?>" data-dir="<?= (int) $dir ?>" <?= $preReason === $k ? 'checked' : '' ?>><span class="oc-body"><span class="oc-title"><?= e($l) ?></span><span class="oc-desc"><?= e($d) ?></span></span></label><?php endforeach; ?></div>
    <div class="form-group mt-3 mb-0"><label>Notes</label><input class="form-control" type="text" name="note" maxlength="255" placeholder="e.g. Pallet dropped while loading"></div>
  </div>
  <div class="card flush"><div class="card-head"><div><h3>Items</h3><div class="sub" id="hint">Search the warehouse stock.</div></div><div style="position:relative;width:min(420px,100%);"><input class="form-control" type="text" id="search" placeholder="Search product…" autocomplete="off"><div class="search-results" id="results" hidden></div></div></div>
    <div id="lines" style="padding:0 16px 16px;"></div><div id="empty"><?= empty_state('package-search', 'No items yet', 'Search for a product above.') ?></div></div>
  <div class="action-bar"><span class="text-muted text-sm">Losses are written off against profit; every adjustment is in the audit log.</span><button class="btn btn-primary btn-lg" type="submit"><?= icon('check', 16) ?> <?= can('inventory.approve') ? 'Apply adjustment' : 'Submit for approval' ?></button></div>
</form>
<script>
(function () {
  const esc = CalimaxUI.esc, wh = document.getElementById('wh'), box = document.getElementById('lines'), res = document.getElementById('results'), search = document.getElementById('search');
  let lines = [];
  const reason = () => document.querySelector('input[name=reason]:checked'), dir = () => +reason().dataset.dir, val = () => reason().value;
  const bucketKey = () => (val() === 'restore_damaged' || val() === 'write_off_damaged') ? 'damaged' : 'on_hand';
  function render() {
    document.getElementById('empty').hidden = lines.length > 0;
    const signed = dir() === 0, bk = bucketKey();
    box.innerHTML = lines.map((l, i) => {
      let body;
      if (l.tracking === 'serial') {
        const want = bk === 'damaged' ? 'damaged' : 'available';
        body = '<div class="text-sm text-secondary mb-2">Tick the unit(s) affected:</div><div class="chip-list">' + l.avail.filter((s) => s.status === want).map((s) => '<label class="sn-chip" style="cursor:pointer;"><input type="checkbox" data-i="' + i + '" data-sid="' + s.id + '" ' + (l.serials.includes(s.id) ? 'checked' : '') + '> ' + esc(s.serial_no) + '</label>').join('') + '</div>';
      } else if (l.tracking === 'batch') {
        body = l.batches.map((b) => '<div class="alloc-row"><div class="strong mono">' + esc(b.batch_no) + '<div class="cell-sub">' + (b.expiry ? 'Exp ' + esc(b.expiry) : 'No expiry') + ' · ' + esc(b.status) + '</div></div><div class="num text-sm">' + (bk === 'damaged' ? 'Damaged' : 'On hand') + ' <strong>' + (bk === 'damaged' ? b.damaged : b.on_hand).toLocaleString() + '</strong></div><input class="form-control num-input" type="number" ' + (signed ? '' : 'min="0"') + ' data-i="' + i + '" data-b="' + b.id + '" value="' + (l.q[b.id] || '') + '" placeholder="' + (signed ? '± qty' : 'Qty') + '"><span></span><span></span></div>').join('') || '<div class="text-muted text-sm">No batches in this warehouse. ' + (val() === 'found' ? 'Receive stock with a PO to create a batch.' : '') + '</div>';
      } else body = '<div class="flex items-center gap-3"><label class="mb-0">Quantity' + (signed ? ' (+ or −)' : '') + '</label><input class="form-control num-input" style="max-width:160px;" type="number" ' + (signed ? '' : 'min="0"') + ' data-i="' + i + '" data-q value="' + (l.qty || '') + '"><span class="text-sm text-secondary">' + (bk === 'damaged' ? 'Damaged' : 'On hand') + ' <strong>' + (bk === 'damaged' ? l.damaged : l.saleable).toLocaleString() + '</strong> ' + esc(l.base_unit) + '</span></div>';
      return '<div class="item-block mt-3"><div class="ib-head"><div class="ib-title">' + esc(l.name) + ' <span class="text-muted mono">' + esc(l.code) + '</span> ' + (l.tracking !== 'none' ? '<span class="tag ' + l.tracking + '">' + l.tracking + '</span>' : '') + '</div><button type="button" class="btn btn-secondary btn-sm" data-rm="' + i + '">Remove</button></div><div class="ib-body">' + body + '</div></div>';
    }).join('');
    sync();
  }
  function sync() {
    const out = [];
    lines.forEach((l) => {
      if (l.tracking === 'serial') { if (l.serials.length) out.push({ product_id: l.id, tracking: 'serial', serials: l.serials }); }
      else if (l.tracking === 'batch') Object.keys(l.q).forEach((b) => { if (+l.q[b]) out.push({ product_id: l.id, tracking: 'batch', batch_id: +b, qty: +l.q[b] }); });
      else if (+l.qty) out.push({ product_id: l.id, tracking: 'none', qty: +l.qty });
    });
    document.getElementById('lines_json').value = JSON.stringify(out);
  }
  box.addEventListener('input', (e) => { const i = e.target.dataset.i; if (i === undefined) return; if (e.target.dataset.b) lines[i].q[e.target.dataset.b] = +e.target.value || 0; else if (e.target.dataset.q !== undefined) lines[i].qty = +e.target.value || 0; sync(); });
  box.addEventListener('change', (e) => { if (e.target.dataset.sid) { const l = lines[e.target.dataset.i], id = +e.target.dataset.sid; l.serials = e.target.checked ? [...new Set([...l.serials, id])] : l.serials.filter((x) => x !== id); sync(); } });
  box.addEventListener('click', (e) => { const b = e.target.closest('[data-rm]'); if (b) { lines.splice(+b.dataset.rm, 1); render(); } });
  document.querySelectorAll('input[name=reason]').forEach((r) => r.addEventListener('change', () => { lines.forEach((l) => { l.q = {}; l.qty = 0; l.serials = []; }); render(); }));
  wh.addEventListener('change', () => { lines = []; render(); });
  let t = null;
  search.addEventListener('input', () => { clearTimeout(t); const q = search.value.trim(); if (!q) { res.hidden = true; return; } t = setTimeout(() => fetch(APP.url + 'api/stock_lookup.php?q=' + encodeURIComponent(q) + '&warehouse_id=' + wh.value).then((r) => r.json()).then((list) => {
    res.innerHTML = list.length ? '' : '<div class="search-empty">No products found.</div>';
    list.forEach((p) => { const row = document.createElement('div'); row.className = 'search-item'; row.innerHTML = '<span class="si-main"><div class="si-title">' + esc(p.name) + '</div><div class="si-sub">' + esc(p.code) + '</div></span><span class="si-meta">' + p.saleable.toLocaleString() + ' on hand</span>';
      row.onclick = () => { if (!lines.find((l) => l.id === p.id)) lines.push({ id: p.id, code: p.code, name: p.name, tracking: p.tracking, base_unit: p.base_unit, saleable: p.saleable, damaged: p.damaged, batches: p.batches, avail: p.serials, serials: [], q: {}, qty: 0 }); res.hidden = true; search.value = ''; render(); }; res.appendChild(row); });
    res.hidden = false; }), 200); });
  document.addEventListener('click', (e) => { if (e.target !== search && !res.contains(e.target)) res.hidden = true; });
  document.getElementById('adjForm').addEventListener('submit', (e) => { sync(); if (document.getElementById('lines_json').value === '[]') { e.preventDefault(); CalimaxUI.toast('Add at least one item with a quantity.', 'error'); } });
  render();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
