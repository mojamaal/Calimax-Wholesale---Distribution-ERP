<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('trace.view');

$pdo = db();
$q = input($_GET, 'q'); $pid = input_int($_GET, 'product'); $status = input($_GET, 'status'); $wh = input_int($_GET, 'wh'); $cust = input_int($_GET, 'customer');
$page = max(1, input_int($_GET, 'page', 1)); $perPage = 50;
// Exact serial number → straight to its history
if ($q !== '') { $x = $pdo->prepare('SELECT id FROM serial_numbers WHERE serial_no = :s'); $x->execute([':s' => $q]); $ids = $x->fetchAll(PDO::FETCH_COLUMN); if (count($ids) === 1) redirect('/trace/serial_view.php?id=' . $ids[0]); }
$where = '1=1'; $params = [];
if ($q !== '') { $where .= ' AND (sn.serial_no LIKE :q1 OR p.name LIKE :q2 OR c.business_name LIKE :q3 OR si.inv_no LIKE :q4)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%", ':q4' => "%$q%"]; }
if ($pid) { $where .= ' AND sn.product_id = :p'; $params[':p'] = $pid; }
if (isset(serial_status_options()[$status])) { $where .= ' AND sn.status = :st'; $params[':st'] = $status; }
if ($wh) { $where .= ' AND sn.warehouse_id = :w'; $params[':w'] = $wh; }
if ($cust) { $where .= ' AND sn.customer_id = :c'; $params[':c'] = $cust; }
$from = "FROM serial_numbers sn JOIN products p ON p.id = sn.product_id LEFT JOIN warehouses w ON w.id = sn.warehouse_id LEFT JOIN customers c ON c.id = sn.customer_id LEFT JOIN sales_invoices si ON si.id = sn.invoice_id LEFT JOIN suppliers su ON su.id = sn.supplier_id WHERE $where";
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
$st = $pdo->prepare("SELECT sn.*, p.name, p.code, w.code AS wh, c.business_name, si.inv_no, su.name AS supplier $from ORDER BY sn.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params); $rows = $st->fetchAll();
$counts = []; foreach ($pdo->query('SELECT status, COUNT(*) n FROM serial_numbers GROUP BY status') as $r) $counts[$r['status']] = (int) $r['n'];
$prods = $pdo->query("SELECT id, name FROM products WHERE tracking = 'serial' ORDER BY name")->fetchAll();
$whs = $pdo->query('SELECT id, name FROM warehouses ORDER BY name')->fetchAll();
$showCost = can('products.view_cost');

$pageTitle = 'Serial numbers';
require __DIR__ . '/../includes/header.php';
page_header('Serial number tracking', 'Every unit is individually identifiable: where it came from, where it is, who bought it and whether it is under warranty.');
nav_trace();
?>
<div class="card mb-4"><form method="get" class="filter-bar mb-0"><div class="search-input" style="max-width:none;"><?= icon('scan-barcode', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Scan or type a serial number, customer or invoice — e.g. DELL-SN-0003" autofocus style="font-family:ui-monospace,Consolas,monospace;"></div><button class="btn btn-primary" type="submit">Find</button></form></div>
<div class="card flush">
  <div style="padding:16px 16px 0;">
    <div class="filter-bar"><div class="chip-group"><a class="chip <?= $status === '' ? 'active' : '' ?>" href="<?= e(query_with(['status' => null, 'page' => null])) ?>">All <span class="chip-count"><?= array_sum($counts) ?></span></a><?php foreach (serial_status_options() as $k => $l): if (empty($counts[$k])) continue; ?><a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(query_with(['status' => $k, 'page' => null])) ?>"><?= e($l) ?> <span class="chip-count"><?= $counts[$k] ?></span></a><?php endforeach; ?></div></div>
    <form method="get" class="filter-bar"><?php foreach (['q' => $q, 'status' => $status] as $k => $v) if ($v !== '') echo '<input type="hidden" name="' . $k . '" value="' . e($v) . '">'; ?>
      <select class="form-control" name="product" onchange="this.form.submit()"><option value="">All products</option><?php foreach ($prods as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $pid === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
      <select class="form-control" name="wh" onchange="this.form.submit()"><option value="">All warehouses</option><?php foreach ($whs as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $wh === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></form>
  </div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Serial number</th><th>Product</th><th>Status</th><th>Location</th><th>Customer / invoice</th><th>Supplier / received</th><th>Warranty</th><?php if ($showCost): ?><th class="num">Cost</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $w = warranty_state($r); ?>
      <tr><td class="strong mono"><a href="<?= e(app_url('/trace/serial_view.php?id=' . $r['id'])) ?>"><?= e($r['serial_no']) ?></a></td><td><?= e($r['name']) ?></td><td><?= status_badge($r['status']) ?></td><td class="text-secondary"><?= e($r['wh'] ?? ($r['status'] === 'available' ? 'In transit' : '—')) ?></td>
        <td class="text-sm"><?= $r['business_name'] ? '<a href="' . e(app_url('/sales/customer_view.php?id=' . $r['customer_id'])) . '">' . e($r['business_name']) . '</a>' . ($r['inv_no'] ? '<div class="cell-sub"><a class="link" href="' . e(app_url('/sales/invoice_view.php?id=' . $r['invoice_id'])) . '">' . e($r['inv_no']) . '</a></div>' : '') : '<span class="text-muted">—</span>' ?></td>
        <td class="text-sm text-secondary"><?= e($r['supplier'] ?? '—') ?><div class="cell-sub"><?= e(fmt_date($r['received_date'])) ?></div></td>
        <td class="text-sm"><?= $w['state'] === 'active' ? '<span class="badge badge-success">Until ' . e(fmt_date($r['warranty_expiry'])) . '</span>' : ($w['state'] === 'expired' ? '<span class="badge badge-error">Expired</span>' : '<span class="text-muted">' . e($w['label']) . '</span>') ?></td>
        <?php if ($showCost): ?><td class="num text-secondary"><?= format_money($r['unit_cost']) ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows) echo empty_state('barcode', 'No serial numbers found', 'Serial numbers are registered when serial-tracked goods are received.'); ?></div>
  <?php render_pagination($page, $perPage, $total); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
