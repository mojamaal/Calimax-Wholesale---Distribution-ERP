<?php
/** Global search across the ERP. Returns groups of {title, sub, meta, url}. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
header('Content-Type: application/json');
$q = input($_GET, 'q');
if (mb_strlen($q) < 2) { echo '{}'; exit; }
$like = "%$q%"; $pdo = db(); $out = [];
$run = function (string $key, string $perm, string $sql, array $p, callable $map) use ($pdo, &$out) {
    if (!can($perm)) return;
    $s = $pdo->prepare($sql); $s->execute($p);
    $rows = array_map($map, $s->fetchAll());
    if ($rows) $out[$key] = $rows;
};
$run('products', 'catalog.view', 'SELECT id, code, name, base_unit, tracking FROM products WHERE name LIKE :a OR code LIKE :b OR barcode = :c ORDER BY name LIMIT 5', [':a' => $like, ':b' => $like, ':c' => $q],
    fn($r) => ['title' => $r['name'], 'sub' => $r['code'] . ' · ' . $r['base_unit'], 'meta' => $r['tracking'] !== 'none' ? $r['tracking'] : '', 'url' => app_url('/catalog/product_view.php?id=' . $r['id'])]);
$run('customers', 'customers.view', 'SELECT id, code, business_name, city FROM customers WHERE business_name LIKE :a OR code LIKE :b OR phone LIKE :c ORDER BY business_name LIMIT 5', [':a' => $like, ':b' => $like, ':c' => $like],
    fn($r) => ['title' => $r['business_name'], 'sub' => $r['code'] . ' · ' . $r['city'], 'meta' => '', 'url' => app_url('/sales/customer_view.php?id=' . $r['id'])]);
$run('orders', 'sales.view', 'SELECT so.id, so.so_no, so.status, so.total, c.business_name FROM sales_orders so JOIN customers c ON c.id = so.customer_id WHERE so.so_no LIKE :a OR c.business_name LIKE :b OR so.customer_po_ref LIKE :c ORDER BY so.id DESC LIMIT 5', [':a' => $like, ':b' => $like, ':c' => $like],
    fn($r) => ['title' => $r['so_no'], 'sub' => $r['business_name'], 'meta' => format_money_short($r['total']), 'url' => app_url('/sales/order_view.php?id=' . $r['id'])]);
$run('invoices', 'invoices.view', 'SELECT si.id, si.inv_no, si.total, c.business_name FROM sales_invoices si JOIN customers c ON c.id = si.customer_id WHERE si.inv_no LIKE :a OR c.business_name LIKE :b ORDER BY si.id DESC LIMIT 5', [':a' => $like, ':b' => $like],
    fn($r) => ['title' => $r['inv_no'], 'sub' => $r['business_name'], 'meta' => format_money_short($r['total']), 'url' => app_url('/sales/invoice_view.php?id=' . $r['id'])]);
$run('suppliers', 'suppliers.view', 'SELECT id, code, name FROM suppliers WHERE name LIKE :a OR code LIKE :b ORDER BY name LIMIT 4', [':a' => $like, ':b' => $like],
    fn($r) => ['title' => $r['name'], 'sub' => $r['code'], 'meta' => '', 'url' => app_url('/purchasing/supplier_view.php?id=' . $r['id'])]);
$run('purchases', 'purchase.view', 'SELECT po.id, po.po_no, po.total, s.name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE po.po_no LIKE :a OR s.name LIKE :b ORDER BY po.id DESC LIMIT 4', [':a' => $like, ':b' => $like],
    fn($r) => ['title' => $r['po_no'], 'sub' => $r['name'], 'meta' => format_money_short($r['total']), 'url' => app_url('/purchasing/order_view.php?id=' . $r['id'])]);
if (can('trace.view')) {
    $rows = [];
    $s = $pdo->prepare('SELECT sn.id, sn.serial_no, sn.status, p.name FROM serial_numbers sn JOIN products p ON p.id = sn.product_id WHERE sn.serial_no LIKE :a ORDER BY sn.serial_no LIMIT 4'); $s->execute([':a' => $like]);
    foreach ($s->fetchAll() as $r) $rows[] = ['title' => $r['serial_no'], 'sub' => $r['name'] . ' · serial', 'meta' => str_replace('_', ' ', $r['status']), 'url' => app_url('/trace/serial_view.php?id=' . $r['id'])];
    $s = $pdo->prepare('SELECT b.id, b.batch_no, b.status, p.name FROM batches b JOIN products p ON p.id = b.product_id WHERE b.batch_no LIKE :a ORDER BY b.batch_no LIMIT 4'); $s->execute([':a' => $like]);
    foreach ($s->fetchAll() as $r) $rows[] = ['title' => $r['batch_no'], 'sub' => $r['name'] . ' · batch', 'meta' => $r['status'], 'url' => app_url('/trace/batch_view.php?id=' . $r['id'])];
    if ($rows) $out['trace'] = $rows;
}
echo json_encode($out);
