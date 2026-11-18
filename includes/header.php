<?php
/**
 * Shared page header. Set $pageTitle (and optionally $bodyClass) before including.
 * Must be included AFTER bootstrap.php and the page's permission guard.
 */
$user = current_user();
$pageTitle = $pageTitle ?? APP_NAME;
$pdo = db();
expire_batches();

// ---- Live alerts & approval counts (cheap aggregate queries) ----------
$notifications = [];
$navBadges = ['orders' => 0, 'returns' => 0, 'pick' => 0, 'stock' => 0, 'expiry' => 0, 'po' => 0];
$cnt = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();

if (can('sales.approve')) {
    $n = $cnt("SELECT COUNT(*) FROM sales_orders WHERE status = 'pending_approval'");
    $navBadges['orders'] = $n;
    if ($n) $notifications[] = ['shield-alert', 'amber', $n . ' sales order(s) awaiting approval', 'Credit or discount exceptions need a decision', '/sales/orders.php?status=pending_approval'];
}
if (can('returns.approve')) {
    $n = $cnt("SELECT COUNT(*) FROM sales_returns WHERE status = 'requested'");
    $navBadges['returns'] += $n;
    if ($n) $notifications[] = ['undo-2', 'blue', $n . ' customer return(s) awaiting approval', 'Approve or reject the return request', '/sales/returns.php?status=requested'];
}
if (can('returns.inspect')) {
    $n = $cnt("SELECT COUNT(*) FROM sales_returns WHERE status IN ('approved','received')");
    $navBadges['returns'] += $n;
    if ($n) $notifications[] = ['search-check', 'purple', $n . ' return(s) to receive or inspect', 'Returned goods must be inspected before they can be sold again', '/sales/returns.php?status=received'];
}
if (can('delivery.fulfil')) {
    $n = $cnt("SELECT COUNT(*) FROM deliveries WHERE status IN ('pending_pick','picked','packed')");
    $navBadges['pick'] = $n;
    $c2 = $cnt("SELECT COUNT(*) FROM sales_orders so WHERE so.status IN ('confirmed','partially_fulfilled') AND NOT EXISTS (SELECT 1 FROM deliveries d WHERE d.so_id = so.id AND d.status IN ('pending_pick','picked','packed'))");
    if ($n || $c2) $notifications[] = ['package-check', 'blue', ($n + $c2) . ' order(s) waiting for the warehouse', 'Pick, pack and dispatch confirmed orders', '/warehouse/fulfilment.php'];
}
if (can('inventory.approve')) {
    $n = $cnt("SELECT COUNT(*) FROM stock_adjustments WHERE status = 'pending'") + $cnt("SELECT COUNT(*) FROM stock_counts WHERE status = 'submitted'");
    $navBadges['stock'] = $n;
    if ($n) $notifications[] = ['clipboard-check', 'purple', $n . ' stock adjustment / count approval(s)', 'Damaged, expired, lost stock or count variances', '/warehouse/adjustments.php?status=pending'];
}
if (can('purchase.approve')) {
    $n = $cnt("SELECT COUNT(*) FROM purchase_orders WHERE status = 'pending_approval'");
    $navBadges['po'] = $n;
    if ($n) $notifications[] = ['file-check', 'amber', $n . ' purchase order(s) awaiting approval', 'Large orders need sign-off', '/purchasing/orders.php?status=pending_approval'];
}
if (can('trace.view')) {
    $crit = (int) setting('expiry_critical_days', '30');
    $n = count(expiring_batches($crit));
    $navBadges['expiry'] = $n;
    if ($n) $notifications[] = ['calendar-clock', 'red', $n . ' batch(es) expire within ' . $crit . ' days', 'Dispatch or return them before they are wasted', '/trace/expiry.php'];
    $n = $cnt("SELECT COUNT(*) FROM batch_recalls WHERE status = 'open'");
    if ($n) $notifications[] = ['siren', 'red', $n . ' open batch recall(s)', 'Track recovered quantities', '/trace/recalls.php?status=open'];
}
if (can('inventory.view')) {
    $n = count(low_stock_products());
    if ($n) $notifications[] = ['alert-triangle', 'amber', $n . ' product(s) at or below reorder level', 'Raise a purchase order to restock', '/warehouse/stock.php?filter=low'];
}
if (can('invoices.create')) {
    $n = $cnt("SELECT COUNT(*) FROM deliveries d WHERE d.status = 'delivered' AND EXISTS (SELECT 1 FROM delivery_items di WHERE di.delivery_id = d.id AND di.qty_accepted > di.qty_invoiced)");
    if ($n) $notifications[] = ['receipt', 'green', $n . ' delivered order(s) not yet invoiced', 'Create the invoice from the delivery', '/sales/deliveries.php?filter=uninvoiced'];
}
if (can('invoices.view')) {
    $n = $cnt("SELECT COUNT(*) FROM sales_invoices WHERE status IN ('unpaid','partial') AND due_date < CURDATE()");
    if ($n) $notifications[] = ['hand-coins', 'red', $n . ' overdue customer invoice(s)', 'Follow up on collections', '/sales/invoices.php?filter=overdue'];
}
if (can('purchase.receive')) {
    $n = $cnt("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('approved','sent','partially_received') AND expected_date IS NOT NULL AND expected_date <= CURDATE()");
    if ($n) $notifications[] = ['truck', 'amber', $n . ' purchase order(s) due for delivery', 'Receive goods when they arrive', '/purchasing/orders.php?status=open'];
}
$notifCount = count($notifications);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="<?= e(app_url('/assets/vendor/lucide.min.js')) ?>" defer></script>
<link rel="stylesheet" href="<?= e(app_url('/assets/css/style.css')) ?>?v=6">
<link rel="stylesheet" href="<?= e(app_url('/assets/css/erp.css')) ?>?v=1">
<script>window.APP = { url: <?= json_encode(app_url('')) ?>, csrf: <?= json_encode(csrf_token()) ?>, currency: <?= json_encode(setting('currency_symbol', 'Rs.')) ?> };</script>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<div class="app-shell">
<?php require __DIR__ . '/sidebar.php'; ?>
<div class="main-col">
<header class="topbar no-print">
  <button class="icon-btn menu-toggle" type="button" data-nav-toggle aria-label="Open menu"><?= icon('menu', 18) ?></button>
  <div class="topbar-search" id="globalSearch">
    <label class="search-box">
      <?= icon('search', 16) ?>
      <input type="search" id="globalSearchInput" placeholder="Search products, customers, orders, invoices, serial or batch numbers…" autocomplete="off" aria-label="Global search">
      <span class="kbd hide-sm">/</span>
    </label>
    <div class="search-results" id="globalSearchResults" hidden></div>
  </div>
  <div class="topbar-actions">
    <?php if (can('sales.create') && !str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/sales/order_form.php')): ?>
      <a class="btn btn-primary btn-sm hide-sm" href="<?= e(app_url('/sales/order_form.php')) ?>"><?= icon('plus', 15) ?> New Order</a>
    <?php endif; ?>
    <div class="dropdown" data-dropdown>
      <button class="icon-btn" type="button" data-dropdown-toggle aria-label="Alerts" title="Alerts">
        <?= icon('bell', 17) ?><?php if ($notifCount): ?><span class="dot-count"><?= $notifCount ?></span><?php endif; ?>
      </button>
      <div class="dropdown-panel wide" hidden>
        <div class="dropdown-head"><span>Alerts</span><span class="text-muted text-sm"><?= $notifCount ?> active</span></div>
        <?php if (!$notifications): ?>
          <div class="search-empty"><?= icon('check-circle-2', 20) ?><div class="mt-2">All clear — nothing needs your attention.</div></div>
        <?php endif; ?>
        <?php foreach ($notifications as $__n): ?>
          <a class="notif-item" href="<?= e(app_url($__n[4])) ?>">
            <span class="icon-tile sm <?= e($__n[1]) ?>"><?= icon($__n[0], 15) ?></span>
            <div><div class="nt"><?= e($__n[2]) ?></div><div class="ns"><?= e($__n[3]) ?></div></div>
          </a>
        <?php endforeach; unset($__n); ?>
      </div>
    </div>
    <div class="dropdown" data-dropdown>
      <button class="icon-btn" type="button" data-dropdown-toggle aria-label="Account" style="width:auto;padding:2px 10px 2px 2px;gap:8px;">
        <span class="avatar" style="width:30px;height:30px;font-size:12px;"><?= e(strtoupper(substr($user['full_name'] ?? '?', 0, 1))) ?></span>
        <span class="hide-sm" style="font-size:13px;font-weight:600;color:var(--dark);"><?= e(explode(' ', $user['full_name'] ?? '')[0]) ?></span>
        <?= icon('chevron-down', 14) ?>
      </button>
      <div class="dropdown-panel" hidden>
        <div class="dropdown-head" style="display:block;">
          <div><?= e($user['full_name'] ?? '') ?></div>
          <div class="text-muted text-sm" style="font-weight:500;"><?= e(role_label($user['role'] ?? '')) ?> · @<?= e($user['username'] ?? '') ?></div>
        </div>
        <?php if (can('settings.manage')): ?><a class="dropdown-item" href="<?= e(app_url('/settings.php')) ?>"><?= icon('settings', 15) ?> Settings</a><?php endif; ?>
        <?php if (can('users.manage')): ?><a class="dropdown-item" href="<?= e(app_url('/users.php')) ?>"><?= icon('users', 15) ?> Staff & roles</a><?php endif; ?>
        <div class="dropdown-sep"></div>
        <a class="dropdown-item" href="<?= e(app_url('/logout.php')) ?>"><?= icon('log-out', 15) ?> Sign out</a>
      </div>
    </div>
  </div>
</header>
<main class="main-content">
<?php $__flashes = get_flashes(); if ($__flashes): ?>
<div class="flash-stack" role="status">
<?php foreach ($__flashes as $__f): ?>
  <div class="alert alert-<?= e($__f['type']) ?>" data-autodismiss><?= icon($__f['type'] === 'success' ? 'check-circle-2' : ($__f['type'] === 'error' ? 'alert-circle' : 'info'), 18) ?><span><?= e($__f['message']) ?></span><button class="alert-close" type="button" aria-label="Dismiss">&times;</button></div>
<?php endforeach; unset($__f, $__flashes); ?>
</div>
<?php endif; ?>
<script src="<?= e(app_url('/assets/js/app.js')) ?>?v=4"></script>
<?php foreach ($pageScripts ?? [] as $__src): ?><script src="<?= e($__src) ?>"></script><?php endforeach; ?>
