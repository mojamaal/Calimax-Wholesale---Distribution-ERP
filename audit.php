<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_permission('audit.view');

$pdo = db();
$period = resolve_report_period('last_30');
$userId = input_int($_GET, 'user_id');
$group = input($_GET, 'group');
$q = input($_GET, 'q');
$entity = input($_GET, 'entity');
$entityId = input_int($_GET, 'entity_id');
$page = max(1, input_int($_GET, 'page', 1));
$perPage = 60;

// Friendly groups → action names, so owners can answer the questions that matter.
$groups = [
    'prices'      => ['Price & pricing changes', ['price_change', 'price_list_update', 'customer_price_update', 'price_break_update']],
    'customers'   => ['Customers & credit limits', ['customer_create', 'customer_update', 'credit_limit_change', 'payment_terms_change', 'customer_toggle']],
    'products'    => ['Products & categories', ['product_create', 'product_update', 'category_create', 'category_update', 'category_delete']],
    'sales'       => ['Sales orders & approvals', ['so_save', 'so_submit', 'so_confirm', 'so_approve', 'so_reject', 'so_cancel', 'so_close_balance']],
    'delivery'    => ['Picking, dispatch & delivery', ['delivery_create', 'delivery_pick', 'delivery_pack', 'delivery_dispatch', 'delivery_confirm', 'delivery_fail', 'delivery_cancel']],
    'invoicing'   => ['Invoices, payments & credit notes', ['invoice_create', 'invoice_void', 'customer_payment', 'credit_note_issue']],
    'returns'     => ['Customer returns', ['return_create', 'return_approve', 'return_reject', 'return_receive', 'return_inspect']],
    'stock'       => ['Stock adjustments, counts & transfers', ['stock_adjustment_create', 'stock_adjustment_approve', 'stock_adjustment_reject', 'stock_count_create', 'stock_count_submit', 'stock_count_approve', 'transfer_create', 'transfer_send', 'transfer_receive', 'warehouse_create', 'warehouse_update']],
    'trace'       => ['Batches, recalls & warranty', ['batch_quarantine', 'batch_release', 'batch_recall', 'recall_recovery', 'recall_contact', 'recall_close', 'warranty_claim', 'warranty_update']],
    'purchasing'  => ['Purchase orders & receiving', ['po_save', 'po_submit', 'po_approve', 'po_reject', 'po_send', 'po_cancel', 'po_close', 'grn_create', 'purchase_return', 'supplier_create', 'supplier_update', 'supplier_toggle']],
    'supplier_money' => ['Supplier invoices & payments', ['supplier_bill_create', 'supplier_payment']],
    'money'       => ['Expenses & funds', ['expense_create', 'expense_delete', 'fund_transfer', 'target_update']],
    'security'    => ['Logins & staff', ['login', 'logout', 'user_create', 'user_update', 'user_toggle', 'password_reset', 'settings_update']],
];
$where = 'al.created_at >= :f AND al.created_at < :t';
$params = period_bounds($period['from'], $period['to']);
if ($entity !== '' && $entityId) { $where = 'al.entity_type = :et AND al.entity_id = :eid'; $params = [':et' => $entity, ':eid' => $entityId]; }
if ($userId) { $where .= ' AND al.user_id = :u'; $params[':u'] = $userId; }
if (isset($groups[$group])) {
    $ph = [];
    foreach ($groups[$group][1] as $i => $a) { $ph[] = ":a$i"; $params[":a$i"] = $a; }
    $where .= ' AND al.action IN (' . implode(',', $ph) . ')';
}
if ($q !== '') { $where .= ' AND al.details LIKE :q'; $params[':q'] = "%$q%"; }

$from = "FROM activity_log al LEFT JOIN users u ON u.id = al.user_id WHERE $where";
if (input($_GET, 'export') === 'csv') {
    $st = $pdo->prepare("SELECT al.created_at, u.full_name, u.username, al.action, al.entity_type, al.entity_id, al.details, al.ip_address $from ORDER BY al.id DESC LIMIT 20000");
    $st->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-log-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['When', 'User', 'Username', 'Action', 'Entity', 'Entity ID', 'Details', 'IP']);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $row) fputcsv($out, $row);
    log_activity('audit_export', 'Exported audit log CSV');
    exit;
}
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$st = $pdo->prepare("SELECT al.*, u.full_name, u.role $from ORDER BY al.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params);
$rows = $st->fetchAll();
$users = $pdo->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll();

$entityLinks = ['sales_order' => '/sales/order_view.php?id=', 'delivery' => '/sales/delivery_view.php?id=', 'sales_invoice' => '/sales/invoice_view.php?id=', 'sales_return' => '/sales/return_view.php?id=',
    'customer' => '/sales/customer_view.php?id=', 'product' => '/catalog/product_view.php?id=', 'purchase_order' => '/purchasing/order_view.php?id=', 'goods_receipt' => '/purchasing/grn_view.php?id=', 'supplier_invoice' => '/purchasing/bill_view.php?id=',
    'supplier' => '/purchasing/supplier_view.php?id=', 'stock_adjustment' => '/warehouse/adjustment_view.php?id=', 'stock_count' => '/warehouse/count_view.php?id=', 'stock_transfer' => '/warehouse/transfer_view.php?id=',
    'batch' => '/trace/batch_view.php?id=', 'batch_recall' => '/trace/recall_view.php?id=', 'serial' => '/trace/serial_view.php?id=', 'credit_note' => '/sales/credit_notes.php?highlight=', 'customer_payment' => '/sales/payments.php?highlight='];
$actionTone = fn($a) => str_contains($a, 'void') || str_contains($a, 'delete') || str_contains($a, 'reject') ? 'error' : (str_contains($a, 'price') || str_contains($a, 'adjust') || str_contains($a, 'return') ? 'warning' : (str_contains($a, 'login') || str_contains($a, 'logout') ? 'neutral' : 'info'));

$pageTitle = 'Audit Log';
require __DIR__ . '/includes/header.php';
page_header('Audit Log', 'Who did what, and when: customers and credit limits, price changes, purchase orders and approvals, stock adjustments, dispatches, invoices, credit notes, payments and more.', '<a class="btn btn-secondary" href="' . e(query_with(['export' => 'csv', 'page' => null])) . '">' . icon('download', 16) . ' Export CSV</a>');
?>
<div class="card tight mb-4">
  <div class="chip-group mb-3">
    <a class="chip <?= $group === '' ? 'active' : '' ?>" href="<?= e(query_with(['group' => null, 'page' => null])) ?>">Everything</a>
    <?php foreach ($groups as $k => [$label]): ?><a class="chip <?= $group === $k ? 'active' : '' ?>" href="<?= e(query_with(['group' => $k, 'page' => null])) ?>"><?= e($label) ?></a><?php endforeach; ?>
  </div>
  <?php if (!$entityId) render_period_filter($period, array_filter(['group' => $group, 'user_id' => $userId ?: '', 'q' => $q])); ?>
  <form method="get" class="filter-bar mb-0">
    <?php foreach (['group' => $group, 'preset' => $period['preset'] ?: 'custom', 'from' => $period['from'], 'to' => $period['to']] as $k => $v): if ($v !== '') echo '<input type="hidden" name="' . $k . '" value="' . e($v) . '">'; endforeach; ?>
    <select class="form-control" name="user_id" onchange="this.form.submit()"><option value="">All staff</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $userId === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?></select>
    <div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search details, e.g. INV-00125, PO-00045 or a customer name"></div>
    <button class="btn btn-secondary" type="submit">Filter</button>
    <span class="text-muted text-sm" style="margin-left:auto;"><?= number_format($total) ?> event(s)</span>
  </form>
</div>
<div class="card flush">
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th style="width:170px;">When</th><th>Who</th><th>Action</th><th>Details</th><th>Record</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap text-secondary"><?= e(fmt_datetime($r['created_at'])) ?></td>
        <td class="nowrap"><span class="strong"><?= e($r['full_name'] ?? 'System') ?></span><?= $r['role'] ? '<div class="cell-sub">' . e(role_label($r['role'])) . '</div>' : '' ?></td>
        <td class="nowrap"><span class="badge badge-<?= $actionTone($r['action']) ?>"><?= e(ucwords(str_replace('_', ' ', $r['action']))) ?></span></td>
        <td style="max-width:520px;"><?= e($r['details']) ?></td>
        <td class="nowrap"><?= $r['entity_type'] && isset($entityLinks[$r['entity_type']]) && $r['entity_id'] ? '<a class="link text-sm" href="' . e(app_url($entityLinks[$r['entity_type']] . $r['entity_id'])) . '">Open ' . e(str_replace('_', ' ', $r['entity_type'])) . '</a>' : '' ?></td>
        <td class="text-muted text-sm mono"><?= e($r['ip_address'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows) echo empty_state('shield-check', 'No matching events', 'Try a wider date range or a different filter.'); ?></div>
  <?php render_pagination($page, $perPage, $total); ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
