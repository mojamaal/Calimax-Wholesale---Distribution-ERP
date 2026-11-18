<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('customers.view');

$pdo = db();
$id = input_int($_GET, 'id');
$s = $pdo->prepare('SELECT c.*, cc.name AS category, u.full_name AS rep_name, t.name AS territory FROM customers c LEFT JOIN customer_categories cc ON cc.id = c.category_id LEFT JOIN users u ON u.id = c.rep_id LEFT JOIN territories t ON t.id = c.territory_id WHERE c.id = :id');
$s->execute([':id' => $id]); $c = $s->fetch();
if (!$c || (!can('sales.view_all') && (int) $c['rep_id'] !== (int) current_user()['id'])) { flash('error', 'Customer not found.'); redirect('/sales/customers.php'); }

if (is_post() && can('pricing.manage')) {
    verify_csrf();
    $action = input($_POST, 'action'); $pid = input_int($_POST, 'product_id');
    try {
        $pn = $pdo->prepare('SELECT name, default_price FROM products WHERE id = :id'); $pn->execute([':id' => $pid]); $prod = $pn->fetch();
        if (!$prod) throw new RuntimeException('Choose a product.');
        $old = $pdo->prepare('SELECT price FROM customer_prices WHERE customer_id = :c AND product_id = :p'); $old->execute([':c' => $id, ':p' => $pid]); $oldP = $old->fetchColumn();
        if ($action === 'set_price') {
            $price = input_float($_POST, 'price', -1);
            if ($price <= 0) throw new RuntimeException('Enter the negotiated price per base unit.');
            $pdo->prepare('INSERT INTO customer_prices (customer_id, product_id, price) VALUES (:c,:p,:pr) ON DUPLICATE KEY UPDATE price = :pr2')->execute([':c' => $id, ':p' => $pid, ':pr' => $price, ':pr2' => $price]);
            log_change('customer_price_update', $prod['name'] . ' price', $oldP === false ? 'list price' : format_money($oldP), format_money($price), 'customer', $id, $c['business_name']);
            flash('success', 'Negotiated price saved.');
        } elseif ($action === 'del_price') {
            $pdo->prepare('DELETE FROM customer_prices WHERE customer_id = :c AND product_id = :p')->execute([':c' => $id, ':p' => $pid]);
            log_change('customer_price_update', $prod['name'] . ' price', $oldP === false ? null : format_money($oldP), 'list price', 'customer', $id, $c['business_name']);
            flash('success', 'Negotiated price removed.');
        }
    } catch (RuntimeException $e) { flash('error', $e->getMessage()); }
    redirect('/sales/customer_view.php?id=' . $id);
}

$ar = customer_ar($id);
$chk = credit_check($id, 0.0, 'credit');
$open = ar_open_invoices($id);
$ageing = array_fill_keys(array_keys(ageing_labels()), 0.0);
foreach ($open as $o) $ageing[$o['bucket']] += (float) $o['outstanding'];
$ageTotal = array_sum($ageing) ?: 1;
$orders = $pdo->query("SELECT so.*, u.full_name AS rep FROM sales_orders so LEFT JOIN users u ON u.id = so.rep_id WHERE so.customer_id = $id ORDER BY so.id DESC LIMIT 8")->fetchAll();
$pays = $pdo->query("SELECT * FROM customer_payments WHERE customer_id = $id ORDER BY pay_date DESC, id DESC LIMIT 6")->fetchAll();
$life = $pdo->query("SELECT COALESCE(SUM(total),0) t, COUNT(*) n, MAX(invoice_date) last_inv FROM sales_invoices WHERE customer_id = $id AND status <> 'void'")->fetch();
$neg = $pdo->query("SELECT cp.product_id, cp.price, p.name, p.code, p.base_unit, p.default_price FROM customer_prices cp JOIN products p ON p.id = cp.product_id WHERE cp.customer_id = $id ORDER BY p.name")->fetchAll();
$prods = $pdo->query('SELECT id, name, code, base_unit FROM products WHERE is_active = 1 ORDER BY name')->fetchAll();
$stmtFrom = date('Y-m-d', strtotime('-90 days'));

$pageTitle = $c['business_name'];
require __DIR__ . '/../includes/header.php';
page_header($c['business_name'], '<span class="mono">' . e($c['code']) . '</span> · ' . e($c['category'] ?? 'No category') . ($c['city'] ? ' · ' . e($c['city']) : '') . ($c['is_active'] ? '' : ' · <span class="text-error">Inactive</span>'),
    (can('sales.create') && $c['is_active'] ? '<a class="btn btn-primary" href="' . e(app_url('/sales/order_form.php?customer_id=' . $id)) . '">' . icon('plus', 16) . ' New order</a>' : '')
    . (can('payments.receive') ? '<a class="btn btn-secondary" href="' . e(app_url('/sales/payment_form.php?customer_id=' . $id)) . '">' . icon('hand-coins', 16) . ' Record payment</a>' : '')
    . (can('invoices.view') ? '<a class="btn btn-secondary" href="' . e(app_url('/sales/print.php?type=statement&id=' . $id . '&from=' . $stmtFrom . '&to=' . date('Y-m-d'))) . '" target="_blank">' . icon('file-text', 16) . ' Statement</a>' : '')
    . (can('customers.edit') ? '<a class="btn btn-secondary" href="' . e(app_url('/sales/customer_form.php?id=' . $id)) . '">' . icon('pencil', 16) . ' Edit</a>' : ''), [['Customers', '/sales/customers.php'], [$c['business_name'], null]]);
?>
<div class="layout-1-2 mb-4">
  <div class="grid">
    <div class="credit-box <?= $chk['exceeds'] ? 'over' : '' ?>">
      <div class="text-sm fw-700 mb-2">Credit position</div>
      <div class="cb-row"><span>Credit limit</span><strong><?= $chk['limit'] > 0 ? format_money($chk['limit']) : 'No credit' ?></strong></div>
      <div class="cb-row"><span>Outstanding invoices</span><strong><?= format_money($chk['outstanding']) ?></strong></div>
      <div class="cb-row"><span>Open orders (not invoiced)</span><strong><?= format_money($chk['open_orders']) ?></strong></div>
      <div class="cb-row total"><span>Available credit</span><strong class="<?= $chk['available'] < 0 ? 'text-error' : 'text-success' ?>"><?= $chk['limit'] > 0 ? format_money($chk['available']) : '—' ?></strong></div>
      <?php if ($chk['limit'] > 0): $pct = min(100, max(0, ($chk['outstanding'] + $chk['open_orders']) / $chk['limit'] * 100)); ?><div class="meter mt-3 <?= $pct >= 100 ? 'bad' : ($pct > 80 ? 'warn' : '') ?>"><span style="width:<?= round($pct) ?>%"></span></div><?php endif; ?>
      <?php if ($ar['overdue'] > 0.005): ?><div class="callout bad mt-3"><strong><?= format_money($ar['overdue']) ?> overdue</strong> — oldest invoice is <?= (int) $ar['max_days_overdue'] ?> days late.</div><?php endif; ?>
      <?php if ($ar['unallocated'] > 0.005): ?><div class="callout good mt-3"><?= format_money($ar['unallocated']) ?> received but not yet applied to an invoice.</div><?php endif; ?>
    </div>
    <div class="card">
      <h3 class="mb-3">Account</h3>
      <dl class="desc-list">
        <dt>Contact</dt><dd><?= e($c['contact_person'] ?? '—') ?></dd>
        <dt>Telephone</dt><dd><?= e($c['phone'] ?? '—') ?></dd>
        <dt>Email</dt><dd><?= e($c['email'] ?? '—') ?></dd>
        <dt>Address</dt><dd><?= e($c['address'] ?? '—') ?></dd>
        <dt>Sales rep</dt><dd><?= e($c['rep_name'] ?? '—') ?></dd>
        <dt>Territory</dt><dd><?= e($c['territory'] ?? '—') ?></dd>
        <dt>Payment terms</dt><dd><?= (int) $c['payment_terms_days'] ? (int) $c['payment_terms_days'] . ' days' : 'Cash on delivery' ?></dd>
        <?php if ($c['tax_no']): ?><dt>Tax no.</dt><dd><?= e($c['tax_no']) ?></dd><?php endif; ?>
      </dl>
      <div class="mt-3"><a class="link text-sm" href="<?= e(app_url('/audit.php?entity=customer&entity_id=' . $id)) ?>">Change history →</a></div>
    </div>
  </div>
  <div class="grid">
    <div class="stat-strip" style="margin:0;">
      <div><div class="sl">Lifetime sales</div><div class="sv"><?= format_money_short($life['t']) ?></div></div>
      <div><div class="sl">Invoices</div><div class="sv"><?= (int) $life['n'] ?></div></div>
      <div><div class="sl">Last invoice</div><div class="sv" style="font-size:15px;"><?= e(fmt_date($life['last_inv'])) ?></div></div>
      <div><div class="sl">Balance</div><div class="sv <?= $ar['balance'] > 0 ? '' : 'text-success' ?>"><?= format_money_short($ar['balance']) ?></div></div>
    </div>
    <div class="card">
      <div class="card-head"><h3>Receivable ageing</h3><span class="text-muted text-sm"><?= format_money($ar['outstanding']) ?> outstanding</span></div>
      <div class="age-bar mb-3"><?php foreach (array_keys(ageing_labels()) as $i => $k): if ($ageing[$k] > 0): ?><span class="age-<?= $i ?>" style="width:<?= $ageing[$k] / $ageTotal * 100 ?>%" title="<?= e(ageing_labels()[$k]) ?>"></span><?php endif; endforeach; ?></div>
      <div class="age-legend"><?php foreach (ageing_labels() as $k => $l): $i = array_search($k, array_keys(ageing_labels())); ?><span><i class="age-<?= $i ?>"></i><?= e($l) ?> <strong><?= format_money_short($ageing[$k]) ?></strong></span><?php endforeach; ?></div>
    </div>
    <div class="card flush">
      <div class="card-head"><h3>Open invoices</h3><a class="btn-ghost btn-sm" href="<?= e(app_url('/sales/invoices.php?customer=' . $id)) ?>">All invoices →</a></div>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Invoice</th><th>Due</th><th class="num">Total</th><th class="num">Outstanding</th></tr></thead>
        <tbody><?php foreach ($open as $o): ?><tr><td class="strong"><a href="<?= e(app_url('/sales/invoice_view.php?id=' . $o['id'])) ?>"><?= e($o['inv_no']) ?></a></td><td class="nowrap <?= $o['days_overdue'] > 0 ? 'text-error fw-700' : 'text-secondary' ?>"><?= e(fmt_date($o['due_date'])) ?><?= $o['days_overdue'] > 0 ? ' · ' . (int) $o['days_overdue'] . 'd late' : '' ?></td><td class="num"><?= format_money($o['total']) ?></td><td class="num strong"><?= format_money($o['outstanding']) ?></td></tr><?php endforeach; ?></tbody>
      </table><?php if (!$open) echo empty_state('check-circle-2', 'Nothing outstanding', 'This customer has no unpaid invoices.'); ?></div>
    </div>
  </div>
</div>

<div class="grid grid-2 mb-4">
  <div class="card flush">
    <div class="card-head"><h3>Recent orders</h3><a class="btn-ghost btn-sm" href="<?= e(app_url('/sales/orders.php?customer=' . $id)) ?>">All →</a></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Order</th><th>Date</th><th class="num">Total</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($orders as $o): ?><tr><td class="strong"><a href="<?= e(app_url('/sales/order_view.php?id=' . $o['id'])) ?>"><?= e($o['so_no']) ?></a></td><td class="text-secondary nowrap"><?= e(fmt_date($o['order_date'])) ?></td><td class="num"><?= format_money($o['total']) ?></td><td><?= status_badge($o['status']) ?></td></tr><?php endforeach; ?></tbody>
    </table><?php if (!$orders) echo empty_state('clipboard-list', 'No orders yet'); ?></div>
  </div>
  <div class="card flush">
    <div class="card-head"><h3>Recent payments</h3><a class="btn-ghost btn-sm" href="<?= e(app_url('/sales/payments.php?customer=' . $id)) ?>">All →</a></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th class="num">Amount</th></tr></thead>
      <tbody><?php foreach ($pays as $p): ?><tr><td class="strong mono"><?= e($p['receipt_no']) ?></td><td class="text-secondary nowrap"><?= e(fmt_date($p['pay_date'])) ?></td><td class="text-secondary"><?= e(method_label($p['method'])) ?></td><td class="num strong"><?= format_money($p['amount']) ?></td></tr><?php endforeach; ?></tbody>
    </table><?php if (!$pays) echo empty_state('hand-coins', 'No payments yet'); ?></div>
  </div>
</div>

<div class="card flush">
  <div class="card-head"><div><h3>Negotiated prices</h3><div class="sub">Special prices for this customer override the category price list (per base unit).</div></div><?php if (can('pricing.manage')): ?><button class="btn btn-secondary btn-sm" type="button" data-modal-open="#priceModal"><?= icon('plus', 14) ?> Add price</button><?php endif; ?></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Product</th><th class="num">List price</th><th class="num">Negotiated</th><th></th></tr></thead>
    <tbody><?php foreach ($neg as $n): ?><tr><td class="strong"><?= e($n['name']) ?> <span class="text-muted mono"><?= e($n['code']) ?></span></td><td class="num text-secondary"><?= format_money($n['default_price']) ?> / <?= e($n['base_unit']) ?></td><td class="num strong"><?= format_money($n['price']) ?></td>
      <td class="actions"><?php if (can('pricing.manage')): ?><form method="post" class="inline-form" data-confirm="Remove this negotiated price?"><?= csrf_field() ?><input type="hidden" name="action" value="del_price"><input type="hidden" name="product_id" value="<?= (int) $n['product_id'] ?>"><button class="btn btn-secondary btn-sm" type="submit">Remove</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody>
  </table><?php if (!$neg) echo empty_state('tags', 'No negotiated prices', 'This customer pays the price list for their category.'); ?></div>
</div>
<?php if (can('pricing.manage')): ?>
<div class="modal-overlay" id="priceModal" hidden><div class="modal sm">
  <div class="modal-header"><h3>Negotiated price</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_price">
    <div class="form-group"><label>Product</label><select class="form-control" name="product_id" required><option value="">Select…</option><?php foreach ($prods as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (per <?= e($p['base_unit']) ?>)</option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Price per base unit</label><input class="form-control" type="number" step="0.01" min="0.01" name="price" required></div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Save price</button></div>
  </form></div></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
