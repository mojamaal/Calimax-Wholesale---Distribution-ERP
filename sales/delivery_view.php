<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('delivery.view');

$pdo = db();
$id = input_int($_GET, 'id');
$s = $pdo->prepare('SELECT d.*, so.so_no, so.payment_type, so.id AS so_id, c.business_name, c.address, c.phone, c.contact_person, w.name AS wh_name, dr.name AS driver, v.reg_no, v.type AS vtype, pu.full_name AS picker, du.full_name AS dispatcher
    FROM deliveries d JOIN sales_orders so ON so.id = d.so_id JOIN customers c ON c.id = d.customer_id JOIN warehouses w ON w.id = d.warehouse_id LEFT JOIN drivers dr ON dr.id = d.driver_id LEFT JOIN vehicles v ON v.id = d.vehicle_id
    LEFT JOIN users pu ON pu.id = d.picked_by LEFT JOIN users du ON du.id = d.dispatched_by WHERE d.id = :id');
$s->execute([':id' => $id]); $d = $s->fetch();
if (!$d || (!can('sales.view_all') && !$pdo->query('SELECT 1 FROM sales_orders WHERE id = ' . (int) $d['so_id'] . ' AND rep_id = ' . (int) current_user()['id'])->fetchColumn())) { flash('error', 'Delivery not found.'); redirect('/sales/deliveries.php'); }

$items = $pdo->prepare('SELECT di.*, p.code, p.name, p.base_unit, p.tracking, p.track_expiry, soi.pack_name, soi.pack_factor, soi.unit_price FROM delivery_items di JOIN products p ON p.id = di.product_id JOIN sales_order_items soi ON soi.id = di.so_item_id WHERE di.delivery_id = :d ORDER BY di.id');
$items->execute([':d' => $id]); $items = $items->fetchAll();

if (is_post()) {
    verify_csrf();
    $a = input($_POST, 'action');
    try {
        if ($a === 'pick' && can('delivery.fulfil')) {
            $picks = [];
            foreach ($items as $it) {
                $pk = $_POST['pick'][$it['id']] ?? [];
                if ($it['tracking'] === 'serial') {
                    $ids = [];
                    foreach (preg_split('/\R/', (string) ($pk['serials'] ?? '')) as $sn) {
                        $sn = trim($sn); if ($sn === '') continue;
                        $x = $pdo->prepare('SELECT id FROM serial_numbers WHERE product_id = :p AND serial_no = :s'); $x->execute([':p' => $it['product_id'], ':s' => $sn]); $sid = $x->fetchColumn();
                        if (!$sid) throw new RuntimeException("Serial number $sn is not registered for {$it['name']}.");
                        $ids[] = (int) $sid;
                    }
                    $picks[$it['id']] = ['serials' => $ids];
                } elseif ($it['tracking'] === 'batch') $picks[$it['id']] = ['batches' => array_map('intval', array_filter($pk['batch'] ?? [], fn($q) => (int) $q > 0))];
                else $picks[$it['id']] = ['qty' => (int) ($pk['qty'] ?? 0)];
            }
            delivery_pick($id, $picks);
            flash('success', 'Picking confirmed. Pack the goods, then dispatch.');
        } elseif ($a === 'pack' && can('delivery.fulfil')) { delivery_pack($id); flash('success', 'Marked as packed.'); }
        elseif ($a === 'dispatch' && can('delivery.fulfil')) { delivery_dispatch($id, input_int($_POST, 'driver_id') ?: null, input_int($_POST, 'vehicle_id') ?: null); flash('success', 'Dispatched. Stock has left the warehouse.'); }
        elseif ($a === 'confirm' && can('delivery.confirm')) {
            $res = [];
            foreach ($items as $it) {
                $res[$it['id']] = ['rejected' => (int) ($_POST['rej'][$it['id']] ?? 0), 'reason' => trim((string) ($_POST['reason'][$it['id']] ?? '')), 'serial_rejects' => array_map('intval', $_POST['serial_rej'][$it['id']] ?? [])];
                if ($it['tracking'] === 'serial' && !$res[$it['id']]['reason'] && $res[$it['id']]['serial_rejects']) $res[$it['id']]['reason'] = trim((string) ($_POST['reason'][$it['id']] ?? ''));
            }
            delivery_confirm($id, $res, input($_POST, 'received_by'), input($_POST, 'pod_note'), input($_POST, 'balance') !== 'redeliver');
            flash('success', 'Delivery confirmed.' . ($d['payment_type'] ? ' You can now invoice the accepted goods.' : ''));
            if (can('invoices.create') && input($_POST, 'then') === 'invoice') redirect('/sales/invoice_create.php?delivery_id=' . $id);
        } elseif ($a === 'fail' && can('delivery.confirm')) { delivery_fail($id, input($_POST, 'reason')); flash('success', 'Delivery marked as failed. Goods are back in stock.'); }
        elseif ($a === 'cancel' && can_any(['delivery.manage', 'delivery.fulfil'])) { delivery_cancel($id); flash('success', 'Delivery note cancelled.'); redirect('/sales/order_view.php?id=' . $d['so_id']); }
    } catch (RuntimeException $e) { flash('error', $e->getMessage()); }
    redirect('/sales/delivery_view.php?id=' . $id);
}

$allocs = [];
foreach ($pdo->query("SELECT da.*, b.batch_no, b.expiry_date, sn.serial_no FROM delivery_allocations da LEFT JOIN batches b ON b.id = da.batch_id LEFT JOIN serial_numbers sn ON sn.id = da.serial_id WHERE da.delivery_item_id IN (SELECT id FROM delivery_items WHERE delivery_id = $id) ORDER BY da.id")->fetchAll() as $a) $allocs[$a['delivery_item_id']][] = $a;
$drivers = $pdo->query('SELECT id, name FROM drivers WHERE is_active = 1 ORDER BY name')->fetchAll();
$vehicles = $pdo->query('SELECT id, reg_no, type FROM vehicles WHERE is_active = 1 ORDER BY reg_no')->fetchAll();
$invoices = $pdo->query("SELECT * FROM sales_invoices WHERE delivery_id = $id AND status <> 'void'")->fetchAll();
$canPick = can('delivery.fulfil') && in_array($d['status'], ['pending_pick', 'picked'], true);
$policy = setting('allocation_policy', 'fefo');
$cur = ['pending_pick' => 'created', 'picked' => 'picked', 'packed' => 'packed', 'dispatched' => 'dispatched', 'delivered' => 'delivered', 'failed' => 'dispatched', 'cancelled' => 'created'][$d['status']];
$uninv = $d['status'] === 'delivered' && (int) $pdo->query("SELECT COUNT(*) FROM delivery_items WHERE delivery_id = $id AND qty_accepted > qty_invoiced")->fetchColumn() > 0;

// Pick-screen data per item
$pickData = [];
if ($canPick) {
    foreach ($items as $it) {
        $pid = (int) $it['product_id']; $wh = (int) $d['warehouse_id'];
        if ($it['tracking'] === 'batch') {
            $batches = saleable_batches($pid, $wh, $id);
            [$sugg] = suggest_batch_allocation($pid, $wh, (int) $it['qty_planned'], $id);
            $cur2 = []; foreach ($allocs[$it['id']] ?? [] as $a) $cur2[(int) $a['batch_id']] = (int) $a['qty'];
            $pickData[$it['id']] = ['batches' => $batches, 'sugg' => array_column($sugg, 'qty', 'batch_id'), 'cur' => $cur2];
        } elseif ($it['tracking'] === 'serial') {
            $av = available_serials($pid, $wh, 400, $id);
            $pickData[$it['id']] = ['serials' => $av, 'sugg' => array_column(array_slice($av, 0, (int) $it['qty_planned']), 'serial_no'), 'cur' => array_column($allocs[$it['id']] ?? [], 'serial_no')];
        } else {
            $on = (int) $pdo->query("SELECT COALESCE(on_hand,0) FROM stock WHERE product_id = $pid AND warehouse_id = $wh AND batch_id = 0")->fetchColumn();
            $other = (int) $pdo->query("SELECT COALESCE(SUM(da.qty),0) FROM delivery_allocations da JOIN delivery_items di ON di.id = da.delivery_item_id JOIN deliveries dd ON dd.id = di.delivery_id WHERE di.product_id = $pid AND da.batch_id = 0 AND dd.warehouse_id = $wh AND dd.status IN ('picked','packed') AND dd.id <> $id")->fetchColumn();
            $pickData[$it['id']] = ['avail' => $on - $other, 'cur' => (int) $it['qty_picked']];
        }
    }
}

$pageTitle = $d['dn_no'];
$pageScripts = [app_url('/assets/js/lines.js') . '?v=1'];
require __DIR__ . '/../includes/header.php';
$act = '<a class="btn btn-secondary" href="' . e(app_url('/sales/print.php?type=delivery&id=' . $id)) . '" target="_blank">' . icon('printer', 16) . ' Print delivery note</a>';
if (can_any(['delivery.manage', 'delivery.fulfil']) && in_array($d['status'], ['pending_pick', 'picked', 'packed'], true)) $act .= '<form method="post" class="inline-form" data-confirm="Cancel this delivery note? Picked serial numbers are released."><input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="cancel"><button class="btn btn-danger-outline" type="submit">Cancel delivery</button></form>';
page_header($d['dn_no'], '<a class="link" href="' . e(app_url('/sales/order_view.php?id=' . $d['so_id'])) . '">' . e($d['so_no']) . '</a> · ' . e($d['business_name']) . ' · ' . status_badge($d['status']) . ($d['has_discrepancy'] ? ' <span class="badge badge-warning">Shortage / rejected goods</span>' : ''), $act, [['Deliveries', '/sales/deliveries.php'], [$d['dn_no'], null]]);
echo workflow_steps(['created' => 'Created', 'picked' => 'Picked', 'packed' => 'Packed', 'dispatched' => 'Dispatched', 'delivered' => 'Delivered'], $cur, $d['status'] === 'failed');

switch ($d['status']) {
    case 'pending_pick': echo next_step('scan-barcode', 'Step 1 — Pick the goods', $policy === 'fefo' ? 'Batches are suggested First-Expired-First-Out. Scan or enter serial numbers for serial-tracked items.' : 'Batches are suggested First-In-First-Out.', '', 'good'); break;
    case 'picked': echo next_step('package', 'Picked — pack and dispatch', 'Check the packed quantities, then hand the goods to the driver.', can('delivery.fulfil') ? '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="pack"><button class="btn btn-secondary" type="submit">' . icon('package', 16) . ' Mark packed</button></form><button class="btn btn-primary" type="button" data-modal-open="#dispatchModal">' . icon('truck', 16) . ' Dispatch</button>' : '', 'info'); break;
    case 'packed': echo next_step('truck', 'Packed — ready to dispatch', 'Assign a driver and vehicle and send the goods out.', can('delivery.fulfil') ? '<button class="btn btn-primary" type="button" data-modal-open="#dispatchModal">' . icon('truck', 16) . ' Dispatch</button>' : '', 'info'); break;
    case 'dispatched': echo next_step('map-pin-check', 'On the way to the customer', 'Dispatched ' . e(fmt_datetime($d['dispatched_at'])) . ' with ' . e($d['driver'] ?? 'no driver') . ($d['reg_no'] ? ' (' . e($d['reg_no']) . ')' : '') . '. Confirm what the customer actually received.', can('delivery.confirm') ? '<button class="btn btn-primary" type="button" data-modal-open="#confirmModal">' . icon('check', 16) . ' Confirm delivery</button><button class="btn btn-secondary" type="button" data-modal-open="#failModal">Delivery failed</button>' : '', 'info'); break;
    case 'delivered':
        if ($uninv && can('invoices.create')) echo next_step('receipt', 'Delivered — invoice the accepted goods', 'Received by ' . e($d['received_by_name'] ?: 'customer') . ' on ' . e(fmt_datetime($d['delivered_at'])) . '.', '<a class="btn btn-primary" href="' . e(app_url('/sales/invoice_create.php?delivery_id=' . $id)) . '">' . icon('receipt', 16) . ' Create invoice</a>', 'good');
        else echo next_step('check-circle-2', 'Delivered', 'Received by ' . e($d['received_by_name'] ?: 'customer') . ' on ' . e(fmt_datetime($d['delivered_at'])) . '.' . ($invoices ? ' Invoiced as ' . implode(', ', array_map(fn($i) => '<a class="link" href="' . e(app_url('/sales/invoice_view.php?id=' . $i['id'])) . '">' . e($i['inv_no']) . '</a>', $invoices)) . '.' : ''), '', 'good');
        break;
    case 'failed': echo next_step('x-circle', 'Delivery failed', e($d['failure_reason'] ?? '') . ' — goods are back in stock. Create a new delivery note from the order to try again.', '<a class="btn btn-primary" href="' . e(app_url('/sales/order_view.php?id=' . $d['so_id'])) . '">Open order</a>', 'bad'); break;
    case 'cancelled': echo next_step('x-circle', 'Delivery cancelled', 'This delivery note was cancelled; the order quantities are available for a new delivery.', '', 'bad'); break;
}
?>
<div class="grid grid-3 mb-4">
  <div class="card"><div class="fact"><div class="fl">Deliver to</div><div class="fv"><?= e($d['business_name']) ?></div></div><div class="text-sm text-secondary mt-2"><?= e($d['address'] ?? '') ?><br><?= e($d['contact_person'] ?? '') ?> <?= e($d['phone'] ?? '') ?></div></div>
  <div class="card"><div class="facts"><div class="fact"><div class="fl">Warehouse</div><div class="fv"><?= e($d['wh_name']) ?></div></div><div class="fact"><div class="fl">Planned</div><div class="fv"><?= e(fmt_date($d['planned_date'])) ?></div></div><div class="fact"><div class="fl">Picked by</div><div class="fv"><?= e($d['picker'] ?? '—') ?></div></div><div class="fact"><div class="fl">Dispatched by</div><div class="fv"><?= e($d['dispatcher'] ?? '—') ?></div></div></div></div>
  <div class="card"><div class="facts"><div class="fact"><div class="fl">Driver</div><div class="fv"><?= e($d['driver'] ?? '—') ?></div></div><div class="fact"><div class="fl">Vehicle</div><div class="fv"><?= e($d['reg_no'] ?? '—') ?><?= $d['vtype'] ? ' · ' . e($d['vtype']) : '' ?></div></div><div class="fact"><div class="fl">Received by</div><div class="fv"><?= e($d['received_by_name'] ?? '—') ?></div></div><div class="fact"><div class="fl">Delivered</div><div class="fv"><?= $d['delivered_at'] ? e(fmt_datetime($d['delivered_at'])) : '—' ?></div></div></div></div>
</div>

<?php if ($canPick): ?>
<form method="post" id="pickForm"><?= csrf_field() ?><input type="hidden" name="action" value="pick">
  <h3 class="mb-3"><?= $d['status'] === 'picked' ? 'Re-pick goods' : 'Pick list' ?> <span class="text-muted text-sm" style="font-weight:500;">— <?= e($d['wh_name']) ?></span></h3>
  <?php foreach ($items as $it): $pd = $pickData[$it['id']]; $planned = (int) $it['qty_planned']; ?>
  <div class="item-block" data-planned="<?= $planned ?>" data-item="<?= (int) $it['id'] ?>" data-product="<?= (int) $it['product_id'] ?>">
    <div class="ib-head"><div class="ib-title"><?= e($it['name']) ?> <span class="text-muted mono"><?= e($it['code']) ?></span> <?= $it['tracking'] !== 'none' ? '<span class="tag ' . $it['tracking'] . '">' . e($it['tracking']) . '</span>' : '' ?></div>
      <div class="text-sm">Ordered <strong><?= fmt_pack_qty($planned, (int) $it['pack_factor'], $it['pack_name'], $it['base_unit']) ?></strong></div><div class="text-sm fw-700 pick-count">0 / <?= fmt_qty($planned) ?> picked</div></div>
    <div class="ib-body">
    <?php if ($it['tracking'] === 'batch'): ?>
      <div class="flex justify-between items-center mb-2"><span class="text-sm text-secondary"><?= $policy === 'fefo' && $it['track_expiry'] ? 'Suggested order: earliest expiry first (FEFO).' : 'Suggested order: oldest received first (FIFO).' ?></span><button class="btn btn-secondary btn-sm" type="button" data-auto="<?= (int) $it['id'] ?>"><?= icon('wand-2', 14) ?> Auto-allocate</button></div>
      <?php foreach ($pd['batches'] as $i => $b): $sg = $pd['sugg'][(int) $b['id']] ?? 0; ?>
        <div class="alloc-row"><div><span class="strong mono"><?= e($b['batch_no']) ?></span> <?= $i === 0 ? '<span class="badge badge-success">Suggested first</span>' : '' ?><div class="cell-sub"><?= $b['expiry_date'] ? 'Expires ' . e(fmt_date($b['expiry_date'])) : 'No expiry' ?> · received <?= e(fmt_date($b['received_date'])) ?></div></div>
          <div class="text-sm text-secondary"><?= $b['expiry_date'] ? expiry_badge($b['expiry_date']) : '' ?></div><div class="num text-sm">Available <strong><?= fmt_qty($b['available']) ?></strong></div>
          <input class="form-control num-input pick-qty" type="number" min="0" max="<?= (int) $b['available'] ?>" name="pick[<?= (int) $it['id'] ?>][batch][<?= (int) $b['id'] ?>]" value="<?= (int) ($pd['cur'] ? ($pd['cur'][(int) $b['id']] ?? 0) : $sg) ?>" data-sugg="<?= (int) $sg ?>" data-avail="<?= (int) $b['available'] ?>"><span></span></div>
      <?php endforeach; ?>
      <?php if (!$pd['batches']): ?><div class="callout bad">No saleable stock of this product in <?= e($d['wh_name']) ?> (expired, quarantined or recalled batches are excluded).</div><?php endif; ?>
    <?php elseif ($it['tracking'] === 'serial'): ?>
      <div data-sn data-expected="<?= $planned ?>">
        <div class="scan-box"><input class="form-control" type="text" data-sn-input placeholder="Scan or type a serial number and press Enter" autocomplete="off"><button class="btn btn-secondary" type="button" data-sn-add>Add</button><button class="btn btn-secondary" type="button" data-sugg='<?= e(js_json($pd['sugg'])) ?>'>Use suggested</button></div>
        <div class="scan-hint">Each serial is checked: right product, in this warehouse, not allocated to another order. <span data-sn-count class="fw-700"></span></div>
        <div class="chip-list mt-2" data-sn-list></div>
        <textarea hidden data-sn-value name="pick[<?= (int) $it['id'] ?>][serials]"><?= e(implode("\n", $pd['cur'] ?: $pd['sugg'])) ?></textarea>
      </div>
    <?php else: ?>
      <div class="flex items-center gap-3"><label class="mb-0">Quantity picked</label><input class="form-control num-input pick-qty" style="max-width:160px;" type="number" min="0" max="<?= max(0, (int) $pd['avail']) ?>" name="pick[<?= (int) $it['id'] ?>][qty]" value="<?= (int) $pd['cur'] ?: min($planned, max(0, (int) $pd['avail'])) ?>"><span class="text-sm text-secondary">Available <strong><?= fmt_qty($pd['avail']) ?></strong> <?= e($it['base_unit']) ?></span></div>
    <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="action-bar"><span class="text-muted text-sm">If you pick less than ordered, the shortage stays open on the sales order for a later delivery.</span><button class="btn btn-primary btn-lg" type="submit"><?= icon('check', 16) ?> Confirm picking</button></div>
</form>
<?php endif; ?>

<?php if (!$canPick): ?>
<div class="card flush mb-4">
  <div class="card-head"><h3>Goods on this delivery</h3></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Product</th><th>Batch / serial numbers</th><th class="num">Qty</th><?php if (in_array($d['status'], ['delivered', 'failed'], true)): ?><th class="num">Accepted</th><th class="num">Rejected</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($items as $it): $al = $allocs[$it['id']] ?? []; ?>
      <tr>
        <td><span class="strong"><?= e($it['name']) ?></span><div class="cell-sub mono"><?= e($it['code']) ?></div></td>
        <td><?php if ($it['tracking'] === 'serial'): ?><div class="chip-list"><?php foreach ($al as $a): ?><a class="sn-chip <?= $a['rejected_qty'] ? 'dup' : '' ?>" href="<?= e(app_url('/trace/serial_view.php?id=' . $a['serial_id'])) ?>" style="padding:3px 10px;"><?= e($a['serial_no']) ?></a><?php endforeach; ?></div>
          <?php elseif ($it['tracking'] === 'batch'): foreach ($al as $a): ?><div class="text-sm"><a class="link mono" href="<?= e(app_url('/trace/batch_view.php?id=' . $a['batch_id'])) ?>"><?= e($a['batch_no']) ?></a> × <?= fmt_qty($a['qty']) ?><?= $a['expiry_date'] ? ' <span class="text-muted">· exp ' . e(fmt_date($a['expiry_date'])) . '</span>' : '' ?></div><?php endforeach; else: ?><span class="text-muted">—</span><?php endif; ?></td>
        <td class="num strong"><?= fmt_pack_qty((int) $it['qty_picked'] ?: (int) $it['qty_planned'], (int) $it['pack_factor'], $it['pack_name'], $it['base_unit']) ?></td>
        <?php if (in_array($d['status'], ['delivered', 'failed'], true)): ?><td class="num text-success strong"><?= $d['status'] === 'failed' ? 0 : fmt_qty($it['qty_accepted']) ?></td><td class="num <?= $it['qty_rejected'] ? 'text-error strong' : 'text-muted' ?>"><?= $it['qty_rejected'] ? fmt_qty($it['qty_rejected']) . '<div class="cell-sub">' . e($it['reject_reason']) . '</div>' : '—' ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php if ($d['pod_note']): ?><div class="callout mb-4"><strong>Proof of delivery:</strong> <?= e($d['pod_note']) ?></div><?php endif; ?>
<?php endif; ?>

<?php if (can('delivery.fulfil') && in_array($d['status'], ['picked', 'packed'], true)): ?>
<div class="modal-overlay" id="dispatchModal" hidden><div class="modal sm">
  <div class="modal-header"><h3>Dispatch <?= e($d['dn_no']) ?></h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="dispatch">
    <div class="form-group"><label>Driver</label><select class="form-control" name="driver_id"><option value="">— Customer collects —</option><?php foreach ($drivers as $dr): ?><option value="<?= (int) $dr['id'] ?>"><?= e($dr['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Vehicle</label><select class="form-control" name="vehicle_id"><option value="">—</option><?php foreach ($vehicles as $v): ?><option value="<?= (int) $v['id'] ?>"><?= e($v['reg_no']) ?><?= $v['type'] ? ' · ' . e($v['type']) : '' ?></option><?php endforeach; ?></select></div>
    <p class="text-secondary text-sm">Dispatching reduces warehouse stock and records the cost of goods sold. It cannot be undone — if delivery fails, mark it as failed to return the goods.</p>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit"><?= icon('truck', 16) ?> Dispatch</button></div>
  </form></div></div>
<?php endif; ?>
<?php if (can('delivery.confirm') && $d['status'] === 'dispatched'): ?>
<div class="modal-overlay" id="confirmModal" hidden><div class="modal lg">
  <div class="modal-header"><h3>Confirm delivery — <?= e($d['business_name']) ?></h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="confirm">
    <p class="text-secondary text-sm mb-3">Record what the customer actually accepted. Anything rejected or short goes back to the warehouse quarantine for inspection.</p>
    <?php foreach ($items as $it): $al = $allocs[$it['id']] ?? []; ?>
      <div class="item-block"><div class="ib-head"><div class="ib-title"><?= e($it['name']) ?></div><div class="text-sm">Delivered <strong><?= fmt_qty($it['qty_picked']) ?></strong> <?= e($it['base_unit']) ?></div></div>
        <div class="ib-body">
        <?php if ($it['tracking'] === 'serial'): ?>
          <div class="text-sm text-secondary mb-2">Tick any unit the customer rejected:</div>
          <div class="chip-list"><?php foreach ($al as $a): ?><label class="sn-chip" style="cursor:pointer;"><input type="checkbox" name="serial_rej[<?= (int) $it['id'] ?>][]" value="<?= (int) $a['serial_id'] ?>"> <?= e($a['serial_no']) ?></label><?php endforeach; ?></div>
          <div class="form-group mt-3 mb-0"><label>Reason if rejected</label><input class="form-control" type="text" name="reason[<?= (int) $it['id'] ?>]" maxlength="150" placeholder="e.g. Screen cracked"></div>
        <?php else: ?>
          <div class="form-row"><div class="form-group mb-0"><label>Rejected / damaged qty</label><input class="form-control num-input" type="number" min="0" max="<?= (int) $it['qty_picked'] ?>" name="rej[<?= (int) $it['id'] ?>]" value="0"></div>
            <div class="form-group mb-0" style="flex:2"><label>Reason <span class="label-opt">(required if rejected)</span></label><input class="form-control" type="text" name="reason[<?= (int) $it['id'] ?>]" maxlength="150" placeholder="e.g. One carton damaged"></div></div>
        <?php endif; ?>
        </div></div>
    <?php endforeach; ?>
    <div class="form-row"><div class="form-group"><label>Received by (name)</label><input class="form-control" type="text" name="received_by" maxlength="100" placeholder="Name of the person who signed"></div><div class="form-group"><label>Proof of delivery note <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="pod_note" maxlength="255" placeholder="Signed delivery note, photo ref…"></div></div>
    <div class="form-group"><label>If goods were rejected</label><div class="segmented"><label><input type="radio" name="balance" value="cancel" checked><span>Cancel the shortage</span></label><label><input type="radio" name="balance" value="redeliver"><span>Re-deliver later</span></label></div></div>
    <?php if (can('invoices.create')): ?><label class="check"><input type="checkbox" name="then" value="invoice" checked> Create the invoice next</label><?php endif; ?>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit"><?= icon('check', 16) ?> Confirm delivery</button></div>
  </form></div></div>
<div class="modal-overlay" id="failModal" hidden><div class="modal sm"><div class="modal-header"><h3>Delivery failed</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="fail"><div class="form-group"><label>What happened?</label><input class="form-control" type="text" name="reason" required maxlength="255" placeholder="e.g. Shop closed, customer refused"></div>
    <p class="text-secondary text-sm">All goods return to saleable stock and the order quantity reopens for a new delivery.</p>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-danger" type="submit">Mark failed</button></div></form></div></div>
<?php endif; ?>

<?php if ($canPick): ?>
<script>
(function () {
  // Live "picked / planned" counters per item block
  document.querySelectorAll('.item-block[data-item]').forEach((blk) => {
    const planned = +blk.dataset.planned, count = blk.querySelector('.pick-count'), sn = blk.querySelector('[data-sn]');
    let scanner = null;
    function total() { if (scanner) return scanner.get().length; return [...blk.querySelectorAll('.pick-qty')].reduce((s, i) => s + (+i.value || 0), 0); }
    function refresh() { const t = total(); count.textContent = t.toLocaleString() + ' / ' + planned.toLocaleString() + ' picked'; count.className = 'text-sm fw-700 pick-count ' + (t === planned ? 'text-success' : (t > planned ? 'text-error' : 'text-warning')); blk.classList.toggle('done', t === planned); }
    if (sn) {
      scanner = CalimaxLines.SerialScanner(sn, { expected: planned, onChange: refresh, validate: (s) => fetch(APP.url + 'api/serial_check.php?serial=' + encodeURIComponent(s) + '&product_id=' + blk.dataset.product + '&warehouse_id=<?= (int) $d['warehouse_id'] ?>&delivery_id=<?= (int) $id ?>').then((r) => r.json()) });
      const sg = sn.querySelector('[data-sugg]'); if (sg) sg.addEventListener('click', () => scanner.set(JSON.parse(sg.dataset.sugg)));
    }
    blk.addEventListener('input', refresh);
    const auto = blk.querySelector('[data-auto]');
    if (auto) auto.addEventListener('click', () => { blk.querySelectorAll('.pick-qty').forEach((i) => { i.value = i.dataset.sugg || 0; }); refresh(); });
    if (!sn && !auto && !blk.querySelector('.pick-qty').value) blk.querySelector('.pick-qty').value = planned;
    refresh();
  });
  document.getElementById('pickForm').addEventListener('submit', (e) => {
    let any = false; document.querySelectorAll('.item-block[data-item] .pick-count').forEach((c) => { if (parseInt(c.textContent, 10) > 0) any = true; });
    if (!any) { e.preventDefault(); CalimaxUI.toast('Pick at least one item.', 'error'); }
  });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
