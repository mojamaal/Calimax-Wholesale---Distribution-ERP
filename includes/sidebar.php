<?php
/** Sidebar navigation (grouped, permission-aware). Included by header.php.
 *  Rendered inside a closure so its loop variables never leak into the page. */
(function (array $navBadges) {
$user = current_user();
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$base = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?: '', '/');
$rel = substr($script, strlen($base));

// [href, label, icon, permission(s), [active path prefixes], badge key]
$sections = [
    'Overview' => [
        ['/index.php', 'Dashboard', 'layout-grid', 'dashboard.view', ['/index.php']],
    ],
    'Sales & Distribution' => [
        ['/sales/orders.php', 'Sales Orders', 'clipboard-list', 'sales.view', ['/sales/orders.php', '/sales/order_'], 'orders'],
        ['/sales/deliveries.php', 'Deliveries', 'truck', 'delivery.view', ['/sales/deliver', '/sales/delivery_']],
        ['/sales/invoices.php', 'Invoices', 'receipt', 'invoices.view', ['/sales/invoice']],
        ['/sales/payments.php', 'Collections', 'hand-coins', 'invoices.view', ['/sales/payment']],
        ['/sales/returns.php', 'Returns & Credits', 'undo-2', 'returns.create', ['/sales/return', '/sales/credit_notes'], 'returns'],
        ['/sales/customers.php', 'Customers', 'contact', 'customers.view', ['/sales/customer']],
    ],
    'Warehouse' => [
        ['/warehouse/fulfilment.php', 'Fulfilment Queue', 'package-check', 'delivery.view', ['/warehouse/fulfilment.php'], 'pick'],
        ['/warehouse/stock.php', 'Stock by Warehouse', 'warehouse', 'inventory.view', ['/warehouse/stock.php', '/warehouse/product_stock.php', '/warehouse/warehouses.php', '/warehouse/movements.php']],
        ['/warehouse/transfers.php', 'Transfers', 'arrow-left-right', 'inventory.view', ['/warehouse/transfer']],
        ['/warehouse/adjustments.php', 'Adjustments & Counts', 'clipboard-check', 'inventory.view', ['/warehouse/adjustment', '/warehouse/count'], 'stock'],
    ],
    'Traceability' => [
        ['/trace/serials.php', 'Serial Numbers', 'barcode', 'trace.view', ['/trace/serial']],
        ['/trace/batches.php', 'Batches & Lots', 'layers', 'trace.view', ['/trace/batch']],
        ['/trace/expiry.php', 'Expiry Monitor', 'calendar-clock', 'trace.view', ['/trace/expiry.php'], 'expiry'],
        ['/trace/recalls.php', 'Recalls', 'siren', 'trace.view', ['/trace/recall']],
        ['/trace/warranty.php', 'Warranty', 'shield-check', 'trace.view', ['/trace/warranty.php']],
    ],
    'Purchasing' => [
        ['/purchasing/orders.php', 'Purchase Orders', 'file-text', 'purchase.view', ['/purchasing/orders.php', '/purchasing/order_'], 'po'],
        ['/purchasing/receipts.php', 'Goods Received', 'package-plus', 'purchase.view', ['/purchasing/receipts.php', '/purchasing/receive.php', '/purchasing/grn_']],
        ['/purchasing/bills.php', 'Supplier Invoices', 'file-spreadsheet', 'purchase.view', ['/purchasing/bill']],
        ['/purchasing/payments.php', 'Supplier Payments', 'banknote', 'purchase.view', ['/purchasing/payment', '/purchasing/returns.php']],
        ['/purchasing/suppliers.php', 'Suppliers', 'building-2', 'suppliers.view', ['/purchasing/supplier']],
    ],
    'Catalogue' => [
        ['/catalog/products.php', 'Products', 'package', 'catalog.view', ['/catalog/products.php', '/catalog/product_', '/catalog/categories.php']],
        ['/catalog/pricing.php', 'Price Lists', 'tags', 'catalog.view', ['/catalog/pricing.php']],
    ],
    'Finance' => [
        ['/finance/expenses.php', 'Expenses', 'receipt-text', 'finance.view', ['/finance/expenses.php']],
        ['/finance/cashbook.php', 'Cash & Bank', 'landmark', 'finance.view', ['/finance/cashbook.php']],
        ['/finance/receivables.php', 'Receivables', 'hand-coins', 'finance.view', ['/finance/receivables.php']],
        ['/finance/payables.php', 'Payables', 'file-clock', 'finance.view', ['/finance/payables.php']],
        ['/finance/ledger.php', 'General Ledger', 'book-open', 'finance.view', ['/finance/ledger.php', '/finance/journal.php']],
        ['/finance/statements.php', 'Financial Statements', 'scale', 'reports.finance', ['/finance/statements.php']],
    ],
    'Insights' => [
        ['/reps/index.php', 'Sales Reps', 'users', 'reps.view', ['/reps/']],
        ['/reports/index.php', 'Reports', 'bar-chart-3', 'reports.view', ['/reports/']],
    ],
    'Administration' => [
        ['/users.php', 'Staff & Roles', 'user-cog', 'users.manage', ['/users.php']],
        ['/audit.php', 'Audit Log', 'shield-check', 'audit.view', ['/audit.php']],
        ['/settings.php', 'Settings', 'settings', 'settings.manage', ['/settings.php']],
    ],
];
?>
<aside class="sidebar" id="sidebar">
  <div class="brand-block">
    <div class="brutal-logo"><span class="mark">CX</span><span class="word"><?= e(APP_NAME) ?></span></div>
    <span class="brand-sub">Trading &amp; Distribution</span>
  </div>

  <?php foreach ($sections as $heading => $items):
      $visible = array_filter($items, fn($it) => can_any((array) $it[3]));
      if (!$visible) continue; ?>
    <div class="nav-section">
      <div class="nav-heading"><?= e($heading) ?></div>
      <nav class="nav-group">
        <?php foreach ($visible as $it):
            $active = false;
            foreach ($it[4] as $match) { if (str_starts_with($rel, $match)) { $active = true; break; } }
            $badge = isset($it[5]) ? (int) ($navBadges[$it[5]] ?? 0) : 0; ?>
          <a class="nav-item <?= $active ? 'active' : '' ?>" href="<?= e(app_url($it[0])) ?>">
            <?= icon($it[2], 18) ?><span class="nav-label"><?= e($it[1]) ?></span>
            <?php if ($badge > 0): ?><span class="nav-count" title="Needs attention"><?= $badge ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>
  <?php endforeach; ?>

  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="avatar"><?= e(strtoupper(substr($user['full_name'] ?? '?', 0, 1))) ?></div>
      <div class="who">
        <div class="name"><?= e($user['full_name'] ?? '') ?></div>
        <div class="role"><?= e(role_label($user['role'] ?? '')) ?></div>
      </div>
    </div>
  </div>
</aside>
<div class="sidebar-backdrop" data-nav-close></div>
<?php })($navBadges ?? []); ?>
