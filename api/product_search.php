<?php
/** Product typeahead for order / purchase-order forms. ?q=&mode=sale|purchase&customer_id=&warehouse_id= */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
header('Content-Type: application/json');

$q = input($_GET, 'q');
$mode = input($_GET, 'mode') === 'purchase' ? 'purchase' : 'sale';
$cust = input_int($_GET, 'customer_id') ?: null;
$wh = input_int($_GET, 'warehouse_id') ?: null;
if ($q === '') { echo '[]'; exit; }

$s = db()->prepare("SELECT * FROM products WHERE is_active = 1 AND (name LIKE :a OR code LIKE :b OR barcode = :c OR brand LIKE :d) ORDER BY (code = :e) DESC, name LIMIT 12");
$s->execute([':a' => "%$q%", ':b' => "%$q%", ':c' => $q, ':d' => "%$q%", ':e' => $q]);
$out = [];
foreach ($s->fetchAll() as $p) {
    $u = db()->prepare('SELECT name, factor FROM product_units WHERE product_id = :id ORDER BY factor');
    $u->execute([':id' => $p['id']]);
    $packs = array_merge([['name' => $p['base_unit'], 'factor' => 1]], array_map(fn($x) => ['name' => $x['name'], 'factor' => (int) $x['factor']], $u->fetchAll()));
    $sum = stock_summary((int) $p['id'], $wh);
    $price = $p['default_price']; $source = 'default';
    if ($mode === 'sale') { $r = resolve_price((int) $p['id'], $cust, 1); $price = $r['price']; $source = $r['source']; }
    $out[] = [
        'id' => (int) $p['id'], 'code' => $p['code'], 'name' => $p['name'], 'tracking' => $p['tracking'], 'base_unit' => $p['base_unit'], 'packs' => $packs,
        'price' => (float) $price, 'source' => $source, 'cost' => can('products.view_cost') || $mode === 'purchase' ? (float) $p['avg_cost'] : 0, 'tax_rate' => (float) $p['tax_rate'],
        'stock' => $mode === 'sale' ? $sum['available'] : $sum['physical'], 'min_order_qty' => (int) $p['min_order_qty'],
    ];
}
echo json_encode($out);
