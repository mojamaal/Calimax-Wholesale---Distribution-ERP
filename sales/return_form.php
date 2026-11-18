<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('returns.create');

$pdo = db();
$invId = input_int($_GET, 'invoice_id');
$q = input($_GET, 'q');
$inv = null;
if ($invId) {
    $s = $pdo->prepare("SELECT si.*, c.business_name FROM sales_invoices si JOIN customers c ON c.id = si.customer_id WHERE si.id = :id AND si.status <> 'void'"); $s->execute([':id' => $invId]); $inv = $s->fetch();
    if (!$inv) { flash('error', 'Invoice not found.'); redirect('/sales/returns.php'); }
}
$errors = [];
if ($inv && is_post()) {
    verify_csrf();
    $lines = [];
    foreach ($_POST['qty'] ?? [] as $itemId => $qty) {
        if ((int) $qty > 0) $lines[] = ['invoice_item_id' => (int) $itemId, 'qty' => (int) $qty, 'batch_id' => (int) ($_POST['batch'][$itemId] ?? 0), 'reason' => trim((string) ($_POST['lreason'][$itemId] ?? '')) ?: null, 'condition' => trim((string) ($_POST['cond'][$itemId] ?? '')) ?: null];
    }
    foreach ($_POST['serial'] ?? [] as $itemId => $sids) {
        if ($sids) $lines[] = ['invoice_item_id' => (int) $itemId, 'serial_ids' => array_map('intval', $sids), 'reason' => trim((string) ($_POST['lreason'][$itemId] ?? '')) ?: null, 'condition' => trim((string) ($_POST['cond'][$itemId] ?? '')) ?: null];
    }
    try {
        $rid = return_create($invId, input_int($_POST, 'warehouse_id') ?: (int) $inv['warehouse_id'], input($_POST, 'reason'), $lines);
        flash('success', 'Return request created. A Sales Manager must approve it before the goods are collected.');
        redirect('/sales/return_view.php?id=' . $rid);
    } catch (RuntimeException $e) { $errors[] = $e->getMessage(); }
}
$pageTitle = 'New customer return';
require __DIR__ . '/../includes/header.php';
page_header('New customer return', 'Start from the original invoice so the batch, serial number and price are known exactly.', '<a class="btn btn-secondary" href="' . e(app_url('/sales/returns.php')) . '">Cancel</a>', [['Returns', '/sales/returns.php'], ['New return', null]]);
foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach;

if (!$inv):
    $matches = [];
    if ($q !== '') { $s = $pdo->prepare("SELECT si.*, c.business_name FROM sales_invoices si JOIN customers c ON c.id = si.customer_id WHERE si.status <> 'void' AND (si.inv_no LIKE :a OR c.business_name LIKE :b OR EXISTS (SELECT 1 FROM serial_numbers sn WHERE sn.invoice_id = si.id AND sn.serial_no LIKE :c)) ORDER BY si.id DESC LIMIT 20");
        $s->execute([':a' => "%$q%", ':b' => "%$q%", ':c' => "%$q%"]); $matches = $s->fetchAll(); }
    else $matches = $pdo->query("SELECT si.*, c.business_name FROM sales_invoices si JOIN customers c ON c.id = si.customer_id WHERE si.status <> 'void' ORDER BY si.id DESC LIMIT 10")->fetchAll();
?>
<div class="card mb-4"><form method="get" class="filter-bar mb-0"><div class="search-input" style="max-width:none;"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Find the invoice — invoice no., customer name or a serial number" autofocus></div><button class="btn btn-primary" type="submit">Search</button></form></div>
<div class="card flush"><div class="card-head"><h3><?= $q !== '' ? 'Matching invoices' : 'Recent invoices' ?></h3></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Invoice</th><th>Customer</th><th>Date</th><th class="num">Total</th><th></th></tr></thead>
  <tbody><?php foreach ($matches as $m): ?><tr><td class="strong"><?= e($m['inv_no']) ?></td><td><?= e($m['business_name']) ?></td><td class="text-secondary"><?= e(fmt_date($m['invoice_date'])) ?></td><td class="num"><?= format_money($m['total']) ?></td><td class="actions"><a class="btn btn-primary btn-sm" href="<?= e(app_url('/sales/return_form.php?invoice_id=' . $m['id'])) ?>">Select</a></td></tr><?php endforeach; ?></tbody></table><?php if (!$matches) echo empty_state('search', 'No invoices found'); ?></div></div>
<?php else:
    $items = $pdo->prepare('SELECT sii.*, p.name, p.code, p.base_unit, p.tracking FROM sales_invoice_items sii JOIN products p ON p.id = sii.product_id WHERE sii.invoice_id = :i ORDER BY sii.id'); $items->execute([':i' => $invId]); $items = $items->fetchAll();
    $whs = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY id')->fetchAll();
?>
<form method="post"><?= csrf_field() ?>
<div class="card mb-4"><div class="form-row">
  <div class="form-group"><label>Invoice</label><div class="strong" style="padding:9px 0;"><?= e($inv['inv_no']) ?> · <?= e($inv['business_name']) ?> · <?= format_money($inv['total']) ?></div></div>
  <div class="form-group"><label>Receive returned goods at</label><select class="form-control" name="warehouse_id"><?php foreach ($whs as $w): ?><option value="<?= (int) $w['id'] ?>" <?= (int) $inv['warehouse_id'] === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
  <div class="form-group" style="flex:2"><label>Reason for the return</label><input class="form-control" type="text" name="reason" maxlength="255" required placeholder="e.g. Damaged on arrival, hardware defect, wrong item"></div></div></div>
<div class="card flush"><div class="card-head"><div><h3>What is coming back?</h3><div class="sub">Quantities cannot exceed what was invoiced and not yet credited.</div></div></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th class="num">Can return</th><th style="width:300px;">Return</th><th>Condition / note</th></tr></thead><tbody>
  <?php foreach ($items as $it): $can = returnable_qty((int) $it['id']);
      $sers = $it['tracking'] === 'serial' ? $pdo->query("SELECT id, serial_no, status FROM serial_numbers WHERE invoice_id = $invId AND product_id = {$it['product_id']} ORDER BY serial_no")->fetchAll() : [];
      $bats = $it['tracking'] === 'batch' && $it['delivery_item_id'] ? $pdo->query("SELECT da.batch_id, b.batch_no, b.expiry_date, SUM(da.qty - da.rejected_qty) q FROM delivery_allocations da JOIN batches b ON b.id = da.batch_id WHERE da.delivery_item_id = {$it['delivery_item_id']} GROUP BY da.batch_id")->fetchAll() : []; ?>
    <tr><td><span class="strong"><?= e($it['name']) ?></span> <?= $it['tracking'] !== 'none' ? '<span class="tag ' . $it['tracking'] . '">' . e($it['tracking']) . '</span>' : '' ?><div class="cell-sub mono"><?= e($it['code']) ?> · sold at <?= format_money($it['unit_price'] * $it['pack_factor']) ?>/<?= e($it['pack_factor'] > 1 ? $it['pack_name'] : $it['base_unit']) ?></div></td>
      <td class="num strong"><?= fmt_qty($can) ?></td>
      <td><?php if ($can <= 0): ?><span class="text-muted">Nothing left to return</span>
        <?php elseif ($it['tracking'] === 'serial'): ?><div class="chip-list"><?php foreach ($sers as $sn): $ok = in_array($sn['status'], ['sold', 'dispatched'], true); ?><label class="sn-chip" style="<?= $ok ? 'cursor:pointer;' : 'opacity:.5;' ?>"><input type="checkbox" name="serial[<?= (int) $it['id'] ?>][]" value="<?= (int) $sn['id'] ?>" <?= $ok ? '' : 'disabled' ?>> <?= e($sn['serial_no']) ?></label><?php endforeach; ?></div>
        <?php elseif ($it['tracking'] === 'batch'): ?><div class="flex gap-2"><select class="form-control" name="batch[<?= (int) $it['id'] ?>]"><?php foreach ($bats as $b): ?><option value="<?= (int) $b['batch_id'] ?>"><?= e($b['batch_no']) ?> (<?= fmt_qty($b['q']) ?> delivered)</option><?php endforeach; ?></select><input class="form-control num-input" style="max-width:110px;" type="number" min="0" max="<?= $can ?>" name="qty[<?= (int) $it['id'] ?>]" value="0" placeholder="Qty"></div>
        <?php else: ?><input class="form-control num-input" style="max-width:140px;" type="number" min="0" max="<?= $can ?>" name="qty[<?= (int) $it['id'] ?>]" value="0"> <span class="text-sm text-muted"><?= e($it['base_unit']) ?></span><?php endif; ?></td>
      <td><?php if ($can > 0): ?><input class="form-control mb-1" type="text" name="lreason[<?= (int) $it['id'] ?>]" maxlength="150" placeholder="Reason (e.g. Leaking)"><input class="form-control" type="text" name="cond[<?= (int) $it['id'] ?>]" maxlength="255" placeholder="Condition on return"><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<div class="action-bar"><span class="text-muted text-sm">Returned goods are received into quarantine. Stock only becomes saleable after inspection.</span><button class="btn btn-primary btn-lg" type="submit"><?= icon('send', 16) ?> Submit return request</button></div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
