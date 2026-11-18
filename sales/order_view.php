<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('sales.view');

$pdo = db();
$id = input_int($_GET, 'id');
$s = $pdo->prepare('SELECT so.*, c.business_name, c.code AS cust_code, c.payment_terms_days, w.name AS wh_name, u.full_name AS rep, ab.full_name AS approver, cu.full_name AS creator FROM sales_orders so JOIN customers c ON c.id = so.customer_id JOIN warehouses w ON w.id = so.warehouse_id
    LEFT JOIN users u ON u.id = so.rep_id LEFT JOIN users ab ON ab.id = so.approved_by LEFT JOIN users cu ON cu.id = so.created_by WHERE so.id = :id');
$s->execute([':id' => $id]); $so = $s->fetch();
if (!$so || (!can('sales.view_all') && (int) $so['rep_id'] !== (int) current_user()['id'])) { flash('error', 'Order not found.'); redirect('/sales/orders.php'); }

if (is_post()) {
    verify_csrf();
    $a = input($_POST, 'action');
    try {
        if ($a === 'submit' && can('sales.create')) { $st = so_submit($id); flash($st === 'confirmed' ? 'success' : 'info', $st === 'confirmed' ? 'Order confirmed — stock is reserved.' : 'Sent to the Sales Manager for approval.'); }
        elseif ($a === 'approve' && can('sales.approve')) { so_decide($id, true, input($_POST, 'note')); flash('success', 'Order approved — stock is reserved.'); }
        elseif ($a === 'reject' && can('sales.approve')) { so_decide($id, false, input($_POST, 'note')); flash('success', 'Order rejected and returned to the sales rep.'); }
        elseif ($a === 'cancel' && can('sales.cancel')) { so_cancel($id, input($_POST, 'reason')); flash('success', 'Order cancelled. Reserved stock is released.'); }
        elseif ($a === 'close_balance' && can('sales.cancel')) { so_close_balance($id, input($_POST, 'reason') ?: 'Balance cancelled'); flash('success', 'The undelivered balance was closed.'); }
        elseif ($a === 'create_delivery' && can_any(['delivery.manage', 'delivery.fulfil'])) { $dn = delivery_create($id, array_map('intval', $_POST['qty'] ?? []), input_date($_POST, 'planned_date')); flash('success', 'Delivery note created. The warehouse can now pick it.'); redirect('/sales/delivery_view.php?id=' . $dn); }
        elseif ($a === 'delete_draft' && can('sales.create') && in_array($so['status'], ['draft', 'rejected'], true)) { $pdo->prepare('DELETE FROM sales_orders WHERE id = :id')->execute([':id' => $id]); log_activity('so_cancel', $so['so_no'] . ' draft deleted', 'sales_order', $id); flash('success', 'Draft deleted.'); redirect('/sales/orders.php'); }
    } catch (RuntimeException $e) { flash('error', $e->getMessage()); }
    redirect('/sales/order_view.php?id=' . $id);
}

$lines = so_line_balances($id);
foreach ($lines as &$l) { $l['avail'] = stock_summary((int) $l['product_id'], (int) $so['warehouse_id'])['available']; }
unset($l);
$dels = $pdo->query("SELECT d.*, dr.name AS driver, (SELECT COALESCE(SUM(qty_picked),0) FROM delivery_items WHERE delivery_id = d.id) AS units FROM deliveries d LEFT JOIN drivers dr ON dr.id = d.driver_id WHERE d.so_id = $id ORDER BY d.id")->fetchAll();
$invs = $pdo->query("SELECT * FROM sales_invoices WHERE so_id = $id ORDER BY id")->fetchAll();
$credit = credit_check((int) $so['customer_id'], 0.0, 'credit', $id);
$log = $pdo->query("SELECT al.*, u.full_name FROM activity_log al LEFT JOIN users u ON u.id = al.user_id WHERE al.entity_type = 'sales_order' AND al.entity_id = $id ORDER BY al.id DESC LIMIT 12")->fetchAll();

// Workflow position
$stepKeys = ['created' => 'Order created', 'approved' => 'Confirmed', 'picking' => 'Picking & dispatch', 'delivered' => 'Delivered', 'invoiced' => 'Invoiced', 'paid' => 'Paid'];
$delivered = array_filter($dels, fn($d) => $d['status'] === 'delivered');
$invTotal = array_sum(array_map(fn($i) => $i['status'] === 'void' ? 0 : (float) $i['total'], $invs));
$paidAll = $invs && array_reduce($invs, fn($c, $i) => $c && in_array($i['status'], ['paid', 'void'], true), true);
$cur = 'created';
if (in_array($so['status'], ['confirmed', 'partially_fulfilled'], true)) $cur = $dels ? 'picking' : 'approved';
elseif ($so['status'] === 'dispatched') $cur = 'picking';
elseif ($so['status'] === 'completed') $cur = $invs ? ($paidAll ? 'paid' : 'invoiced') : 'delivered';
$cancelled = in_array($so['status'], ['cancelled', 'rejected'], true);

$remaining = array_sum(array_column($lines, 'to_plan'));
$openDel = array_values(array_filter($dels, fn($d) => in_array($d['status'], ['pending_pick', 'picked', 'packed'], true)));
$uninvoiced = array_values(array_filter($dels, fn($d) => $d['status'] === 'delivered' && (int) $pdo->query("SELECT COUNT(*) FROM delivery_items WHERE delivery_id = {$d['id']} AND qty_accepted > qty_invoiced")->fetchColumn() > 0));

$pageTitle = $so['so_no'];
require __DIR__ . '/../includes/header.php';
$actions = '';
if (in_array($so['status'], ['draft', 'rejected'], true) && can('sales.create')) $actions .= '<a class="btn btn-secondary" href="' . e(app_url('/sales/order_form.php?id=' . $id)) . '">' . icon('pencil', 16) . ' Edit</a>';
if (can('sales.cancel') && in_array($so['status'], ['confirmed', 'pending_approval'], true) && !array_sum(array_column($lines, 'qty_dispatched'))) $actions .= '<button class="btn btn-danger-outline" type="button" data-modal-open="#cancelModal">' . icon('x-circle', 16) . ' Cancel order</button>';
page_header($so['so_no'], '<a class="link" href="' . e(app_url('/sales/customer_view.php?id=' . $so['customer_id'])) . '">' . e($so['business_name']) . '</a> · ' . e(fmt_date($so['order_date'])) . ' · ' . status_badge($so['status']), $actions, [['Sales orders', '/sales/orders.php'], [$so['so_no'], null]]);
if (!$cancelled) echo workflow_steps($stepKeys, $cur);

// Contextual next step
switch ($so['status']) {
    case 'draft': echo next_step('send', 'Draft order', 'Review the lines, then confirm. Within the customer’s credit it is confirmed instantly and stock is reserved.', can('sales.create') ? '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="submit"><button class="btn btn-primary" type="submit">' . icon('check', 16) . ' Confirm order</button></form><form method="post" class="inline-form" data-confirm="Delete this draft?">' . csrf_field() . '<input type="hidden" name="action" value="delete_draft"><button class="btn btn-secondary" type="submit">Delete</button></form>' : '', 'info'); break;
    case 'pending_approval':
        echo next_step('shield-alert', 'Waiting for the Sales Manager', 'Reasons: <strong>' . e($so['approval_reason'] ?? '') . '</strong>', can('sales.approve') ? '<button class="btn btn-primary" type="button" data-modal-open="#approveModal">' . icon('check', 16) . ' Review &amp; decide</button>' : '', 'warn'); break;
    case 'rejected': echo next_step('x-circle', 'Order rejected', e($so['decision_note'] ?? ''), can('sales.create') ? '<a class="btn btn-primary" href="' . e(app_url('/sales/order_form.php?id=' . $id)) . '">Edit &amp; resubmit</a>' : '', 'bad'); break;
    case 'confirmed': case 'partially_fulfilled':
        if ($openDel) echo next_step('package-check', 'Delivery in progress', 'Delivery note <strong>' . e($openDel[0]['dn_no']) . '</strong> is ' . str_replace('_', ' ', $openDel[0]['status']) . '.', '<a class="btn btn-primary" href="' . e(app_url('/sales/delivery_view.php?id=' . $openDel[0]['id'])) . '">Open delivery</a>', 'info');
        elseif ($remaining > 0 && can_any(['delivery.manage', 'delivery.fulfil'])) echo next_step('truck', $so['status'] === 'confirmed' ? 'Ready for fulfilment' : 'Balance still to deliver', 'Stock is reserved. Create a delivery note so the warehouse can pick, pack and dispatch.', '<button class="btn btn-primary" type="button" data-modal-open="#deliveryModal">' . icon('plus', 16) . ' Create delivery note</button>' . (can('sales.cancel') && $so['status'] === 'partially_fulfilled' ? '<button class="btn btn-secondary" type="button" data-modal-open="#closeModal">Close balance</button>' : ''), 'good');
        else echo next_step('clock', 'Waiting for the warehouse', 'The warehouse will create the delivery note, pick and dispatch the goods.', '', 'info');
        break;
    case 'dispatched': echo next_step('truck', 'Goods are on the road', 'Confirm the delivery when the customer signs for the goods — record any shortage or damage.', '<a class="btn btn-primary" href="' . e(app_url('/sales/delivery_view.php?id=' . (end($dels)['id'] ?? 0))) . '">Confirm delivery</a>', 'info'); break;
    case 'completed':
        if ($uninvoiced && can('invoices.create')) echo next_step('receipt', 'Delivered — ready to invoice', 'Invoice what the customer actually accepted.', '<a class="btn btn-primary" href="' . e(app_url('/sales/invoice_create.php?delivery_id=' . $uninvoiced[0]['id'])) . '">Create invoice</a>', 'good');
        elseif ($invs && !$paidAll) echo next_step('hand-coins', 'Invoiced — awaiting payment', 'Record the customer’s payment when it arrives.', can('payments.receive') ? '<a class="btn btn-primary" href="' . e(app_url('/sales/payment_form.php?customer_id=' . $so['customer_id'])) . '">Record payment</a>' : '', 'info');
        else echo next_step('check-circle-2', 'Order complete', 'Delivered, invoiced and paid.', '', 'good');
        break;
    case 'cancelled': echo next_step('x-circle', 'Order cancelled', e($so['cancel_reason'] ?? ''), '', 'bad'); break;
}
?>
<div class="layout-2-1 mb-4">
  <div class="card flush">
    <div class="card-head"><h3>Items</h3><span class="text-muted text-sm"><?= e($so['wh_name']) ?> · <?= $so['payment_type'] === 'cash' ? 'Cash on delivery' : 'Credit — ' . (int) $so['payment_terms_days'] . ' days' ?></span></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Product</th><th class="num">Ordered</th><th class="num">Dispatched</th><th class="num">Remaining</th><th class="num">In stock</th><th class="num">Unit price</th><th class="num">Total</th></tr></thead>
      <tbody>
      <?php foreach ($lines as $l): $short = in_array($so['status'], ['confirmed', 'partially_fulfilled'], true) && $l['remaining'] > 0 && $l['avail'] + $l['remaining'] < $l['remaining'] + 0 && false; ?>
        <tr>
          <td><a class="strong" href="<?= e(app_url('/catalog/product_view.php?id=' . $l['product_id'])) ?>"><?= e($l['name']) ?></a><div class="cell-sub mono"><?= e($l['code']) ?> <?= $l['tracking'] !== 'none' ? '<span class="tag ' . $l['tracking'] . '">' . e($l['tracking']) . '</span>' : '' ?></div></td>
          <td class="num"><?= fmt_pack_qty((int) $l['qty'], (int) $l['pack_factor'], $l['pack_name'], $l['base_unit']) ?></td>
          <td class="num"><?= (int) $l['qty_dispatched'] ? fmt_qty($l['qty_dispatched']) : '—' ?><?= (int) $l['qty_cancelled'] ? '<div class="cell-sub text-error">' . fmt_qty($l['qty_cancelled']) . ' cancelled</div>' : '' ?></td>
          <td class="num strong"><?= $l['remaining'] > 0 ? fmt_qty($l['remaining']) : '—' ?></td>
          <td class="num <?= $l['avail'] <= 0 ? 'stock-out' : ($l['avail'] < 0 ? 'stock-low' : 'text-secondary') ?>"><?= in_array($so['status'], ['confirmed', 'partially_fulfilled', 'pending_approval', 'draft'], true) ? fmt_qty($l['avail']) : '—' ?></td>
          <td class="num"><?= format_money($l['unit_price'] * $l['pack_factor']) ?><div class="cell-sub">per <?= e($l['pack_factor'] > 1 ? $l['pack_name'] : $l['base_unit']) ?><?= (float) $l['discount_pct'] > 0 ? ' · ' . rtrim(rtrim(number_format($l['discount_pct'], 2), '0'), '.') . '% off' : '' ?></div></td>
          <td class="num strong"><?= format_money($l['line_total']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <?php if ((float) $so['discount_total'] > 0): ?><tr><td colspan="6" class="text-right">Discounts included</td><td class="num">− <?= format_money($so['discount_total']) ?></td></tr><?php endif; ?>
        <tr><td colspan="6" class="text-right">Subtotal</td><td class="num"><?= format_money($so['subtotal']) ?></td></tr>
        <tr><td colspan="6" class="text-right"><?= e(setting('tax_name', 'VAT')) ?></td><td class="num"><?= format_money($so['tax_total']) ?></td></tr>
        <tr><td colspan="6" class="text-right">Order total</td><td class="num"><?= format_money($so['total']) ?></td></tr>
      </tfoot>
    </table></div>
  </div>
  <div class="grid">
    <div class="card">
      <h3 class="mb-3">Order details</h3>
      <dl class="desc-list">
        <dt>Customer</dt><dd><?= e($so['business_name']) ?></dd>
        <dt>Sales rep</dt><dd><?= e($so['rep'] ?? '—') ?></dd>
        <dt>Needed by</dt><dd><?= e(fmt_date($so['required_date'])) ?></dd>
        <?php if ($so['customer_po_ref']): ?><dt>Customer PO</dt><dd><?= e($so['customer_po_ref']) ?></dd><?php endif; ?>
        <dt>Created by</dt><dd><?= e($so['creator'] ?? '—') ?></dd>
        <?php if ($so['approved_by']): ?><dt>Approved by</dt><dd><?= e($so['approver']) ?><div class="cell-sub"><?= e(fmt_datetime($so['approved_at'])) ?></div></dd><?php endif; ?>
      </dl>
      <?php if ($so['notes']): ?><div class="callout mt-3"><?= e($so['notes']) ?></div><?php endif; ?>
      <?php if ($so['approval_reason'] && $so['status'] !== 'pending_approval'): ?><div class="callout warn mt-3"><strong>Approved exception:</strong> <?= e($so['approval_reason']) ?><?= $so['decision_note'] ? '<br>“' . e($so['decision_note']) . '”' : '' ?></div><?php endif; ?>
    </div>
    <?php if ($so['payment_type'] === 'credit'): ?>
    <div class="credit-box">
      <div class="text-sm fw-700 mb-2">Customer credit</div>
      <div class="cb-row"><span>Limit</span><strong><?= $credit['limit'] > 0 ? format_money($credit['limit']) : 'None' ?></strong></div>
      <div class="cb-row"><span>Outstanding</span><strong><?= format_money($credit['outstanding']) ?></strong></div>
      <div class="cb-row"><span>Overdue</span><strong class="<?= $credit['overdue'] > 0 ? 'text-error' : '' ?>"><?= format_money($credit['overdue']) ?></strong></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-2 mb-4">
  <div class="card flush">
    <div class="card-head"><h3>Deliveries</h3></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Delivery note</th><th>Driver</th><th class="num">Units</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($dels as $d): ?><tr><td class="strong"><a href="<?= e(app_url('/sales/delivery_view.php?id=' . $d['id'])) ?>"><?= e($d['dn_no']) ?></a><div class="cell-sub"><?= e(fmt_date($d['planned_date'])) ?></div></td><td class="text-secondary"><?= e($d['driver'] ?? '—') ?></td><td class="num"><?= fmt_qty($d['units']) ?></td><td><?= status_badge($d['status']) ?><?= $d['has_discrepancy'] ? ' <span class="badge badge-warning">Shortage</span>' : '' ?></td></tr><?php endforeach; ?></tbody>
    </table><?php if (!$dels) echo empty_state('truck', 'No deliveries yet', 'A delivery note is created once the order is confirmed.'); ?></div>
  </div>
  <div class="card flush">
    <div class="card-head"><h3>Invoices</h3></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Invoice</th><th>Date</th><th class="num">Total</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($invs as $i): ?><tr><td class="strong"><a href="<?= e(app_url('/sales/invoice_view.php?id=' . $i['id'])) ?>"><?= e($i['inv_no']) ?></a></td><td class="text-secondary nowrap"><?= e(fmt_date($i['invoice_date'])) ?></td><td class="num"><?= format_money($i['total']) ?></td><td><?= status_badge($i['status']) ?></td></tr><?php endforeach; ?></tbody>
    </table><?php if (!$invs) echo empty_state('receipt', 'Not invoiced yet', 'Invoices are created from delivered goods.'); ?></div>
  </div>
</div>

<div class="card flush"><div class="card-head"><h3>Activity</h3><a class="btn-ghost btn-sm" href="<?= e(app_url('/audit.php?entity=sales_order&entity_id=' . $id)) ?>">Full audit trail →</a></div>
  <ul class="timeline" style="padding:0 20px 8px;"><?php foreach ($log as $a): ?><li><span class="t-dot"></span><div><div class="strong"><?= e($a['details']) ?></div><div class="text-muted text-sm"><?= e($a['full_name'] ?? 'System') ?> · <?= e(fmt_datetime($a['created_at'])) ?></div></div></li><?php endforeach; ?></ul>
  <?php if (!$log) echo empty_state('history', 'No activity recorded'); ?></div>

<?php if (can_any(['delivery.manage', 'delivery.fulfil']) && $remaining > 0 && in_array($so['status'], ['confirmed', 'partially_fulfilled'], true)): ?>
<div class="modal-overlay" id="deliveryModal" hidden><div class="modal lg">
  <div class="modal-header"><h3>Create delivery note</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create_delivery">
    <p class="text-secondary text-sm mb-3">Choose how much of each line goes on this delivery. Leave the rest for a later delivery (partial fulfilment).</p>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th class="num">Remaining</th><th class="num">In stock</th><th style="width:160px;">Quantity to send (<?= '' ?>base units)</th></tr></thead><tbody>
    <?php foreach ($lines as $l): if ($l['to_plan'] <= 0) continue; $def = min($l['to_plan'], max(0, $l['avail'] + $l['remaining'])); ?>
      <tr><td class="strong"><?= e($l['name']) ?></td><td class="num"><?= fmt_qty($l['to_plan']) ?> <?= e($l['base_unit']) ?></td><td class="num <?= $l['avail'] < $l['to_plan'] ? 'stock-low' : 'stock-ok' ?>"><?= fmt_qty(max(0, $l['avail'] + $l['remaining'])) ?></td>
        <td><input class="form-control num-input" type="number" min="0" max="<?= (int) $l['to_plan'] ?>" name="qty[<?= (int) $l['id'] ?>]" value="<?= (int) $l['to_plan'] ?>"></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <div class="form-group mt-3"><label>Planned delivery date</label><input class="form-control" type="date" name="planned_date" value="<?= e($so['required_date'] ?: date('Y-m-d')) ?>" style="max-width:220px;"></div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Create delivery note</button></div>
  </form></div></div>
<?php endif; ?>
<?php if (can('sales.approve') && $so['status'] === 'pending_approval'): ?>
<div class="modal-overlay" id="approveModal" hidden><div class="modal">
  <div class="modal-header"><h3>Approve exception — <?= e($so['so_no']) ?></h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <div class="callout warn mb-3"><strong>Why it needs approval</strong><br><?= e($so['approval_reason']) ?><div class="mt-2">Order total <strong><?= format_money($so['total']) ?></strong> · outstanding <?= format_money($credit['outstanding']) ?> · limit <?= $credit['limit'] > 0 ? format_money($credit['limit']) : 'none' ?></div></div>
  <form method="post"><?= csrf_field() ?><div class="form-group"><label>Note <span class="label-opt">(required when rejecting)</span></label><input class="form-control" type="text" name="note" maxlength="255" placeholder="e.g. Approved — customer paid last month’s invoices"></div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-danger-outline" type="submit" name="action" value="reject" data-no-busy>Reject</button><button class="btn btn-primary" type="submit" name="action" value="approve" data-no-busy><?= icon('check', 16) ?> Approve exception</button></div></form></div></div>
<?php endif; ?>
<?php if (can('sales.cancel')): ?>
<div class="modal-overlay" id="cancelModal" hidden><div class="modal sm"><div class="modal-header"><h3>Cancel order</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><div class="form-group"><label>Reason</label><input class="form-control" type="text" name="reason" required maxlength="255" placeholder="e.g. Customer cancelled"></div><p class="text-secondary text-sm">Reserved stock is released and any open delivery note is cancelled.</p>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Keep order</button><button class="btn btn-danger" type="submit">Cancel order</button></div></form></div></div>
<div class="modal-overlay" id="closeModal" hidden><div class="modal sm"><div class="modal-header"><h3>Close undelivered balance</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="close_balance"><div class="form-group"><label>Reason</label><input class="form-control" type="text" name="reason" required maxlength="255" placeholder="e.g. Out of stock — customer agreed"></div><p class="text-secondary text-sm">The delivered quantity stays; the rest of the order is cancelled and the order total is reduced.</p>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Close balance</button></div></form></div></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
