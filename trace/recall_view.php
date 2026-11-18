<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('trace.view');

$pdo = db();
$id = input_int($_GET, 'id');
$s = $pdo->prepare('SELECT br.*, b.batch_no, b.product_id, b.received_qty, b.expiry_date, p.name, p.base_unit, su.name AS supplier, u.full_name AS who FROM batch_recalls br JOIN batches b ON b.id = br.batch_id JOIN products p ON p.id = b.product_id LEFT JOIN suppliers su ON su.id = b.supplier_id LEFT JOIN users u ON u.id = br.initiated_by WHERE br.id = :id');
$s->execute([':id' => $id]); $r = $s->fetch();
if (!$r) { flash('error', 'Recall not found.'); redirect('/trace/recalls.php'); }

if (is_post() && can('batches.manage')) {
    verify_csrf(); $a = input($_POST, 'action');
    try {
        if ($a === 'contacted') { recall_mark_contacted($id, input_int($_POST, 'customer_id'), input($_POST, 'note')); flash('success', 'Marked as contacted.'); }
        elseif ($a === 'recover') { recall_record_recovery($id, input_int($_POST, 'customer_id'), input_int($_POST, 'qty'), input_int($_POST, 'warehouse_id'), input($_POST, 'note')); flash('success', 'Recovered stock recorded and placed in quarantine.'); }
        elseif ($a === 'close') { recall_close($id); flash('success', 'Recall closed.'); }
    } catch (RuntimeException $e) { flash('error', $e->getMessage()); }
    redirect('/trace/recall_view.php?id=' . $id);
}
$custs = $pdo->prepare('SELECT rc.*, c.business_name, c.phone, c.contact_person FROM recall_customers rc JOIN customers c ON c.id = rc.customer_id WHERE rc.recall_id = :r ORDER BY rc.qty_delivered DESC'); $custs->execute([':r' => $id]); $custs = $custs->fetchAll();
$stock = $pdo->query("SELECT COALESCE(SUM(on_hand),0) oh, COALESCE(SUM(quarantine),0) qr, COALESCE(SUM(damaged),0) dm FROM stock WHERE batch_id = " . (int) $r['batch_id'])->fetch();
$recovered = array_sum(array_column($custs, 'qty_recovered'));
$outstanding = array_sum(array_column($custs, 'qty_delivered')) - $recovered;   // still with customers (net of earlier returns)
$bid = (int) $r['batch_id'];
$dispatched = (int) $pdo->query("SELECT COALESCE(SUM(da.qty - da.rejected_qty),0) FROM delivery_allocations da JOIN delivery_items di ON di.id = da.delivery_item_id JOIN deliveries d ON d.id = di.delivery_id WHERE da.batch_id = $bid AND d.status IN ('dispatched','delivered')")->fetchColumn();
$returnedEarlier = (int) $pdo->query("SELECT COALESCE(SUM(sri.qty),0) FROM sales_return_items sri JOIN sales_returns sr ON sr.id = sri.return_id WHERE sri.batch_id = $bid AND sr.status IN ('received','closed')")->fetchColumn();
$accounted = (int) $stock['oh'] + (int) $stock['qr'] + (int) $stock['dm'] + $outstanding;
$events = $pdo->query("SELECT te.*, u.full_name FROM trace_events te LEFT JOIN users u ON u.id = te.user_id WHERE te.entity_type = 'batch' AND te.entity_id = " . (int) $r['batch_id'] . " ORDER BY te.id")->fetchAll();
$whs = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY id')->fetchAll();

$pageTitle = $r['recall_no'];
require __DIR__ . '/../includes/header.php';
page_header($r['recall_no'] . ' · ' . $r['name'], 'Batch <a class="link mono" href="' . e(app_url('/trace/batch_view.php?id=' . $r['batch_id'])) . '">' . e($r['batch_no']) . '</a> · ' . status_badge($r['status'] === 'open' ? 'recalled' : 'closed'),
    $r['status'] === 'open' && can('batches.manage') ? '<form method="post" class="inline-form" data-confirm="Close this recall?">' . csrf_field() . '<input type="hidden" name="action" value="close"><button class="btn btn-secondary" type="submit">Close recall</button></form>' : '', [['Recalls', '/trace/recalls.php'], [$r['recall_no'], null]]);
echo next_step('siren', 'Recall reason', e($r['reason']) . ' · started ' . e(fmt_datetime($r['initiated_at'])) . ' by ' . e($r['who'] ?? '') . '.', '', 'bad');
?>
<div class="card mb-4">
  <div class="card-head"><div><h3>Recall report</h3><div class="sub">Where every unit of <?= e($r['name']) ?> — batch <?= e($r['batch_no']) ?> — is right now</div></div></div>
  <div class="grid grid-4">
    <div class="fact"><div class="fl">Originally received</div><div class="fv big"><?= fmt_qty($r['received_qty']) ?></div></div>
    <div class="fact"><div class="fl">Sold / dispatched to customers</div><div class="fv big"><?= fmt_qty($dispatched) ?></div><div class="text-sm text-muted"><?= fmt_qty($returnedEarlier + $recovered) ?> since returned or recovered</div></div>
    <div class="fact"><div class="fl">Remaining in warehouses</div><div class="fv big"><?= fmt_qty((int) $stock['oh'] + (int) $stock['qr'] + (int) $stock['dm']) ?></div><div class="text-sm text-muted">blocked from sale · incl. <?= fmt_qty($returnedEarlier + $recovered) ?> returned / recovered</div></div>
    <div class="fact"><div class="fl">Still with customers</div><div class="fv big <?= $outstanding > 0 ? 'text-error' : 'text-success' ?>"><?= fmt_qty($outstanding) ?></div><div class="text-sm text-muted">to be recovered</div></div>
  </div>
  <div class="age-bar mt-4"><?php $tot = max(1, $accounted); ?><span class="age-0" style="width:<?= ((int) $stock['oh'] + (int) $stock['qr'] + (int) $stock['dm']) / $tot * 100 ?>%" title="In warehouse"></span><span class="age-3" style="width:<?= $outstanding / $tot * 100 ?>%" title="With customers"></span></div>
  <div class="age-legend mt-2"><span><i class="age-0"></i>In warehouse (incl. recovered)</span><span><i class="age-3"></i>With customers, not yet recovered</span><span class="text-muted">Total accounted for: <strong><?= fmt_qty($accounted) ?></strong> of <?= fmt_qty($r['received_qty']) ?> received<?= $accounted < $r['received_qty'] ? ' (the rest was written off, transferred or returned to the supplier)' : '' ?></span></div>
</div>

<div class="layout-2-1">
  <div class="card flush"><div class="card-head"><div><h3>Affected customers</h3><div class="sub">Contact each one and record what comes back</div></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Customer</th><th>Contact</th><th class="num">Received</th><th class="num">Recovered</th><th class="num">Outstanding</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($custs as $c): $left = (int) $c['qty_delivered'] - (int) $c['qty_recovered']; ?>
      <tr><td class="strong"><a href="<?= e(app_url('/sales/customer_view.php?id=' . $c['customer_id'])) ?>"><?= e($c['business_name']) ?></a></td><td class="text-sm text-secondary"><?= e($c['contact_person'] ?? '') ?><div class="cell-sub"><?= e($c['phone'] ?? '') ?></div></td><td class="num"><?= fmt_qty($c['qty_delivered']) ?></td><td class="num text-success"><?= fmt_qty($c['qty_recovered']) ?></td><td class="num strong <?= $left > 0 ? 'text-error' : 'text-muted' ?>"><?= $left > 0 ? fmt_qty($left) : '—' ?></td><td><?= status_badge($c['status']) ?><?= $c['note'] ? '<div class="cell-sub">' . e($c['note']) . '</div>' : '' ?></td>
        <td class="actions"><?php if ($r['status'] === 'open' && can('batches.manage')): ?><?php if ($c['status'] === 'pending'): ?><button class="btn btn-secondary btn-sm" type="button" onclick="contact(<?= (int) $c['customer_id'] ?>, <?= e(json_encode($c['business_name'])) ?>)">Mark contacted</button><?php endif; ?><?php if ($left > 0): ?><button class="btn btn-primary btn-sm" type="button" onclick="recover(<?= (int) $c['customer_id'] ?>, <?= e(json_encode($c['business_name'])) ?>, <?= $left ?>)">Record recovery</button><?php endif; ?><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table><?php if (!$custs) echo empty_state('users', 'No customers received this batch', 'The batch was never dispatched.'); ?></div></div>
  <div class="card"><div class="card-head"><h3>Batch history</h3></div><div class="trace"><?php foreach ($events as $e): ?><div class="trace-item t-<?= e($e['event_type']) ?>"><span class="dot"></span><div class="t-title"><?= e($e['title']) ?></div><div class="t-when"><?= e(fmt_datetime($e['created_at'])) ?></div><?php if ($e['detail']): ?><div class="t-detail"><?= e($e['detail']) ?></div><?php endif; ?></div><?php endforeach; ?></div></div>
</div>
<?php if ($r['status'] === 'open' && can('batches.manage')): ?>
<div class="modal-overlay" id="contactModal" hidden><div class="modal sm"><div class="modal-header"><h3>Contacted — <span id="cName"></span></h3><button class="modal-close" type="button" data-modal-close>&times;</button></div><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="contacted"><input type="hidden" name="customer_id" id="cId"><div class="form-group"><label>Note</label><input class="form-control" type="text" name="note" maxlength="200" placeholder="e.g. Phoned the manager, will return stock Friday"></div><div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div></form></div></div>
<div class="modal-overlay" id="recoverModal" hidden><div class="modal sm"><div class="modal-header"><h3>Recovered stock — <span id="rName"></span></h3><button class="modal-close" type="button" data-modal-close>&times;</button></div><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="recover"><input type="hidden" name="customer_id" id="rId">
  <div class="form-row"><div class="form-group"><label>Quantity recovered</label><input class="form-control num-input" type="number" min="1" name="qty" id="rQty" required></div><div class="form-group"><label>Received into</label><select class="form-control" name="warehouse_id"><?php foreach ($whs as $w): ?><option value="<?= (int) $w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?></select></div></div>
  <div class="form-group"><label>Note</label><input class="form-control" type="text" name="note" maxlength="200"></div><p class="text-secondary text-sm">Recovered units go to quarantine. Issue the customer a credit note from their invoice.</p><div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Record recovery</button></div></form></div></div>
<script>function contact(id,n){document.getElementById('cId').value=id;document.getElementById('cName').textContent=n;document.getElementById('contactModal').hidden=false;}function recover(id,n,max){document.getElementById('rId').value=id;document.getElementById('rName').textContent=n;var q=document.getElementById('rQty');q.max=max;q.value=max;document.getElementById('recoverModal').hidden=false;}</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
