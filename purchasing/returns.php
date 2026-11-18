<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.view');

$pdo = db();
$new = input($_GET, 'new') === '1' && can('purchase.receive'); $hl = input_int($_GET, 'highlight');
$errors = [];
if ($new && is_post()) {
    verify_csrf();
    $lines = [];
    foreach (json_decode($_POST['lines_json'] ?? '[]', true) ?: [] as $l) {
        $pid = (int) $l['product_id'];
        if (($l['tracking'] ?? '') === 'serial') foreach ($l['serials'] ?? [] as $sid) { $lines[] = ['product_id' => $pid, 'serial_id' => (int) $sid, 'qty' => 1, 'unit_cost' => item_unit_cost($pid, 0, (int) $sid), 'bucket' => 'on_hand']; }
        elseif ((int) ($l['qty'] ?? 0) > 0) $lines[] = ['product_id' => $pid, 'batch_id' => (int) ($l['batch_id'] ?? 0), 'qty' => (int) $l['qty'], 'unit_cost' => item_unit_cost($pid, (int) ($l['batch_id'] ?? 0)), 'bucket' => in_array($l['bucket'] ?? '', ['on_hand', 'quarantine', 'damaged'], true) ? $l['bucket'] : 'on_hand'];
    }
    try { $id = purchase_return_create(input_int($_POST, 'supplier_id'), input_int($_POST, 'warehouse_id'), input_date($_POST, 'return_date', date('Y-m-d')), input($_POST, 'reason'), $lines); flash('success', 'Return recorded. Stock has left the warehouse and the supplier’s payable is reduced.'); redirect('/purchasing/returns.php?highlight=' . $id); }
    catch (RuntimeException $e) { $errors[] = $e->getMessage(); }
}
$rows = $pdo->query("SELECT pr.*, s.name AS supplier, w.code AS wh, u.full_name AS who, (SELECT COALESCE(SUM(qty),0) FROM purchase_return_items WHERE return_id = pr.id) AS units FROM purchase_returns pr JOIN suppliers s ON s.id = pr.supplier_id JOIN warehouses w ON w.id = pr.warehouse_id LEFT JOIN users u ON u.id = pr.created_by ORDER BY pr.id DESC LIMIT 60")->fetchAll();
$suppliers = $pdo->query('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name')->fetchAll();
$whs = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY id')->fetchAll();
$pageTitle = 'Returns to supplier';
require __DIR__ . '/../includes/header.php';
page_header('Returns to supplier', 'Defective, damaged or recalled goods sent back. The supplier’s payable is reduced by the value returned.', !$new && can('purchase.receive') ? '<a class="btn btn-primary" href="' . e(app_url('/purchasing/returns.php?new=1')) . '">' . icon('plus', 16) . ' New return</a>' : '<a class="btn btn-secondary" href="' . e(app_url('/purchasing/returns.php')) . '">Cancel</a>');
nav_purchasing();
foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach;
if ($new): ?>
<form method="post" id="rf"><?= csrf_field() ?><input type="hidden" name="lines_json" id="lines_json">
  <div class="card mb-4"><div class="form-row"><div class="form-group"><label>Supplier</label><select class="form-control" name="supplier_id" required><option value="">Select…</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Goods are in</label><select class="form-control" name="warehouse_id" id="wh"><?php foreach ($whs as $w): ?><option value="<?= (int) $w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Date</label><input class="form-control" type="date" name="return_date" value="<?= date('Y-m-d') ?>"></div><div class="form-group" style="flex:2"><label>Reason</label><input class="form-control" type="text" name="reason" required maxlength="255" placeholder="e.g. Damaged on arrival, quality failure"></div></div></div>
  <div class="card flush"><div class="card-head"><h3>Goods to return</h3><div style="position:relative;width:min(420px,100%);"><input class="form-control" type="text" id="search" placeholder="Search product…" autocomplete="off"><div class="search-results" id="results" hidden></div></div></div><div id="lines" style="padding:0 16px 16px;"></div><div id="empty"><?= empty_state('package-search', 'No items yet', 'Search for a product above.') ?></div></div>
  <div class="action-bar"><span class="text-muted text-sm">Choose where the goods currently are: saleable, quarantine or damaged stock.</span><button class="btn btn-primary btn-lg" type="submit"><?= icon('check', 16) ?> Record return</button></div>
</form>
<script>
(function () {
  const esc = CalimaxUI.esc, wh = document.getElementById('wh'), box = document.getElementById('lines'), res = document.getElementById('results'), search = document.getElementById('search'); let lines = [];
  const bk = [['on_hand', 'Saleable'], ['quarantine', 'Quarantine'], ['damaged', 'Damaged']];
  function render() { document.getElementById('empty').hidden = lines.length > 0;
    box.innerHTML = lines.map((l, i) => { let body;
      if (l.tracking === 'serial') body = '<div class="chip-list">' + l.avail.map((s) => '<label class="sn-chip" style="cursor:pointer;"><input type="checkbox" data-i="' + i + '" data-sid="' + s.id + '" ' + (l.serials.includes(s.id) ? 'checked' : '') + '> ' + esc(s.serial_no) + ' <span class="text-muted">' + esc(s.status.replace('_', ' ')) + '</span></label>').join('') + '</div>';
      else if (l.tracking === 'batch') body = l.batches.map((b) => bk.filter((k) => b[k[0]] > 0).map((k) => '<div class="alloc-row"><div class="strong mono">' + esc(b.batch_no) + '<div class="cell-sub">' + k[1] + '</div></div><div class="num text-sm">Available <strong>' + b[k[0]].toLocaleString() + '</strong></div><input class="form-control num-input" type="number" min="0" max="' + b[k[0]] + '" data-i="' + i + '" data-b="' + b.id + '" data-k="' + k[0] + '" value="' + (l.q[b.id + ':' + k[0]] || '') + '" placeholder="Qty"><span></span><span></span></div>').join('')).join('') || '<div class="text-muted text-sm">No batches in this warehouse.</div>';
      else body = bk.filter((k) => k[0] !== 'quarantine' || l.quarantine > 0).map((k) => { const av = k[0] === 'damaged' ? l.damaged : (k[0] === 'quarantine' ? l.quarantine : l.saleable); return av > 0 ? '<div class="alloc-row"><div class="strong">' + k[1] + '</div><div class="num text-sm">Available <strong>' + av.toLocaleString() + '</strong></div><input class="form-control num-input" type="number" min="0" max="' + av + '" data-i="' + i + '" data-k="' + k[0] + '" data-plain value="' + (l.q[k[0]] || '') + '"><span></span><span></span></div>' : ''; }).join('');
      return '<div class="item-block mt-3"><div class="ib-head"><div class="ib-title">' + esc(l.name) + ' <span class="text-muted mono">' + esc(l.code) + '</span></div><button type="button" class="btn btn-secondary btn-sm" data-rm="' + i + '">Remove</button></div><div class="ib-body">' + body + '</div></div>'; }).join(''); sync(); }
  function sync() { const out = []; lines.forEach((l) => { if (l.tracking === 'serial') { if (l.serials.length) out.push({ product_id: l.id, tracking: 'serial', serials: l.serials }); } else Object.keys(l.q).forEach((k) => { if (+l.q[k] > 0) { const p = k.split(':'); out.push(l.tracking === 'batch' ? { product_id: l.id, tracking: 'batch', batch_id: +p[0], bucket: p[1], qty: +l.q[k] } : { product_id: l.id, tracking: 'none', bucket: k, qty: +l.q[k] }); } }); }); document.getElementById('lines_json').value = JSON.stringify(out); }
  box.addEventListener('input', (e) => { const i = e.target.dataset.i; if (i === undefined || !e.target.dataset.k) return; lines[i].q[e.target.dataset.b ? e.target.dataset.b + ':' + e.target.dataset.k : e.target.dataset.k] = +e.target.value || 0; sync(); });
  box.addEventListener('change', (e) => { if (e.target.dataset.sid) { const l = lines[e.target.dataset.i], id = +e.target.dataset.sid; l.serials = e.target.checked ? [...new Set([...l.serials, id])] : l.serials.filter((x) => x !== id); sync(); } });
  box.addEventListener('click', (e) => { const b = e.target.closest('[data-rm]'); if (b) { lines.splice(+b.dataset.rm, 1); render(); } });
  wh.addEventListener('change', () => { lines = []; render(); }); let t = null;
  search.addEventListener('input', () => { clearTimeout(t); const q = search.value.trim(); if (!q) { res.hidden = true; return; } t = setTimeout(() => fetch(APP.url + 'api/stock_lookup.php?q=' + encodeURIComponent(q) + '&warehouse_id=' + wh.value).then((r) => r.json()).then((list) => { res.innerHTML = list.length ? '' : '<div class="search-empty">No products found.</div>';
    list.forEach((p) => { const row = document.createElement('div'); row.className = 'search-item'; row.innerHTML = '<span class="si-main"><div class="si-title">' + esc(p.name) + '</div><div class="si-sub">' + esc(p.code) + '</div></span>'; row.onclick = () => { if (!lines.find((l) => l.id === p.id)) lines.push({ id: p.id, code: p.code, name: p.name, tracking: p.tracking, saleable: p.saleable, damaged: p.damaged, quarantine: p.quarantine, batches: p.batches, avail: p.serials, serials: [], q: {} }); res.hidden = true; search.value = ''; render(); }; res.appendChild(row); }); res.hidden = false; }), 200); });
  document.addEventListener('click', (e) => { if (e.target !== search && !res.contains(e.target)) res.hidden = true; });
  document.getElementById('rf').addEventListener('submit', (e) => { sync(); if (document.getElementById('lines_json').value === '[]') { e.preventDefault(); CalimaxUI.toast('Add at least one item with a quantity.', 'error'); } }); render();
})();
</script>
<?php else: ?>
<div class="card flush"><div class="table-wrap"><table class="data-table"><thead><tr><th>Return</th><th>Date</th><th>Supplier</th><th>Warehouse</th><th>Reason</th><th class="num">Units</th><th class="num">Value</th><th>By</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr class="<?= $hl === (int) $r['id'] ? 'row-warn' : '' ?>"><td class="strong mono"><?= e($r['return_no']) ?></td><td class="text-secondary nowrap"><?= e(fmt_date($r['return_date'])) ?></td><td><?= e($r['supplier']) ?></td><td class="text-secondary"><?= e($r['wh']) ?></td><td class="text-secondary"><?= e($r['reason'] ?? '') ?></td><td class="num"><?= fmt_qty($r['units']) ?></td><td class="num strong"><?= format_money($r['total']) ?></td><td class="text-secondary"><?= e($r['who'] ?? '—') ?></td></tr><?php endforeach; ?></tbody></table>
<?php if (!$rows) echo empty_state('undo-2', 'No returns to suppliers', 'Send defective or recalled goods back from here, or from a customer return inspection.'); ?></div></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
