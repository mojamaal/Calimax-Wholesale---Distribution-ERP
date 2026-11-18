<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('trace.view');

$pdo = db();
$q = input($_GET, 'q'); $pid = input_int($_GET, 'product'); $status = input($_GET, 'status'); $wh = input_int($_GET, 'wh'); $empty = input($_GET, 'show') === 'all';
$page = max(1, input_int($_GET, 'page', 1)); $perPage = 40;
$where = '1=1'; $params = [];
if ($q !== '') { $where .= ' AND (b.batch_no LIKE :q1 OR p.name LIKE :q2 OR p.code LIKE :q3 OR su.name LIKE :q4)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%", ':q4' => "%$q%"]; }
if ($pid) { $where .= ' AND b.product_id = :p'; $params[':p'] = $pid; }
if (in_array($status, ['active', 'quarantined', 'recalled', 'expired'], true)) { $where .= ' AND b.status = :st'; $params[':st'] = $status; }
$wf = $wh ? ' AND s.warehouse_id = ' . $wh : '';
$base = "FROM batches b JOIN products p ON p.id = b.product_id LEFT JOIN suppliers su ON su.id = b.supplier_id LEFT JOIN stock s ON s.batch_id = b.id $wf WHERE $where GROUP BY b.id" . ($empty ? '' : ' HAVING (on_hand + quarantine + damaged) > 0 OR b.status = \'recalled\'');
$sel = "SELECT b.*, p.name, p.code, p.base_unit, su.name AS supplier, COALESCE(SUM(s.on_hand),0) AS on_hand, COALESCE(SUM(s.quarantine),0) AS quarantine, COALESCE(SUM(s.damaged),0) AS damaged,
    (SELECT COALESCE(SUM(da.qty - da.rejected_qty),0) FROM delivery_allocations da JOIN delivery_items di ON di.id = da.delivery_item_id JOIN deliveries d ON d.id = di.delivery_id WHERE da.batch_id = b.id AND d.status IN ('dispatched','delivered')) AS dispatched";
$all = $pdo->prepare("$sel $base ORDER BY b.expiry_date IS NULL, b.expiry_date, b.id"); $all->execute($params); $list = $all->fetchAll();
$total = count($list); $rows = array_slice($list, ($page - 1) * $perPage, $perPage);
$prods = $pdo->query("SELECT id, name FROM products WHERE tracking = 'batch' ORDER BY name")->fetchAll();
$whs = $pdo->query('SELECT id, name FROM warehouses ORDER BY name')->fetchAll();

$pageTitle = 'Batches & lots';
require __DIR__ . '/../includes/header.php';
page_header('Batch & lot tracking', 'Each delivery of a batch-tracked product is its own batch with an expiry date, a supplier and a full history.');
nav_trace();
?>
<div class="card mb-4"><form method="get" class="filter-bar mb-0"><div class="search-input" style="max-width:none;"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Batch number, product or supplier — e.g. BATCH-001" style="font-family:ui-monospace,Consolas,monospace;"></div><button class="btn btn-primary" type="submit">Find batch</button></form></div>
<div class="card flush">
  <div style="padding:16px 16px 0;"><form method="get" class="filter-bar">
    <?php if ($q !== '') echo '<input type="hidden" name="q" value="' . e($q) . '">'; ?>
    <select class="form-control" name="product" onchange="this.form.submit()"><option value="">All products</option><?php foreach ($prods as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $pid === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
    <select class="form-control" name="wh" onchange="this.form.submit()"><option value="">All warehouses</option><?php foreach ($whs as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $wh === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select>
    <div class="chip-group"><a class="chip <?= $status === '' ? 'active' : '' ?>" href="<?= e(query_with(['status' => null, 'page' => null])) ?>">All</a><?php foreach (['active' => 'Active', 'quarantined' => 'Quarantined', 'recalled' => 'Recalled', 'expired' => 'Expired'] as $k => $l): ?><a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(query_with(['status' => $k, 'page' => null])) ?>"><?= $l ?></a><?php endforeach; ?><a class="chip <?= $empty ? 'active' : '' ?>" href="<?= e(query_with(['show' => $empty ? null : 'all', 'page' => null])) ?>">Include empty</a></div>
  </form></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Batch</th><th>Product</th><th>Supplier</th><th>Received</th><th>Expiry</th><th class="num">Received qty</th><th class="num">Dispatched</th><th class="num">On hand</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $b): ?>
      <tr><td class="strong mono"><a href="<?= e(app_url('/trace/batch_view.php?id=' . $b['id'])) ?>"><?= e($b['batch_no']) ?></a></td><td><?= e($b['name']) ?></td><td class="text-secondary"><?= e($b['supplier'] ?? '—') ?></td><td class="text-secondary nowrap"><?= e(fmt_date($b['received_date'])) ?></td>
        <td class="nowrap"><?= $b['expiry_date'] ? e(fmt_date($b['expiry_date'])) . '<div class="cell-sub">' . expiry_badge($b['expiry_date']) . '</div>' : '<span class="text-muted">—</span>' ?></td>
        <td class="num"><?= fmt_qty($b['received_qty']) ?></td><td class="num text-secondary"><?= fmt_qty($b['dispatched']) ?></td><td class="num strong"><?= fmt_qty($b['on_hand']) ?><?= (int) $b['quarantine'] ? '<div class="cell-sub text-warning">+' . fmt_qty($b['quarantine']) . ' quarantine</div>' : '' ?></td><td><?= status_badge($b['status']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows) echo empty_state('layers', 'No batches found', 'Batches are created when batch-tracked goods are received.'); ?></div>
  <?php render_pagination($page, $perPage, $total); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
