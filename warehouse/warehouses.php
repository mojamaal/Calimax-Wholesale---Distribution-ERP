<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('inventory.view');

$pdo = db();
if (is_post() && can('warehouses.manage')) {
    verify_csrf();
    $id = input_int($_POST, 'id'); $name = input($_POST, 'name'); $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', input($_POST, 'code')));
    try {
        if ($name === '' || $code === '') throw new RuntimeException('Enter a name and a short code.');
        $dup = $pdo->prepare('SELECT COUNT(*) FROM warehouses WHERE code = :c AND id <> :id'); $dup->execute([':c' => $code, ':id' => $id]);
        if ((int) $dup->fetchColumn()) throw new RuntimeException('That code is already used.');
        if ($id) {
            $active = isset($_POST['is_active']) ? 1 : 0;
            if (!$active && (int) $pdo->query("SELECT COALESCE(SUM(on_hand+quarantine+damaged),0) FROM stock WHERE warehouse_id = $id")->fetchColumn() > 0) throw new RuntimeException('This warehouse still holds stock. Transfer it out before deactivating.');
            $pdo->prepare('UPDATE warehouses SET name=:n, code=:c, address=:a, is_active=:ac WHERE id=:id')->execute([':n' => $name, ':c' => $code, ':a' => input($_POST, 'address') ?: null, ':ac' => $active, ':id' => $id]); log_activity('warehouse_update', "Updated warehouse $name", 'warehouse', $id);
        } else { $pdo->prepare('INSERT INTO warehouses (name, code, address) VALUES (:n,:c,:a)')->execute([':n' => $name, ':c' => $code, ':a' => input($_POST, 'address') ?: null]); log_activity('warehouse_create', "Created warehouse $name ($code)", 'warehouse', (int) $pdo->lastInsertId()); }
        flash('success', 'Warehouse saved.');
    } catch (RuntimeException $e) { flash('error', $e->getMessage()); }
    redirect('/warehouse/warehouses.php');
}
$val = inventory_valuation(); $by = [];
foreach ($val['rows'] as $v) { $by[$v['warehouse_id']] = ($by[$v['warehouse_id']] ?? ['v' => 0, 'u' => 0, 'p' => 0]); $by[$v['warehouse_id']]['v'] += $v['value']; $by[$v['warehouse_id']]['u'] += $v['qty']; $by[$v['warehouse_id']]['p']++; }
$list = $pdo->query('SELECT * FROM warehouses ORDER BY is_active DESC, id')->fetchAll();
$pageTitle = 'Warehouses';
require __DIR__ . '/../includes/header.php';
page_header('Warehouses', 'Each warehouse keeps its own stock. Transfer goods between them with a transfer note.', can('warehouses.manage') ? '<button class="btn btn-primary" type="button" onclick="openWh()">' . icon('plus', 16) . ' New warehouse</button>' : '');
nav_warehouse();
?>
<div class="grid grid-3">
<?php foreach ($list as $w): $b = $by[$w['id']] ?? ['v' => 0, 'u' => 0, 'p' => 0]; ?>
  <div class="card <?= $w['is_active'] ? '' : 'row-muted' ?>">
    <div class="flex justify-between items-start mb-3"><div class="flex items-center gap-3"><span class="icon-tile"><?= icon('building', 20) ?></span><div><div class="strong" style="font-size:15px;"><?= e($w['name']) ?></div><div class="text-muted text-sm mono"><?= e($w['code']) ?></div></div></div><?= status_badge($w['is_active'] ? 'active' : 'cancelled') ?></div>
    <div class="text-sm text-secondary mb-3"><?= e($w['address'] ?? 'No address') ?></div>
    <div class="facts"><div class="fact"><div class="fl">Products</div><div class="fv big"><?= (int) $b['p'] ?></div></div><div class="fact"><div class="fl">Units</div><div class="fv big"><?= fmt_qty($b['u']) ?></div></div><div class="fact"><div class="fl">Value</div><div class="fv big"><?= format_money_short($b['v']) ?></div></div></div>
    <div class="flex gap-2 mt-4"><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/warehouse/stock.php?wh=' . $w['id'])) ?>">View stock</a><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/warehouse/movements.php?wh=' . $w['id'])) ?>">Movements</a><?php if (can('warehouses.manage')): ?><button class="btn btn-secondary btn-sm" type="button" onclick='openWh(<?= js_json(['id' => $w['id'], 'name' => $w['name'], 'code' => $w['code'], 'address' => $w['address'], 'active' => (int) $w['is_active']]) ?>)'>Edit</button><?php endif; ?></div>
  </div>
<?php endforeach; ?>
</div>
<?php if (can('warehouses.manage')): ?>
<div class="modal-overlay" id="whModal" hidden><div class="modal sm"><div class="modal-header"><h3 id="wTitle">New warehouse</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" id="w_id">
    <div class="form-group"><label>Name</label><input class="form-control" type="text" name="name" id="w_name" required maxlength="100"></div>
    <div class="form-row"><div class="form-group"><label>Short code</label><input class="form-control mono" type="text" name="code" id="w_code" required maxlength="20"></div></div>
    <div class="form-group"><label>Address</label><input class="form-control" type="text" name="address" id="w_address" maxlength="255"></div>
    <label class="check" id="w_activeRow" hidden><input type="checkbox" name="is_active" id="w_active" checked> Warehouse is active</label>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div></form></div></div>
<script>function openWh(w){document.getElementById('wTitle').textContent=w?'Edit warehouse':'New warehouse';document.getElementById('w_id').value=w?w.id:'';document.getElementById('w_name').value=w?w.name:'';document.getElementById('w_code').value=w?w.code:'';document.getElementById('w_address').value=w&&w.address?w.address:'';document.getElementById('w_activeRow').hidden=!w;document.getElementById('w_active').checked=w?!!w.active:true;document.getElementById('whModal').hidden=false;}</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
