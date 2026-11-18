<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('trace.view');

$pdo = db();
$id = input_int($_GET, 'id');
$s = $pdo->prepare('SELECT b.*, p.name, p.code, p.base_unit, su.name AS supplier, g.grn_no FROM batches b JOIN products p ON p.id = b.product_id LEFT JOIN suppliers su ON su.id = b.supplier_id LEFT JOIN goods_receipts g ON g.id = b.grn_id WHERE b.id = :id');
$s->execute([':id' => $id]); $b = $s->fetch();
if (!$b) { flash('error', 'Batch not found.'); redirect('/trace/batches.php'); }

if (is_post() && can('batches.manage')) {
    verify_csrf(); $a = input($_POST, 'action');
    try {
        if ($a === 'quarantine') { batch_hold($id, true, input($_POST, 'note')); flash('success', 'Batch quarantined. It cannot be picked or dispatched.'); }
        elseif ($a === 'release') { batch_hold($id, false, input($_POST, 'note')); flash('success', 'Batch released.'); }
        elseif ($a === 'recall') { $rid = recall_start($id, input($_POST, 'reason')); flash('success', 'Recall started. Affected customers have been identified.'); redirect('/trace/recall_view.php?id=' . $rid); }
    } catch (RuntimeException $e) { flash('error', $e->getMessage()); }
    redirect('/trace/batch_view.php?id=' . $id);
}
$stock = $pdo->prepare('SELECT s.*, w.name AS wh FROM stock s JOIN warehouses w ON w.id = s.warehouse_id WHERE s.batch_id = :b ORDER BY w.name'); $stock->execute([':b' => $id]); $stock = $stock->fetchAll();
$tot = ['on_hand' => 0, 'quarantine' => 0, 'damaged' => 0]; foreach ($stock as $r) foreach ($tot as $k => $_) $tot[$k] += (int) $r[$k];
$custs = $pdo->prepare("SELECT c.id, c.business_name, SUM(da.qty - da.rejected_qty) AS q, COUNT(DISTINCT d.id) AS n, MAX(d.dispatched_at) AS last, GROUP_CONCAT(DISTINCT si.inv_no ORDER BY si.id SEPARATOR ', ') AS invs
    FROM delivery_allocations da JOIN delivery_items di ON di.id = da.delivery_item_id JOIN deliveries d ON d.id = di.delivery_id JOIN customers c ON c.id = d.customer_id LEFT JOIN sales_invoice_items sii ON sii.delivery_item_id = di.id LEFT JOIN sales_invoices si ON si.id = sii.invoice_id
    WHERE da.batch_id = :b AND d.status IN ('dispatched','delivered') GROUP BY c.id HAVING q > 0 ORDER BY q DESC"); $custs->execute([':b' => $id]); $custs = $custs->fetchAll();
$dispatched = array_sum(array_column($custs, 'q'));
$events = $pdo->prepare('SELECT te.*, u.full_name FROM trace_events te LEFT JOIN users u ON u.id = te.user_id WHERE te.entity_type = \'batch\' AND te.entity_id = :id ORDER BY te.id'); $events->execute([':id' => $id]); $events = $events->fetchAll();
$recall = $pdo->query("SELECT * FROM batch_recalls WHERE batch_id = $id ORDER BY id DESC LIMIT 1")->fetch();
$refLink = fn($t, $rid) => match ($t) { 'goods_receipt' => '/purchasing/grn_view.php?id=' . $rid, 'delivery' => '/sales/delivery_view.php?id=' . $rid, 'sales_return' => '/sales/return_view.php?id=' . $rid, 'batch_recall' => '/trace/recall_view.php?id=' . $rid, 'stock_transfer' => '/warehouse/transfer_view.php?id=' . $rid, default => null };

$pageTitle = $b['batch_no'];
require __DIR__ . '/../includes/header.php';
$act = '';
if (can('batches.manage')) {
    if ($b['status'] === 'active') $act .= '<button class="btn btn-secondary" type="button" data-modal-open="#holdModal">' . icon('pause-circle', 16) . ' Quarantine</button>';
    if ($b['status'] === 'quarantined') $act .= '<button class="btn btn-secondary" type="button" data-modal-open="#releaseModal">' . icon('play-circle', 16) . ' Release</button>';
    if ($b['status'] !== 'recalled') $act .= '<button class="btn btn-danger" type="button" data-modal-open="#recallModal">' . icon('siren', 16) . ' Start recall</button>';
}
page_header($b['batch_no'], e($b['name']) . ' · ' . status_badge($b['status']) . ($b['expiry_date'] ? ' · ' . expiry_badge($b['expiry_date']) : ''), $act, [['Batches', '/trace/batches.php'], [$b['batch_no'], null]]);
if ($b['status'] === 'recalled' && $recall) echo next_step('siren', 'This batch is recalled', e($recall['reason']) . ' — it is blocked from sale and dispatch.', '<a class="btn btn-primary" href="' . e(app_url('/trace/recall_view.php?id=' . $recall['id'])) . '">Open recall report</a>', 'bad');
elseif ($b['status'] === 'quarantined') echo next_step('pause-circle', 'Batch quarantined', e($b['status_note'] ?? '') . ' It cannot be picked or dispatched until released.', '', 'warn');
elseif ($b['status'] === 'expired') echo next_step('calendar-x', 'Batch expired', 'Blocked from normal sale. Dispose of the stock with a stock adjustment (reason: Expired).', can('inventory.adjust') ? '<a class="btn btn-primary" href="' . e(app_url('/warehouse/adjustment_form.php?reason=expired')) . '">Write off expired stock</a>' : '', 'bad');
?>
<div class="stat-strip">
  <div><div class="sl">Received</div><div class="sv"><?= fmt_qty($b['received_qty']) ?></div></div>
  <div><div class="sl">Dispatched to customers</div><div class="sv"><?= fmt_qty($dispatched) ?></div></div>
  <div><div class="sl">On hand</div><div class="sv"><?= fmt_qty($tot['on_hand']) ?></div></div>
  <div><div class="sl">Quarantine</div><div class="sv <?= $tot['quarantine'] ? 'text-warning' : '' ?>"><?= fmt_qty($tot['quarantine']) ?></div></div>
  <div><div class="sl">Damaged</div><div class="sv"><?= fmt_qty($tot['damaged']) ?></div></div>
</div>
<div class="layout-2-1 mb-4">
  <div class="card"><div class="card-head"><div><h3>Batch history</h3><div class="sub">Where it came from, where it went and what happened to it</div></div></div>
    <div class="trace"><?php foreach ($events as $e): $lk = $e['ref_type'] && $e['ref_id'] ? $refLink($e['ref_type'], $e['ref_id']) : null; ?>
      <div class="trace-item t-<?= e($e['event_type']) ?>"><span class="dot"></span><div class="t-title"><?= e($e['title']) ?></div><div class="t-when"><?= e(fmt_datetime($e['created_at'])) ?><?= $e['ref_no'] ? ' · ' . ($lk ? '<a class="link" href="' . e(app_url($lk)) . '">' . e($e['ref_no']) . '</a>' : e($e['ref_no'])) : '' ?></div><?php if ($e['detail']): ?><div class="t-detail"><?= e($e['detail']) ?></div><?php endif; ?></div><?php endforeach; ?>
      <?php if (!$events): ?><div class="text-muted">No history recorded.</div><?php endif; ?></div></div>
  <div class="grid">
    <div class="card"><h3 class="mb-3">Batch details</h3><dl class="desc-list"><dt>Product</dt><dd><a class="link" href="<?= e(app_url('/catalog/product_view.php?id=' . $b['product_id'])) ?>"><?= e($b['name']) ?></a></dd><dt>Supplier</dt><dd><?= e($b['supplier'] ?? '—') ?></dd><dt>Goods receipt</dt><dd><?= $b['grn_id'] ? '<a class="link" href="' . e(app_url('/purchasing/grn_view.php?id=' . $b['grn_id'])) . '">' . e($b['grn_no']) . '</a>' : '—' ?></dd><dt>Received</dt><dd><?= e(fmt_date($b['received_date'])) ?></dd><dt>Manufactured</dt><dd><?= e(fmt_date($b['mfg_date'])) ?></dd><dt>Expires</dt><dd><?= e(fmt_date($b['expiry_date'])) ?></dd><?php if (can('products.view_cost')): ?><dt>Unit cost</dt><dd><?= format_money($b['unit_cost']) ?></dd><?php endif; ?></dl></div>
    <div class="card flush"><div class="card-head"><h3>Stock by warehouse</h3></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Warehouse</th><th class="num">On hand</th><th class="num">Quar.</th><th class="num">Dmg</th></tr></thead><tbody><?php foreach ($stock as $r): ?><tr><td><?= e($r['wh']) ?></td><td class="num strong"><?= fmt_qty($r['on_hand']) ?></td><td class="num"><?= (int) $r['quarantine'] ?: '—' ?></td><td class="num"><?= (int) $r['damaged'] ?: '—' ?></td></tr><?php endforeach; ?></tbody></table><?php if (!$stock) echo '<div class="text-muted text-sm" style="padding:14px 18px;">No stock records.</div>'; ?></div></div>
  </div>
</div>
<div class="card flush"><div class="card-head"><div><h3>Customers who received this batch</h3><div class="sub">Traced through delivery notes and invoices</div></div></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Customer</th><th class="num">Quantity</th><th class="num">Deliveries</th><th>Invoices</th><th>Last dispatched</th></tr></thead><tbody>
  <?php foreach ($custs as $c): ?><tr><td class="strong"><a href="<?= e(app_url('/sales/customer_view.php?id=' . $c['id'])) ?>"><?= e($c['business_name']) ?></a></td><td class="num strong"><?= fmt_qty($c['q']) ?> <span class="text-muted text-sm"><?= e($b['base_unit']) ?></span></td><td class="num"><?= (int) $c['n'] ?></td><td class="text-sm"><?= e($c['invs'] ?? '—') ?></td><td class="text-secondary nowrap"><?= e(fmt_date($c['last'])) ?></td></tr><?php endforeach; ?></tbody></table>
  <?php if (!$custs) echo empty_state('users', 'Not sold yet', 'No customer has received stock from this batch.'); ?></div></div>
<?php if (can('batches.manage')): ?>
<div class="modal-overlay" id="holdModal" hidden><div class="modal sm"><div class="modal-header"><h3>Quarantine batch</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="quarantine"><div class="form-group"><label>Reason</label><input class="form-control" type="text" name="note" required maxlength="200" placeholder="e.g. Quality check pending"></div><p class="text-secondary text-sm">Blocks picking and dispatch. Open pick lists that include this batch go back to “to pick”.</p><div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Quarantine</button></div></form></div></div>
<div class="modal-overlay" id="releaseModal" hidden><div class="modal sm"><div class="modal-header"><h3>Release batch</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="release"><div class="form-group"><label>Note</label><input class="form-control" type="text" name="note" maxlength="200" placeholder="e.g. Passed inspection"></div><div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Release</button></div></form></div></div>
<div class="modal-overlay" id="recallModal" hidden><div class="modal sm"><div class="modal-header"><h3>Start a recall — <?= e($b['batch_no']) ?></h3><button class="modal-close" type="button" data-modal-close>&times;</button></div><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="recall"><div class="form-group"><label>Reason / manufacturer notice</label><input class="form-control" type="text" name="reason" required maxlength="255" placeholder="e.g. Contamination reported by the manufacturer"></div>
  <div class="callout bad text-sm">The batch is blocked from every sale and dispatch, remaining stock is quarantined and a list of affected customers is generated.</div><div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-danger" type="submit"><?= icon('siren', 16) ?> Start recall</button></div></form></div></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
