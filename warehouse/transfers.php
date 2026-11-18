<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('inventory.view');

$pdo = db();
$status = input($_GET, 'status'); $page = max(1, input_int($_GET, 'page', 1)); $perPage = 40;
$statuses = ['draft' => 'Draft', 'in_transit' => 'In transit', 'received' => 'Received', 'cancelled' => 'Cancelled'];
$where = '1=1'; $params = [];
if (isset($statuses[$status])) { $where .= ' AND st.status = :s'; $params[':s'] = $status; }
$from = "FROM stock_transfers st JOIN warehouses f ON f.id = st.from_wh JOIN warehouses t ON t.id = st.to_wh WHERE $where";
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
$s = $pdo->prepare("SELECT st.*, f.name AS from_name, t.name AS to_name, (SELECT COALESCE(SUM(qty_sent),0) FROM stock_transfer_items WHERE transfer_id = st.id) + (SELECT COUNT(*) FROM stock_transfer_serials WHERE transfer_id = st.id) AS units $from ORDER BY FIELD(st.status,'in_transit','draft','received','cancelled'), st.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$s->execute($params); $rows = $s->fetchAll();
$counts = []; foreach ($pdo->query('SELECT status, COUNT(*) n FROM stock_transfers GROUP BY status') as $r) $counts[$r['status']] = (int) $r['n'];
$pageTitle = 'Stock transfers';
require __DIR__ . '/../includes/header.php';
page_header('Stock transfers', 'Move goods between warehouses. Stock leaves the source when sent and enters the destination only when received.', can('inventory.transfer') ? '<a class="btn btn-primary" href="' . e(app_url('/warehouse/transfer_form.php')) . '">' . icon('plus', 16) . ' New transfer</a>' : '');
nav_warehouse();
?>
<div class="card flush">
  <div style="padding:16px 16px 0;"><div class="filter-bar"><div class="chip-group"><a class="chip <?= $status === '' ? 'active' : '' ?>" href="<?= e(query_with(['status' => null, 'page' => null])) ?>">All</a><?php foreach ($statuses as $k => $l): ?><a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(query_with(['status' => $k, 'page' => null])) ?>"><?= $l ?> <span class="chip-count"><?= $counts[$k] ?? 0 ?></span></a><?php endforeach; ?></div></div></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Transfer</th><th>From</th><th>To</th><th>Date</th><th class="num">Units</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $t): ?><tr><td class="strong"><a href="<?= e(app_url('/warehouse/transfer_view.php?id=' . $t['id'])) ?>"><?= e($t['tr_no']) ?></a></td><td><?= e($t['from_name']) ?></td><td><?= e($t['to_name']) ?></td><td class="text-secondary nowrap"><?= e(fmt_date($t['transfer_date'])) ?></td><td class="num"><?= fmt_qty($t['units']) ?></td><td><?= status_badge($t['status']) ?></td><td class="actions"><a class="btn btn-<?= $t['status'] === 'in_transit' ? 'primary' : 'secondary' ?> btn-sm" href="<?= e(app_url('/warehouse/transfer_view.php?id=' . $t['id'])) ?>"><?= $t['status'] === 'in_transit' ? 'Receive' : 'View' ?></a></td></tr><?php endforeach; ?></tbody></table>
  <?php if (!$rows) echo empty_state('arrow-left-right', 'No transfers yet', 'Create a transfer to replenish a regional warehouse.'); ?></div><?php render_pagination($page, $perPage, $total); ?></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
