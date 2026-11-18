<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('invoices.view');

$pdo = db();
$me = (int) current_user()['id']; $own = !can('sales.view_all') && !can('invoices.create');
$period = resolve_report_period('this_month');
$cust = input_int($_GET, 'customer'); $method = input($_GET, 'method'); $rep = input_int($_GET, 'rep'); $hl = input_int($_GET, 'highlight');
$page = max(1, input_int($_GET, 'page', 1)); $perPage = 40;
$where = 'cp.pay_date BETWEEN :f AND :t'; $params = [':f' => $period['from'], ':t' => $period['to']];
if ($cust) $where = 'cp.customer_id = :cu AND ' . $where; if ($cust) $params[':cu'] = $cust;
if ($own) { $where .= ' AND cp.rep_id = :me'; $params[':me'] = $me; }
if (isset(money_methods()[$method])) { $where .= ' AND cp.method = :m'; $params[':m'] = $method; }
if ($rep && !$own) { $where .= ' AND cp.rep_id = :rp'; $params[':rp'] = $rep; }
$from = "FROM customer_payments cp JOIN customers c ON c.id = cp.customer_id LEFT JOIN users u ON u.id = cp.rep_id WHERE $where";
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
$sm = $pdo->prepare("SELECT COALESCE(SUM(cp.amount),0) a, COALESCE(SUM(cp.amount - cp.allocated),0) u $from"); $sm->execute($params); $sum = $sm->fetch();
$by = $pdo->prepare("SELECT cp.method, SUM(cp.amount) a $from GROUP BY cp.method ORDER BY a DESC"); $by->execute($params); $byMethod = $by->fetchAll();
$st = $pdo->prepare("SELECT cp.*, c.business_name, u.full_name AS rep, (SELECT GROUP_CONCAT(si.inv_no ORDER BY si.id SEPARATOR ', ') FROM customer_payment_allocations a JOIN sales_invoices si ON si.id = a.invoice_id WHERE a.payment_id = cp.id) AS invoices $from ORDER BY cp.pay_date DESC, cp.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params); $rows = $st->fetchAll();
$customers = $pdo->query('SELECT id, business_name FROM customers ORDER BY business_name')->fetchAll();
$reps = $pdo->query("SELECT id, full_name FROM users WHERE role = 'sales_rep' ORDER BY full_name")->fetchAll();
// Payments with money not yet applied
$free = $pdo->query('SELECT COUNT(*) FROM customer_payments WHERE amount - allocated > 0.004')->fetchColumn();

if (is_post() && can('payments.receive')) {
    verify_csrf();
    try { $pdo->beginTransaction(); $a = payment_allocate($pdo, input_int($_POST, 'payment_id'), null); $pdo->commit(); log_activity('customer_payment', 'Applied ' . format_money($a) . ' of advance payment #' . input_int($_POST, 'payment_id') . ' to open invoices', 'customer_payment', input_int($_POST, 'payment_id')); flash('success', $a > 0 ? format_money($a) . ' applied to open invoices.' : 'No open invoices to apply it to.'); }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash('error', $e->getMessage()); }
    redirect('/sales/payments.php' . query_with([]));
}
$pageTitle = 'Collections';
require __DIR__ . '/../includes/header.php';
page_header('Collections', 'Every payment received from customers and which invoices it settled.', can('payments.receive') ? '<a class="btn btn-primary" href="' . e(app_url('/sales/payment_form.php')) . '">' . icon('plus', 16) . ' Record payment</a>' : '');
nav_sales();
?>
<div class="card tight mb-4"><?php render_period_filter($period, ['customer' => $cust ?: '', 'method' => $method, 'rep' => $rep ?: '']); ?></div>
<div class="grid grid-4 mb-4">
  <?= kpi('Collected', format_money_short($sum['a']), 'hand-coins', 'green', e($period['label'])) ?>
  <?= kpi('Receipts', number_format($total), 'receipt', 'blue', 'Payments recorded') ?>
  <?= kpi('Advance on account', format_money_short($sum['u']), 'wallet', $sum['u'] > 0 ? 'amber' : 'gray', 'Received but not applied') ?>
  <div class="kpi-card"><div class="kpi-top"><span class="kpi-label">By method</span></div><?php foreach ($byMethod as $m): ?><div class="summary-row" style="margin:2px 0;"><span><?= e(method_label($m['method'])) ?></span><strong><?= format_money_short($m['a']) ?></strong></div><?php endforeach; if (!$byMethod) echo '<div class="text-muted text-sm">—</div>'; ?></div>
</div>
<div class="card flush">
  <div style="padding:16px 16px 0;"><form method="get" class="filter-bar">
    <?php foreach (['preset' => $period['preset'] ?: 'custom', 'from' => $period['from'], 'to' => $period['to']] as $k => $v) echo '<input type="hidden" name="' . $k . '" value="' . e($v) . '">'; ?>
    <select class="form-control" name="customer" onchange="this.form.submit()"><option value="">All customers</option><?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $cust === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['business_name']) ?></option><?php endforeach; ?></select>
    <select class="form-control" name="method" onchange="this.form.submit()"><option value="">All methods</option><?php foreach (money_methods() as $k => $l): ?><option value="<?= e($k) ?>" <?= $method === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <?php if (!$own): ?><select class="form-control" name="rep" onchange="this.form.submit()"><option value="">All reps</option><?php foreach ($reps as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $rep === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['full_name']) ?></option><?php endforeach; ?></select><?php endif; ?>
  </form></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Receipt</th><th>Date</th><th>Customer</th><th>Method</th><th>Applied to</th><th>Collected by</th><th class="num">Amount</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): $adv = (float) $p['amount'] - (float) $p['allocated']; ?>
      <tr class="<?= $hl === (int) $p['id'] ? 'row-warn' : '' ?>">
        <td class="strong mono"><a href="<?= e(app_url('/sales/print.php?type=receipt&id=' . $p['id'])) ?>" target="_blank"><?= e($p['receipt_no']) ?></a></td>
        <td class="text-secondary nowrap"><?= e(fmt_date($p['pay_date'])) ?></td>
        <td><a href="<?= e(app_url('/sales/customer_view.php?id=' . $p['customer_id'])) ?>"><?= e($p['business_name']) ?></a></td>
        <td class="text-secondary"><?= e(method_label($p['method'])) ?><?= $p['reference'] ? '<div class="cell-sub mono">' . e($p['reference']) . '</div>' : '' ?></td>
        <td class="text-sm"><?= e($p['invoices'] ?? '') ?><?= $adv > 0.004 ? '<div class="cell-sub text-warning">' . format_money($adv) . ' unapplied advance</div>' : '' ?></td>
        <td class="text-secondary"><?= e($p['rep'] ?? '—') ?></td>
        <td class="num strong"><?= format_money($p['amount']) ?></td>
        <td class="actions"><?php if ($adv > 0.004 && can('payments.receive')): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>"><button class="btn btn-secondary btn-sm" type="submit">Apply to invoices</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows) echo empty_state('hand-coins', 'No payments in this period'); ?></div>
  <?php render_pagination($page, $perPage, $total); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
