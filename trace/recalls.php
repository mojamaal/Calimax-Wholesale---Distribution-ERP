<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('trace.view');

$pdo = db();
$status = input($_GET, 'status');
if (is_post() && can('batches.manage')) {
    verify_csrf();
    try { $rid = recall_start(input_int($_POST, 'batch_id'), input($_POST, 'reason')); flash('success', 'Recall started. Affected customers have been identified.'); redirect('/trace/recall_view.php?id=' . $rid); }
    catch (RuntimeException $e) { flash('error', $e->getMessage()); redirect('/trace/recalls.php'); }
}
$where = in_array($status, ['open', 'closed'], true) ? 'WHERE br.status = ' . $pdo->quote($status) : '';
$rows = $pdo->query("SELECT br.*, b.batch_no, b.received_qty, p.name, p.base_unit, u.full_name AS who, (SELECT COUNT(*) FROM recall_customers WHERE recall_id = br.id) AS customers, (SELECT COALESCE(SUM(qty_delivered),0) FROM recall_customers WHERE recall_id = br.id) AS delivered,
    (SELECT COALESCE(SUM(qty_recovered),0) FROM recall_customers WHERE recall_id = br.id) AS recovered FROM batch_recalls br JOIN batches b ON b.id = br.batch_id JOIN products p ON p.id = b.product_id LEFT JOIN users u ON u.id = br.initiated_by $where ORDER BY br.status = 'open' DESC, br.id DESC")->fetchAll();
$batches = $pdo->query("SELECT b.id, b.batch_no, p.name FROM batches b JOIN products p ON p.id = b.product_id WHERE b.status <> 'recalled' ORDER BY b.id DESC LIMIT 200")->fetchAll();
$pageTitle = 'Recalls';
require __DIR__ . '/../includes/header.php';
page_header('Batch recalls', 'If a supplier reports a quality problem, trace every affected customer in seconds and track what has been recovered.', can('batches.manage') ? '<button class="btn btn-danger" type="button" data-modal-open="#recallModal">' . icon('siren', 16) . ' Start a recall</button>' : '');
nav_trace();
?>
<div class="chip-group mb-4"><a class="chip <?= $status === '' ? 'active' : '' ?>" href="<?= e(query_with(['status' => null])) ?>">All</a><a class="chip <?= $status === 'open' ? 'active' : '' ?>" href="<?= e(query_with(['status' => 'open'])) ?>">Open</a><a class="chip <?= $status === 'closed' ? 'active' : '' ?>" href="<?= e(query_with(['status' => 'closed'])) ?>">Closed</a></div>
<div class="card flush"><div class="table-wrap"><table class="data-table"><thead><tr><th>Recall</th><th>Product / batch</th><th>Reason</th><th class="num">Customers</th><th style="width:200px;">Recovered</th><th>Started</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): $pct = $r['delivered'] ? min(100, round($r['recovered'] / $r['delivered'] * 100)) : 0; ?>
  <tr><td class="strong"><a href="<?= e(app_url('/trace/recall_view.php?id=' . $r['id'])) ?>"><?= e($r['recall_no']) ?></a></td><td><?= e($r['name']) ?><div class="cell-sub mono"><a class="link" href="<?= e(app_url('/trace/batch_view.php?id=' . $r['batch_id'])) ?>"><?= e($r['batch_no']) ?></a></div></td><td class="text-secondary" style="max-width:260px;"><?= e($r['reason']) ?></td><td class="num"><?= (int) $r['customers'] ?></td>
    <td><div class="text-sm"><?= fmt_qty($r['recovered']) ?> of <?= fmt_qty($r['delivered']) ?></div><div class="meter mt-1 <?= $pct < 100 ? 'warn' : '' ?>"><span style="width:<?= $pct ?>%"></span></div></td><td class="text-secondary nowrap"><?= e(fmt_date($r['initiated_at'])) ?><div class="cell-sub"><?= e($r['who'] ?? '') ?></div></td><td><?= status_badge($r['status'] === 'open' ? 'recalled' : 'closed') ?></td><td class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/trace/recall_view.php?id=' . $r['id'])) ?>">Open</a></td></tr><?php endforeach; ?></tbody></table>
<?php if (!$rows) echo empty_state('siren', 'No recalls', 'If a batch has a quality problem, start a recall from here or from the batch page.'); ?></div></div>
<?php if (can('batches.manage')): ?><div class="modal-overlay" id="recallModal" hidden><div class="modal"><div class="modal-header"><h3>Start a batch recall</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><div class="form-group"><label>Affected batch</label><select class="form-control" name="batch_id" required><option value="">Select batch…</option><?php foreach ($batches as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['batch_no']) ?> — <?= e($b['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Reason / manufacturer notice</label><input class="form-control" type="text" name="reason" required maxlength="255"></div><div class="callout bad text-sm">The batch is blocked from every sale and dispatch and the customers who received it are listed.</div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-danger" type="submit">Start recall</button></div></form></div></div><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
