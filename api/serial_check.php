<?php
/** Validate a scanned serial number. ?serial=&product_id=&warehouse_id=&mode=receive|pick&delivery_id= */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
header('Content-Type: application/json');
$serial = input($_GET, 'serial'); $pid = input_int($_GET, 'product_id'); $mode = input($_GET, 'mode', 'pick');
if ($serial === '') { echo json_encode(['ok' => false, 'message' => 'Empty serial number.']); exit; }
$s = db()->prepare('SELECT sn.*, p.name FROM serial_numbers sn JOIN products p ON p.id = sn.product_id WHERE sn.serial_no = :s' . ($pid ? ' AND sn.product_id = :p' : ''));
$s->execute($pid ? [':s' => $serial, ':p' => $pid] : [':s' => $serial]);
$r = $s->fetch();
if ($mode === 'receive') {
    echo json_encode($r ? ['ok' => false, 'message' => "Serial $serial is already registered (" . str_replace('_', ' ', $r['status']) . ')'] : ['ok' => true, 'serial' => $serial]);
    exit;
}
if (!$r) { echo json_encode(['ok' => false, 'message' => "Serial $serial is not registered for this product."]); exit; }
$wh = input_int($_GET, 'warehouse_id'); $del = input_int($_GET, 'delivery_id');
if ($wh && (int) $r['warehouse_id'] !== $wh) { echo json_encode(['ok' => false, 'message' => "Serial $serial is stored in a different warehouse."]); exit; }
if ($r['status'] !== 'available' && !($r['status'] === 'reserved' && (int) $r['delivery_id'] === $del)) { echo json_encode(['ok' => false, 'message' => "Serial $serial is " . str_replace('_', ' ', $r['status']) . ' and cannot be picked.']); exit; }
echo json_encode(['ok' => true, 'serial' => $r['serial_no'], 'id' => (int) $r['id']]);
