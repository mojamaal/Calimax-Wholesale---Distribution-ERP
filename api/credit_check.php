<?php
/** Live credit exposure for the order form. ?customer_id=&total=&type=credit|cash&so_id= */
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('sales.view');
header('Content-Type: application/json');
$cid = input_int($_GET, 'customer_id');
if (!$cid) { echo json_encode(['none' => true]); exit; }
$c = db()->prepare('SELECT c.business_name, c.payment_terms_days, c.credit_limit, cc.name AS category FROM customers c LEFT JOIN customer_categories cc ON cc.id = c.category_id WHERE c.id = :id');
$c->execute([':id' => $cid]);
$cu = $c->fetch();
if (!$cu) { echo json_encode(['none' => true]); exit; }
$chk = credit_check($cid, input_float($_GET, 'total'), input($_GET, 'type') === 'cash' ? 'cash' : 'credit', input_int($_GET, 'so_id') ?: null);
echo json_encode($chk + ['customer' => $cu['business_name'], 'terms' => (int) $cu['payment_terms_days'], 'category' => $cu['category']]);
