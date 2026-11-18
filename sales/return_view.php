<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('returns.create');

$pdo = db();
$id = input_int($_GET, 'id');
$s = $pdo->prepare('SELECT sr.*, c.business_name, si.inv_no, w.name AS wh_name, rq.full_name AS requester, ap.full_name AS approver, cn.cn_no FROM sales_returns sr JOIN customers c ON c.id = sr.customer_id JOIN sales_invoices si ON si.id = sr.invoice_id JOIN warehouses w ON w.id = sr.warehouse_id
    LEFT JOIN users rq ON rq.id = sr.requested_by LEFT JOIN users ap ON ap.id = sr.approved_by LEFT JOIN credit_notes cn ON cn.id = sr.credit_note_id WHERE sr.id = :id');
$s->execute([':id' => $id]); $r = $s->fetch();
if (!$r) { flash('error', 'Return not found.'); redirect('/sales/returns.php'); }

if (is_post()) {
    verify_csrf();
    $a = input($_POST, 'action');
    try {
        if ($a === 'approve' && can('returns.approve')) { return_decide($id, true, input($_POST, 'note')); flash('success', 'Return approved. Collect the goods and receive them at the warehouse.'); }
        elseif ($a === 'reject' && can('returns.approve')) { return_decide($id, false, input($_POST, 'note')); flash('success', 'Return rejected.'); }
        elseif ($a === 'receive' && can('returns.receive')) { return_receive($id); flash('success', 'Goods received into quarantine. Inspect each item next.'); }
        elseif ($a === 'inspect' && can('returns.inspect')) {
            return_resolve(input_int($_POST, 'line_id'), input($_POST, 'disposition'), input($_POST, 'note'), isset($_POST['give_credit']));
            flash('success', 'Inspection result recorded.');
        }
    } catch (RuntimeException $e) { flash('error', $e->getMessage()); }
    redirect('/sales/return_view.php?id=' . $id);
}
$lines = $pdo->prepare('SELECT sri.*, p.name, p.code, p.base_unit, p.tracking, b.batch_no, sn.serial_no FROM sales_return_items sri JOIN products p ON p.id = sri.product_id LEFT JOIN batches b ON b.id = sri.batch_id LEFT JOIN serial_numbers sn ON sn.id = sri.serial_id WHERE sri.return_id = :r ORDER BY sri.id');
$lines->execute([':r' => $id]); $lines = $lines->fetchAll();
$pending = count(array_filter($lines, fn($l) => $l['resolved_at'] === null));
$value = 0; foreach ($lines as $l) $value += line_amounts((int) $l['qty'], (float) $l['unit_price'], (float) $l['discount_pct'], (float) $l['tax_rate'])['total'];
$cur = ['requested' => 'requested', 'approved' => 'approved', 'received' => 'received', 'closed' => 'closed', 'rejected' => 'requested'][$r['status']];

$pageTitle = $r['rma_no'];
require __DIR__ . '/../includes/header.php';
page_header($r['rma_no'], e($r['business_name']) . ' · invoice <a class="link" href="' . e(app_url('/sales/invoice_view.php?id=' . $r['invoice_id'])) . '">' . e($r['inv_no']) . '</a> · ' . status_badge($r['status'] === 'received' ? 'under_inspection' : $r['status']), '', [['Returns', '/sales/returns.php'], [$r['rma_no'], null]]);
echo workflow_steps(['requested' => 'Requested', 'approved' => 'Approved', 'received' => 'Received & inspected', 'closed' => 'Credited / closed'], $cur, $r['status'] === 'rejected');
switch ($r['status']) {
    case 'requested': echo next_step('shield-question', 'Awaiting approval', 'A Sales Manager must approve this return before goods are collected.', can('returns.approve') ? '<button class="btn btn-primary" type="button" data-modal-open="#decideModal">Review &amp; decide</button>' : '', 'warn'); break;
    case 'approved': echo next_step('truck', 'Approved — receive the goods', 'When the goods reach ' . e($r['wh_name']) . ', receive them. They go to quarantine, not to saleable stock.', can('returns.receive') ? '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="receive"><button class="btn btn-primary" type="submit">' . icon('package-check', 16) . ' Receive into quarantine</button></form>' : '', 'info'); break;
    case 'received': echo next_step('search-check', $pending . ' item(s) to inspect', 'Decide for each item: restock, repair, replace, send back to the supplier or dispose. The credit note is issued automatically once every item is resolved.', '', 'warn'); break;
    case 'closed': echo next_step('check-circle-2', 'Return completed', $r['cn_no'] ? 'Credit note <strong>' . e($r['cn_no']) . '</strong> issued to the customer.' : 'Closed without a credit note.', '', 'good'); break;
    case 'rejected': echo next_step('x-circle', 'Return rejected', e($r['decision_note'] ?? ''), '', 'bad'); break;
}
?>
<div class="layout-2-1">
  <div class="card flush">
    <div class="card-head"><h3>Returned items</h3><span class="text-muted text-sm">Value at invoice price: <?= format_money($value) ?></span></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Product</th><th>Batch / serial</th><th class="num">Qty</th><th>Reason</th><th>Inspection result</th><?php if ($r['status'] === 'received' && can('returns.inspect')): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($lines as $l): ?>
        <tr>
          <td><span class="strong"><?= e($l['name']) ?></span><div class="cell-sub mono"><?= e($l['code']) ?></div></td>
          <td><?= $l['serial_no'] ? '<a class="sn-chip" style="padding:2px 9px;" href="' . e(app_url('/trace/serial_view.php?id=' . $l['serial_id'])) . '">' . e($l['serial_no']) . '</a>' : ($l['batch_no'] ? '<a class="link mono" href="' . e(app_url('/trace/batch_view.php?id=' . $l['batch_id'])) . '">' . e($l['batch_no']) . '</a>' : '<span class="text-muted">—</span>') ?></td>
          <td class="num strong"><?= fmt_qty($l['qty']) ?></td>
          <td class="text-sm"><?= e($l['reason'] ?? '—') ?><?= $l['condition_note'] ? '<div class="cell-sub">' . e($l['condition_note']) . '</div>' : '' ?></td>
          <td><?php if ($l['disposition'] === 'pending'): ?><span class="badge badge-neutral"><?= $r['status'] === 'received' ? 'Awaiting inspection' : 'Not received yet' ?></span>
            <?php else: ?><span class="badge badge-<?= ['restock' => 'success', 'repair' => 'warning', 'replace' => 'info', 'return_supplier' => 'neutral', 'dispose' => 'error'][$l['disposition']] ?>"><?= e(ucwords(str_replace('_', ' ', $l['disposition']))) ?></span><?= $l['resolved_at'] ? ($l['give_credit'] ? ' <span class="text-sm text-success">credited</span>' : ' <span class="text-sm text-muted">no credit</span>') : ' <span class="text-sm text-warning">in progress</span>' ?><?php endif; ?></td>
          <?php if ($r['status'] === 'received' && can('returns.inspect')): ?><td class="actions"><?php if ($l['resolved_at'] === null): ?><button class="btn btn-primary btn-sm" type="button" onclick='inspect(<?= js_json(['id' => $l['id'], 'name' => $l['name'], 'serial' => (bool) $l['serial_id'], 'sn' => $l['serial_no'], 'cur' => $l['disposition']]) ?>)'>Inspect</button><?php endif; ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card"><h3 class="mb-3">Return details</h3><dl class="desc-list">
    <dt>Customer</dt><dd><?= e($r['business_name']) ?></dd><dt>Invoice</dt><dd><a class="link" href="<?= e(app_url('/sales/invoice_view.php?id=' . $r['invoice_id'])) ?>"><?= e($r['inv_no']) ?></a></dd><dt>Warehouse</dt><dd><?= e($r['wh_name']) ?></dd>
    <dt>Requested by</dt><dd><?= e($r['requester'] ?? '—') ?></dd><dt>Reason</dt><dd><?= e($r['reason'] ?? '—') ?></dd>
    <?php if ($r['approver']): ?><dt>Decision by</dt><dd><?= e($r['approver']) ?><div class="cell-sub"><?= e(fmt_datetime($r['approved_at'])) ?></div></dd><?php endif; ?>
    <?php if ($r['received_at']): ?><dt>Received</dt><dd><?= e(fmt_datetime($r['received_at'])) ?></dd><?php endif; ?>
    <?php if ($r['cn_no']): ?><dt>Credit note</dt><dd><a class="link" href="<?= e(app_url('/sales/credit_notes.php?highlight=' . $r['credit_note_id'])) ?>"><?= e($r['cn_no']) ?></a></dd><?php endif; ?></dl>
    <?php if ($r['decision_note']): ?><div class="callout mt-3"><?= e($r['decision_note']) ?></div><?php endif; ?>
    <div class="callout info mt-3 text-sm"><strong>Why quarantine?</strong> A returned item is never added straight back to saleable stock. It is inspected first so damaged or unsafe goods are not resold.</div></div>
</div>

<?php if (can('returns.approve') && $r['status'] === 'requested'): ?>
<div class="modal-overlay" id="decideModal" hidden><div class="modal sm"><div class="modal-header"><h3>Decide on <?= e($r['rma_no']) ?></h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><div class="form-group"><label>Note <span class="label-opt">(required when rejecting)</span></label><input class="form-control" type="text" name="note" maxlength="255"></div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-danger-outline" type="submit" name="action" value="reject" data-no-busy>Reject</button><button class="btn btn-primary" type="submit" name="action" value="approve" data-no-busy>Approve</button></div></form></div></div>
<?php endif; ?>
<?php if (can('returns.inspect') && $r['status'] === 'received'): ?>
<div class="modal-overlay" id="inspectModal" hidden><div class="modal">
  <div class="modal-header"><h3>Inspect — <span id="iName"></span></h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="inspect"><input type="hidden" name="line_id" id="iLine">
    <div class="option-cards" id="iOptions"></div>
    <div class="form-group mt-3"><label>Inspection notes</label><input class="form-control" type="text" name="note" maxlength="200" placeholder="What did you find?"></div>
    <label class="check"><input type="checkbox" name="give_credit" id="iCredit" checked> Credit the customer for this item</label>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Record result</button></div></form></div></div>
<script>
const DISP = { restock: ['Restock — approved as saleable', 'Goes back into available stock.'], repair: ['Send for repair', 'Stays in quarantine under repair (serial items).'], replace: ['Replace — defective unit kept as damaged', 'No credit; issue a replacement order.'], return_supplier: ['Send back to supplier', 'Leaves stock; reduces what you owe the supplier.'], dispose: ['Dispose — write off', 'Removed and the cost is written off.'] };
function inspect(l) {
  document.getElementById('iLine').value = l.id; document.getElementById('iName').textContent = l.name + (l.sn ? ' · ' + l.sn : '');
  const keys = l.serial ? ['restock', 'repair', 'replace', 'return_supplier', 'dispose'] : ['restock', 'return_supplier', 'dispose'];
  document.getElementById('iOptions').innerHTML = keys.map((k, i) => '<label class="option-card"><input type="radio" name="disposition" value="' + k + '"' + (i === 0 ? ' checked' : '') + '><span class="oc-body"><span class="oc-title">' + DISP[k][0] + '</span><span class="oc-desc">' + DISP[k][1] + '</span></span></label>').join('');
  document.getElementById('inspectModal').hidden = false;
}
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
