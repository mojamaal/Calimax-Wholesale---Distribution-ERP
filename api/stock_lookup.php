<?php
/** Product + warehouse stock detail for transfers / adjustments. ?q=&warehouse_id=&bucket=on_hand|damaged|any */
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('inventory.view');
header('Content-Type: application/json');
$q = input($_GET, 'q'); $wh = input_int($_GET, 'warehouse_id');
$pf = input_int($_GET, 'product_id');
if (($q === '' && !$pf) || !$wh) { echo '[]'; exit; }
if ($pf) { $s = db()->prepare('SELECT * FROM products WHERE id = :id'); $s->execute([':id' => $pf]); }
else { $s = db()->prepare('SELECT * FROM products WHERE is_active = 1 AND (name LIKE :a OR code LIKE :b OR barcode = :c) ORDER BY name LIMIT 10'); $s->execute([':a' => "%$q%", ':b' => "%$q%", ':c' => $q]); }
$out = [];
foreach ($s->fetchAll() as $p) {
    $pid = (int) $p['id'];
    $sum = stock_summary($pid, $wh);
    $row = ['id' => $pid, 'code' => $p['code'], 'name' => $p['name'], 'tracking' => $p['tracking'], 'base_unit' => $p['base_unit'], 'saleable' => $sum['saleable'], 'damaged' => $sum['damaged'], 'quarantine' => $sum['quarantine'], 'batches' => [], 'serials' => []];
    if ($p['tracking'] === 'batch') {
        $b = db()->prepare("SELECT b.id, b.batch_no, b.expiry_date, b.status, s.on_hand, s.quarantine, s.damaged FROM stock s JOIN batches b ON b.id = s.batch_id WHERE s.product_id = :p AND s.warehouse_id = :w AND (s.on_hand + s.quarantine + s.damaged) > 0 ORDER BY b.expiry_date IS NULL, b.expiry_date, b.id");
        $b->execute([':p' => $pid, ':w' => $wh]);
        $row['batches'] = array_map(fn($x) => ['id' => (int) $x['id'], 'batch_no' => $x['batch_no'], 'expiry' => $x['expiry_date'], 'status' => $x['status'], 'on_hand' => (int) $x['on_hand'], 'quarantine' => (int) $x['quarantine'], 'damaged' => (int) $x['damaged']], $b->fetchAll());
    } elseif ($p['tracking'] === 'serial') {
        $r = db()->prepare("SELECT id, serial_no, status FROM serial_numbers WHERE product_id = :p AND warehouse_id = :w AND status IN ('available','damaged','under_inspection','under_repair','returned') ORDER BY serial_no LIMIT 300");
        $r->execute([':p' => $pid, ':w' => $wh]);
        $row['serials'] = array_map(fn($x) => ['id' => (int) $x['id'], 'serial_no' => $x['serial_no'], 'status' => $x['status']], $r->fetchAll());
    }
    $out[] = $row;
}
echo json_encode($out);
