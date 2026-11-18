<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('inventory.view');

$pdo = db();
$status = input($_GET, 'status'); $page = max(1, input_int($_GET, 'page', 1)); $perPage = 40;
$statuses = ['pending' => 'Awaiting approval', 'approved' => 'Approved', 'rejected' => 'Rejected'];
$where = '1=1'; $params = [];
if (isset($statuses[$status])) { $where .= ' AND sa.status = :s'; $params[':s'] = $status; }
$from = "FROM stock_adjustments sa JOIN warehouses w ON w.id = sa.warehouse_id LEFT JOIN users u ON u.id = sa.requested_by WHERE $where";
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
$st = $pdo->prepare("SELECT sa.*, w.name AS wh, u.full_name AS who, (SELECT COUNT(*) FROM stock_adjustment_items WHERE adjustment_id = sa.id) AS n_lines $from ORDER BY FIELD(sa.status,'pending','approved','rejected'), sa.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params); $rows = $st->fetchAll();
$counts = []; foreach ($pdo->query('SELECT status, COUNT(*) n FROM stock_adjustments GROUP BY status') as $r) $counts[$r['status']] = (int) $r['n'];
$pageTitle = 'Stock adjustments';
require __DIR__ . '/../includes/header.php';
page_header('Adjustments & counts', 'Damaged, expired, lost or found stock — recorded with a reason and approved by the warehouse manager.', can('inventory.adjust') ? '<a class="btn btn-primary" href="' . e(app_url('/warehouse/adjustment_form.php')) . '">' . icon('plus', 16) . ' New adjustment</a>' : '');
nav_warehouse();
?>
<div class="card flush">
  <div style="padding:16px 16px 0;"><div class="filter-bar"><div class="chip-group"><a class="chip <?= $status === '' ? 'active' : '' ?>" href="<?= e(query_with(['status' => null, 'page' => null])) ?>">All</a><?php foreach ($statuses as $k => $l): ?><a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(query_with(['status' => $k, 'page' => null])) ?>"><?= $l ?> <span class="chip-count"><?= $counts[$k] ?? 0 ?></span></a><?php endforeach; ?></div></div></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Adjustment</th><th>Warehouse</th><th>Reason</th><th class="num">Lines</th><th class="num">Value effect</th><th>Requested by</th><th>Date</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $a): ?><tr><td class="strong"><a href="<?= e(app_url('/warehouse/adjustment_view.php?id=' . $a['id'])) ?>"><?= e($a['adj_no']) ?></a><?= $a['source'] === 'count' ? ' <span class="badge badge-info">Count</span>' : '' ?></td><td><?= e($a['wh']) ?></td><td><?= e(adjustment_reason_label($a['reason'])) ?></td><td class="num"><?= (int) $a['n_lines'] ?></td><td class="num <?= $a['value_change'] < 0 ? 'text-error' : '' ?>"><?= $a['status'] === 'approved' ? format_money($a['value_change']) : '—' ?></td><td class="text-secondary"><?= e($a['who'] ?? '—') ?></td><td class="text-secondary nowrap"><?= e(fmt_date($a['created_at'])) ?></td><td><?= status_badge($a['status']) ?></td></tr><?php endforeach; ?></tbody></table>
  <?php if (!$rows) echo empty_state('clipboard-check', 'No adjustments', 'Record damaged, expired or lost stock here so the books stay accurate.'); ?></div><?php render_pagination($page, $perPage, $total); ?></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
