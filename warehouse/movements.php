<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('inventory.view');

$pdo = db();
$period = resolve_report_period('last_30');
$wh = input_int($_GET, 'wh'); $pid = input_int($_GET, 'product'); $type = input($_GET, 'type'); $batch = input_int($_GET, 'batch'); $serial = input_int($_GET, 'serial'); $q = input($_GET, 'q');
$page = max(1, input_int($_GET, 'page', 1)); $perPage = 60;
$types = movement_types();
$where = ($batch || $serial) ? '1=1' : 'sm.created_at >= :f AND sm.created_at < :t'; $params = ($batch || $serial) ? [] : period_bounds($period['from'], $period['to']);
if ($wh) { $where .= ' AND sm.warehouse_id = :w'; $params[':w'] = $wh; }
if ($pid) { $where .= ' AND sm.product_id = :p'; $params[':p'] = $pid; }
if ($batch) { $where .= ' AND sm.batch_id = :b'; $params[':b'] = $batch; }
if ($serial) { $where .= ' AND sm.serial_id = :sr'; $params[':sr'] = $serial; }
if (isset($types[$type])) { $where .= ' AND sm.type = :ty'; $params[':ty'] = $type; }
if ($q !== '') { $where .= ' AND (sm.reference_no LIKE :q1 OR p.name LIKE :q2 OR p.code LIKE :q3 OR b.batch_no LIKE :q4)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%", ':q4' => "%$q%"]; }
$from = "FROM stock_movements sm JOIN products p ON p.id = sm.product_id JOIN warehouses w ON w.id = sm.warehouse_id LEFT JOIN batches b ON b.id = sm.batch_id LEFT JOIN users u ON u.id = sm.created_by WHERE $where";
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
$st = $pdo->prepare("SELECT sm.*, p.name, p.code, w.code AS wh, b.batch_no, u.full_name AS who, (SELECT serial_no FROM serial_numbers WHERE id = sm.serial_id) AS serial_no $from ORDER BY sm.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params); $rows = $st->fetchAll();
$refLinks = ['goods_receipt' => '/purchasing/grn_view.php?id=', 'delivery' => '/sales/delivery_view.php?id=', 'sales_return' => '/sales/return_view.php?id=', 'stock_adjustment' => '/warehouse/adjustment_view.php?id=', 'stock_transfer' => '/warehouse/transfer_view.php?id=', 'batch_recall' => '/trace/recall_view.php?id='];
$whs = $pdo->query('SELECT id, name FROM warehouses ORDER BY name')->fetchAll();
$prods = $pdo->query('SELECT id, name FROM products ORDER BY name')->fetchAll();

$pageTitle = 'Stock movements';
require __DIR__ . '/../includes/header.php';
page_header('Stock movement history', 'Every receipt, dispatch, transfer, return and adjustment — with the running balance after each one.', '<a class="btn btn-secondary" href="' . e(app_url('/warehouse/stock.php')) . '">Back to stock</a>');
nav_warehouse();
if (!$batch && !$serial) { echo '<div class="card tight mb-4">'; render_period_filter($period, ['wh' => $wh ?: '', 'product' => $pid ?: '', 'type' => $type, 'q' => $q]); echo '</div>'; }
?>
<div class="card flush">
  <div style="padding:16px 16px 0;"><form method="get" class="filter-bar">
    <?php foreach (['preset' => $period['preset'] ?: 'custom', 'from' => $period['from'], 'to' => $period['to'], 'batch' => $batch ?: '', 'serial' => $serial ?: ''] as $k => $v) if ($v !== '') echo '<input type="hidden" name="' . $k . '" value="' . e((string) $v) . '">'; ?>
    <div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Reference, product or batch"></div>
    <select class="form-control" name="wh" onchange="this.form.submit()"><option value="">All warehouses</option><?php foreach ($whs as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $wh === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select>
    <select class="form-control" name="product" onchange="this.form.submit()"><option value="">All products</option><?php foreach ($prods as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $pid === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
    <select class="form-control" name="type" onchange="this.form.submit()"><option value="">All movement types</option><?php foreach ($types as $k => $t): ?><option value="<?= e($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($t[0]) ?></option><?php endforeach; ?></select>
  </form></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>When</th><th>Product</th><th>Batch / serial</th><th>Movement</th><th>Warehouse</th><th>Reference</th><th>By</th><th class="num">Change</th><th class="num">Balance</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $m): $t = $types[$m['type']] ?? [ucwords(str_replace('_', ' ', $m['type'])), 'neutral']; $link = $m['reference_type'] && $m['reference_id'] && isset($refLinks[$m['reference_type']]) ? app_url($refLinks[$m['reference_type']] . $m['reference_id']) : null; ?>
      <tr>
        <td class="nowrap text-secondary"><?= e(fmt_datetime($m['created_at'])) ?></td>
        <td><a class="strong" href="<?= e(app_url('/catalog/product_view.php?id=' . $m['product_id'])) ?>"><?= e($m['name']) ?></a></td>
        <td class="mono text-sm"><?= $m['batch_id'] ? '<a class="link" href="' . e(app_url('/trace/batch_view.php?id=' . $m['batch_id'])) . '">' . e($m['batch_no']) . '</a>' : '' ?><?= $m['serial_no'] ? '<a class="link" href="' . e(app_url('/trace/serial_view.php?id=' . $m['serial_id'])) . '">' . e($m['serial_no']) . '</a>' : '' ?></td>
        <td><span class="badge badge-<?= $t[1] ?>"><?= e($t[0]) ?></span><?= $m['bucket'] !== 'on_hand' ? ' <span class="text-muted text-sm">' . e($m['bucket']) . '</span>' : '' ?></td>
        <td class="text-secondary"><?= e($m['wh']) ?></td>
        <td class="mono text-sm"><?= $link ? '<a class="link" href="' . e($link) . '">' . e($m['reference_no']) . '</a>' : e($m['reference_no'] ?? '') ?></td>
        <td class="text-secondary text-sm"><?= e($m['who'] ?? '') ?></td>
        <td class="num strong <?= $m['change_qty'] < 0 ? 'text-error' : ($m['change_qty'] > 0 ? 'text-success' : 'text-muted') ?>"><?= $m['change_qty'] > 0 ? '+' : '' ?><?= fmt_qty($m['change_qty']) ?></td>
        <td class="num text-secondary"><?= fmt_qty($m['balance_after']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows) echo empty_state('history', 'No movements found', 'Try a wider date range or fewer filters.'); ?></div>
  <?php render_pagination($page, $perPage, $total); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
