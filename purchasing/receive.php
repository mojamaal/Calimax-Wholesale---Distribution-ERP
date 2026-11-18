<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.receive');

$pdo = db();
$poId = input_int($_GET, 'po_id');
if (!$poId) {
    // Pick an order to receive against
    $open = $pdo->query("SELECT po.*, s.name AS supplier, w.code AS wh, (SELECT COALESCE(SUM(qty - received_qty),0) FROM purchase_order_items WHERE po_id = po.id) AS remaining FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id JOIN warehouses w ON w.id = po.warehouse_id WHERE po.status IN ('approved','sent','partially_received') ORDER BY po.expected_date IS NULL, po.expected_date")->fetchAll();
    $pageTitle = 'Receive goods';
    require __DIR__ . '/../includes/header.php';
    page_header('Receive goods', 'Choose the purchase order the delivery belongs to. You will record exactly what arrived.');
    nav_purchasing();
    ?><div class="card flush"><div class="table-wrap"><table class="data-table"><thead><tr><th>Purchase order</th><th>Supplier</th><th>Expected</th><th>Warehouse</th><th class="num">Units still expected</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($open as $o): $late = $o['expected_date'] && $o['expected_date'] < date('Y-m-d'); ?><tr><td class="strong"><a href="<?= e(app_url('/purchasing/order_view.php?id=' . $o['id'])) ?>"><?= e($o['po_no']) ?></a></td><td><?= e($o['supplier']) ?></td><td class="nowrap <?= $late ? 'text-error fw-700' : 'text-secondary' ?>"><?= e(fmt_date($o['expected_date'])) ?><?= $late ? ' · overdue' : '' ?></td><td class="text-secondary"><?= e($o['wh']) ?></td><td class="num"><?= fmt_qty($o['remaining']) ?></td><td><?= status_badge($o['status']) ?></td><td class="actions"><a class="btn btn-primary btn-sm" href="<?= e(app_url('/purchasing/receive.php?po_id=' . $o['id'])) ?>">Receive</a></td></tr><?php endforeach; ?></tbody></table>
    <?php if (!$open) echo empty_state('package-check', 'No deliveries expected', 'Approved purchase orders appear here until their goods are received.'); ?></div></div><?php
    require __DIR__ . '/../includes/footer.php'; exit;
}
$s = $pdo->prepare('SELECT po.*, s.name AS supplier, w.name AS wh FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id JOIN warehouses w ON w.id = po.warehouse_id WHERE po.id = :id'); $s->execute([':id' => $poId]); $po = $s->fetch();
if (!$po || !in_array($po['status'], ['approved', 'sent', 'partially_received'], true)) { flash('error', 'This purchase order is not open for receiving.'); redirect('/purchasing/receive.php'); }
$lines = array_values(array_filter(po_lines_with_balance($poId), fn($l) => (int) $l['remaining'] > 0));
$errors = [];

if (is_post()) {
    verify_csrf();
    $gl = [];
    foreach ($lines as $l) {
        $in = $_POST['line'][$l['id']] ?? [];
        $recv = max(0, (int) ($in['recv'] ?? 0)); $rej = max(0, (int) ($in['rej'] ?? 0));
        if ($recv === 0 && $rej === 0) continue;
        $g = ['po_item_id' => (int) $l['id'], 'product_id' => (int) $l['product_id'], 'qty_received' => $recv, 'qty_rejected' => $rej, 'reject_reason' => trim((string) ($in['reason'] ?? '')), 'unit_cost' => (float) $l['unit_cost']];
        if ($l['tracking'] === 'batch') foreach (($in['batch'] ?? []) as $b) { if ((int) ($b['qty'] ?? 0) > 0 || trim((string) ($b['no'] ?? '')) !== '') $g['batches'][] = ['batch_no' => trim((string) $b['no']), 'qty' => (int) $b['qty'], 'mfg_date' => trim((string) ($b['mfg'] ?? '')), 'expiry_date' => trim((string) ($b['exp'] ?? ''))]; }
        if ($l['tracking'] === 'serial') $g['serials'] = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($in['serials'] ?? ''))), fn($x) => $x !== ''));
        $gl[] = $g;
    }
    try {
        $grn = grn_post(['po_id' => $poId, 'supplier_id' => $po['supplier_id'], 'warehouse_id' => $po['warehouse_id'], 'received_date' => input_date($_POST, 'received_date', date('Y-m-d')), 'supplier_ref' => input($_POST, 'supplier_ref'), 'notes' => input($_POST, 'notes')], $gl);
        $left = (int) $pdo->query('SELECT COALESCE(SUM(qty - received_qty),0) FROM purchase_order_items WHERE po_id = ' . $poId)->fetchColumn();
        flash($left > 0 ? 'info' : 'success', 'Goods received into ' . $po['wh'] . '. ' . ($left > 0 ? fmt_qty($left) . ' unit(s) are still outstanding on the order.' : 'The purchase order is fully received.'));
        redirect('/purchasing/grn_view.php?id=' . $grn);
    } catch (RuntimeException $e) { $errors[] = $e->getMessage(); }
}
$pageTitle = 'Receive goods';
$pageScripts = [app_url('/assets/js/lines.js') . '?v=1'];
require __DIR__ . '/../includes/header.php';
page_header('Receive goods — ' . $po['po_no'], e($po['supplier']) . ' → ' . e($po['wh']) . '. Enter what actually arrived: a short or damaged delivery is recorded exactly as it happened.', '<a class="btn btn-secondary" href="' . e(app_url('/purchasing/order_view.php?id=' . $poId)) . '">Cancel</a>', [['Purchase orders', '/purchasing/orders.php'], [$po['po_no'], '/purchasing/order_view.php?id=' . $poId], ['Receive', null]]);
foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<form method="post" id="rcvForm"><?= csrf_field() ?>
  <div class="card mb-4"><div class="form-row">
    <div class="form-group"><label>Date received</label><input class="form-control" type="date" name="received_date" value="<?= e($_POST['received_date'] ?? date('Y-m-d')) ?>"></div>
    <div class="form-group"><label>Supplier delivery note / invoice ref</label><input class="form-control" type="text" name="supplier_ref" maxlength="60" value="<?= e($_POST['supplier_ref'] ?? '') ?>" placeholder="Printed on the delivery paperwork"></div>
    <div class="form-group" style="flex:2"><label>Notes <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="notes" maxlength="255" value="<?= e($_POST['notes'] ?? '') ?>" placeholder="e.g. Driver reported 200 bottles were left at the factory"></div></div></div>

  <?php foreach ($lines as $l): $in = $_POST['line'][$l['id']] ?? []; $rem = (int) $l['remaining']; ?>
  <div class="item-block rcv" data-remaining="<?= $rem ?>" data-tracking="<?= e($l['tracking']) ?>" data-expiry="<?= (int) $l['track_expiry'] ?>">
    <div class="ib-head"><div class="ib-title"><?= e($l['name']) ?> <span class="text-muted mono"><?= e($l['code']) ?></span> <?= $l['tracking'] !== 'none' ? '<span class="tag ' . $l['tracking'] . '">' . e($l['tracking']) . '</span>' : '' ?><?= $l['track_expiry'] ? ' <span class="tag exp">expiry</span>' : '' ?></div>
      <div class="text-sm">Ordered <strong><?= fmt_pack_qty((int) $l['qty'], (int) $l['pack_factor'], $l['pack_name'], $l['base_unit']) ?></strong> · already received <strong><?= fmt_qty($l['received_qty']) ?></strong> · expecting <strong><?= fmt_qty($rem) ?></strong></div></div>
    <div class="ib-body">
      <div class="form-row" style="align-items:end;">
        <div class="form-group mb-0"><label>Received (<?= e($l['base_unit']) ?>)</label><input class="form-control num-input recv" type="number" min="0" max="<?= $rem ?>" name="line[<?= (int) $l['id'] ?>][recv]" value="<?= e($in['recv'] ?? $rem) ?>"></div>
        <div class="form-group mb-0"><label>Rejected / damaged</label><input class="form-control num-input rej" type="number" min="0" name="line[<?= (int) $l['id'] ?>][rej]" value="<?= e($in['rej'] ?? 0) ?>"></div>
        <div class="form-group mb-0" style="flex:2"><label>Reason <span class="label-opt">(if rejected)</span></label><input class="form-control" type="text" name="line[<?= (int) $l['id'] ?>][reason]" maxlength="150" value="<?= e($in['reason'] ?? '') ?>" placeholder="e.g. Broken seals"></div>
        <div class="form-group mb-0"><div class="short-badge"></div></div></div>

      <?php if ($l['tracking'] === 'batch'): ?>
        <div class="mt-3"><label>Batches received <span class="label-opt">(batch numbers add up to the received quantity)</span></label>
          <div class="batch-rows"></div><button class="btn btn-secondary btn-sm mt-2 add-batch" type="button"><?= icon('plus', 14) ?> Add another batch</button>
          <div class="text-sm mt-2 batch-sum"></div></div>
      <?php elseif ($l['tracking'] === 'serial'): ?>
        <div class="mt-3" data-sn>
          <label>Serial numbers <span class="label-opt">(scan each unit — one per unit received)</span></label>
          <div class="scan-box"><input class="form-control" type="text" data-sn-input placeholder="Scan or type a serial number and press Enter" autocomplete="off"><button class="btn btn-secondary" type="button" data-sn-add>Add</button><button class="btn btn-secondary" type="button" data-sn-range>Add a range…</button></div>
          <div class="scan-hint">Paste many at once (separated by spaces, commas or new lines). Duplicates and already-registered numbers are rejected. <span data-sn-count class="fw-700"></span></div>
          <div class="chip-list mt-2" data-sn-list></div>
          <textarea hidden data-sn-value name="line[<?= (int) $l['id'] ?>][serials]"><?= e($in['serials'] ?? '') ?></textarea>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="action-bar"><span class="text-muted text-sm">Stock increases only by what you enter here. Any shortage stays open on the purchase order.</span><button class="btn btn-primary btn-lg" type="submit"><?= icon('package-check', 16) ?> Confirm goods received</button></div>
</form>
<script>
(function () {
  const esc = CalimaxUI.esc, blocks = document.querySelectorAll('.rcv');
  const posted = <?= js_json(array_map(fn($l) => $_POST['line'][$l['id']]['batch'] ?? [], $lines)) ?>;
  blocks.forEach((blk, idx) => {
    const rem = +blk.dataset.remaining, tracking = blk.dataset.tracking, exp = blk.dataset.expiry === '1';
    const recv = blk.querySelector('.recv'), badge = blk.querySelector('.short-badge');
    const lineId = recv.name.match(/\[(\d+)\]/)[1];
    let scanner = null;
    function status() {
      const r = +recv.value || 0, short = rem - r;
      badge.innerHTML = r === 0 ? '<span class="badge badge-neutral">Nothing received</span>' : (short > 0 ? '<span class="badge badge-warning">Short by ' + short.toLocaleString() + '</span>' : (short < 0 ? '<span class="badge badge-error">More than ordered</span>' : '<span class="badge badge-success">Complete</span>'));
      blk.classList.toggle('done', r > 0 && short === 0);
      if (tracking === 'batch') batchSum(); }
    if (tracking === 'serial') {
      scanner = CalimaxLines.SerialScanner(blk.querySelector('[data-sn]'), { expected: +recv.value || 0, validate: (s) => fetch(APP.url + 'api/serial_check.php?mode=receive&serial=' + encodeURIComponent(s)).then((r) => r.json()) });
      recv.addEventListener('input', () => scanner.setExpected(+recv.value || 0));
    }
    if (tracking === 'batch') {
      const rows = blk.querySelector('.batch-rows');
      const row = (b, i) => '<div class="alloc-row" style="grid-template-columns:minmax(140px,1.3fr) 150px ' + (exp ? '150px' : '0px') + ' 120px auto;"><input class="form-control mono" type="text" name="line[' + lineId + '][batch][' + i + '][no]" placeholder="Batch no." value="' + esc(b.no || '') + '"><div><label class="text-sm text-muted mb-0">Manufactured</label><input class="form-control" type="date" name="line[' + lineId + '][batch][' + i + '][mfg]" value="' + esc(b.mfg || '') + '"></div>'
        + (exp ? '<div><label class="text-sm text-muted mb-0">Expiry ✱</label><input class="form-control" type="date" name="line[' + lineId + '][batch][' + i + '][exp]" value="' + esc(b.exp || '') + '"></div>' : '<span></span>') + '<div><label class="text-sm text-muted mb-0">Quantity</label><input class="form-control num-input bq" type="number" min="0" name="line[' + lineId + '][batch][' + i + '][qty]" value="' + esc(b.qty || '') + '"></div><button type="button" class="btn btn-secondary btn-sm btn-icon" data-rmb aria-label="Remove">&times;</button></div>';
      let n = 0; const add = (b) => { rows.insertAdjacentHTML('beforeend', row(b || {}, n++)); };
      const p = posted[idx] && Object.keys(posted[idx]).length ? Object.values(posted[idx]) : [{ qty: recv.value }];
      p.forEach((b) => add({ no: b.no, mfg: b.mfg, exp: b.exp, qty: b.qty }));
      blk.querySelector('.add-batch').addEventListener('click', () => add({}));
      rows.addEventListener('click', (e) => { if (e.target.closest('[data-rmb]') && rows.children.length > 1) { e.target.closest('.alloc-row').remove(); batchSum(); } });
      rows.addEventListener('input', batchSum);
      window.batchSum = batchSum;
      function batchSum() { const s = [...rows.querySelectorAll('.bq')].reduce((a, i) => a + (+i.value || 0), 0), r = +recv.value || 0, el = blk.querySelector('.batch-sum'); el.innerHTML = 'Batches total <strong>' + s.toLocaleString() + '</strong> of ' + r.toLocaleString() + ' received' + (s !== r ? ' — <span class="text-error">must match</span>' : ' <span class="text-success">✓</span>'); }
      recv.addEventListener('input', () => { if (rows.children.length === 1) rows.querySelector('.bq').value = recv.value; batchSum(); });
    }
    recv.addEventListener('input', status); status();
  });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
