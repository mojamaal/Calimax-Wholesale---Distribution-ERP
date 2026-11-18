<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('returns.create');

$pdo = db();
$status = input($_GET, 'status'); $q = input($_GET, 'q');
$page = max(1, input_int($_GET, 'page', 1)); $perPage = 40;
$statuses = ['requested' => 'Awaiting approval', 'approved' => 'To receive', 'received' => 'Under inspection', 'closed' => 'Closed', 'rejected' => 'Rejected'];
$where = '1=1'; $params = [];
if (isset($statuses[$status])) { $where .= ' AND sr.status = :s'; $params[':s'] = $status; }
if ($q !== '') { $where .= ' AND (sr.rma_no LIKE :q1 OR c.business_name LIKE :q2 OR si.inv_no LIKE :q3)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%"]; }
$from = "FROM sales_returns sr JOIN customers c ON c.id = sr.customer_id JOIN sales_invoices si ON si.id = sr.invoice_id WHERE $where";
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
$st = $pdo->prepare("SELECT sr.*, c.business_name, si.inv_no, (SELECT COALESCE(SUM(qty),0) FROM sales_return_items WHERE return_id = sr.id) AS units, (SELECT COUNT(*) FROM sales_return_items WHERE return_id = sr.id AND resolved_at IS NULL) AS pending
    $from ORDER BY FIELD(sr.status,'requested','approved','received','closed','rejected'), sr.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params); $rows = $st->fetchAll();
$counts = [];
foreach ($pdo->query('SELECT status, COUNT(*) n FROM sales_returns GROUP BY status') as $r) $counts[$r['status']] = (int) $r['n'];

$pageTitle = 'Customer returns';
require __DIR__ . '/../includes/header.php';
page_header('Returns & credit notes', 'Returned goods are received into quarantine and only become saleable again after inspection.', '<a class="btn btn-primary" href="' . e(app_url('/sales/return_form.php')) . '">' . icon('plus', 16) . ' New return</a>');
nav_sales();
render_subnav([['/sales/returns.php', 'Customer returns', 'returns.create', 'undo-2'], ['/sales/credit_notes.php', 'Credit notes', 'invoices.view', 'file-minus']]);
?>
<div class="card flush">
  <div style="padding:16px 16px 0;">
    <div class="filter-bar"><div class="chip-group"><a class="chip <?= $status === '' ? 'active' : '' ?>" href="<?= e(query_with(['status' => null, 'page' => null])) ?>">All</a><?php foreach ($statuses as $k => $l): ?><a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(query_with(['status' => $k, 'page' => null])) ?>"><?= $l ?> <span class="chip-count"><?= $counts[$k] ?? 0 ?></span></a><?php endforeach; ?></div></div>
    <form method="get" class="filter-bar"><?php if ($status) echo '<input type="hidden" name="status" value="' . e($status) . '">'; ?><div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Return no., invoice or customer"></div></form>
  </div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Return</th><th>Customer</th><th>Invoice</th><th>Date</th><th>Reason</th><th class="num">Units</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td class="strong"><a href="<?= e(app_url('/sales/return_view.php?id=' . $r['id'])) ?>"><?= e($r['rma_no']) ?></a></td><td><?= e($r['business_name']) ?></td><td><a class="link" href="<?= e(app_url('/sales/invoice_view.php?id=' . $r['invoice_id'])) ?>"><?= e($r['inv_no']) ?></a></td><td class="text-secondary nowrap"><?= e(fmt_date($r['created_at'])) ?></td><td class="text-secondary"><?= e($r['reason'] ?? '') ?></td><td class="num"><?= fmt_qty($r['units']) ?></td>
        <td><?= status_badge($r['status'] === 'received' ? 'under_inspection' : $r['status']) ?><?= $r['status'] === 'received' && $r['pending'] ? ' <span class="text-muted text-sm">' . (int) $r['pending'] . ' to inspect</span>' : '' ?></td>
        <td class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/sales/return_view.php?id=' . $r['id'])) ?>">Open</a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows) echo empty_state('undo-2', 'No returns', 'When a customer sends goods back, raise a return from their invoice.'); ?></div>
  <?php render_pagination($page, $perPage, $total); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
