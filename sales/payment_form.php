<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('payments.receive');

$pdo = db();
$me = (int) current_user()['id']; $own = !can('sales.view_all') && !can('invoices.create');
$custId = input_int($_GET, 'customer_id'); $focus = input_int($_GET, 'invoice_id');
$customers = $pdo->query('SELECT id, business_name FROM customers WHERE is_active = 1' . ($own ? ' AND rep_id = ' . $me : '') . ' ORDER BY business_name')->fetchAll();
$errors = [];

if (is_post()) {
    verify_csrf();
    $custId = input_int($_POST, 'customer_id');
    $amount = round2(input_float($_POST, 'amount'));
    $mode = input($_POST, 'alloc_mode') === 'manual' ? 'manual' : 'auto';
    $map = null;
    if ($mode === 'manual') { $map = []; foreach (($_POST['alloc'] ?? []) as $iid => $a) { if ((float) $a > 0) $map[(int) $iid] = (float) $a; } }
    try {
        if (!$custId) throw new RuntimeException('Select the customer.');
        $pid = customer_payment_record($custId, $amount, input($_POST, 'method', 'cash'), input_date($_POST, 'pay_date', date('Y-m-d')), input($_POST, 'reference'), null, input($_POST, 'notes'), $map);
        $p = $pdo->query('SELECT receipt_no, allocated, amount FROM customer_payments WHERE id = ' . $pid)->fetch();
        flash('success', 'Payment ' . $p['receipt_no'] . ' recorded.' . ((float) $p['amount'] - (float) $p['allocated'] > 0.004 ? ' ' . format_money($p['amount'] - $p['allocated']) . ' is held as an advance on the customer’s account.' : ' It was applied to the customer’s open invoices.'));
        redirect('/sales/payments.php?highlight=' . $pid);
    } catch (RuntimeException $e) { $errors[] = $e->getMessage(); }
}
$open = $custId ? ar_open_invoices($custId) : [];
$ar = $custId ? customer_ar($custId) : null;
$focusAmt = 0; foreach ($open as $o) if ((int) $o['id'] === $focus) $focusAmt = (float) $o['outstanding'];
$pageTitle = 'Record payment';
require __DIR__ . '/../includes/header.php';
page_header('Record customer payment', 'Money received from a customer is applied to their oldest invoices first — or choose exactly which invoices it settles.', '<a class="btn btn-secondary" href="' . e(app_url('/sales/payments.php')) . '">Cancel</a>', [['Collections', '/sales/payments.php'], ['Record payment', null]]);
foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<form method="get" class="card tight mb-4"><div class="form-row" style="align-items:end;"><div class="form-group mb-0" style="flex:3"><label>Customer</label><select class="form-control" name="customer_id" onchange="this.form.submit()"><option value="">Select customer…</option><?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $custId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['business_name']) ?></option><?php endforeach; ?></select></div></div></form>
<?php if ($custId): ?>
<form method="post" id="payForm"><?= csrf_field() ?><input type="hidden" name="customer_id" value="<?= $custId ?>">
<div class="layout-2-1">
  <div class="card flush">
    <div class="card-head"><div><h3>Open invoices</h3><div class="sub">Outstanding: <strong><?= format_money($ar['outstanding']) ?></strong><?= $ar['unallocated'] > 0 ? ' · advance held ' . format_money($ar['unallocated']) : '' ?></div></div>
      <div class="segmented"><label><input type="radio" name="alloc_mode" value="auto" checked><span>Oldest first (auto)</span></label><label><input type="radio" name="alloc_mode" value="manual"><span>I’ll choose</span></label></div></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Invoice</th><th>Due</th><th class="num">Outstanding</th><th style="width:150px;" class="manual-col">Apply</th></tr></thead>
      <tbody><?php foreach ($open as $o): ?><tr><td class="strong"><a href="<?= e(app_url('/sales/invoice_view.php?id=' . $o['id'])) ?>"><?= e($o['inv_no']) ?></a></td><td class="nowrap <?= $o['days_overdue'] > 0 ? 'text-error fw-700' : 'text-secondary' ?>"><?= e(fmt_date($o['due_date'])) ?><?= $o['days_overdue'] > 0 ? ' · ' . (int) $o['days_overdue'] . 'd late' : '' ?></td><td class="num strong"><?= format_money($o['outstanding']) ?></td><td class="manual-col"><input class="form-control num-input alloc" type="number" step="0.01" min="0" max="<?= e((string) round($o['outstanding'], 2)) ?>" name="alloc[<?= (int) $o['id'] ?>]" data-due="<?= e((string) round($o['outstanding'], 2)) ?>" value="<?= (int) $o['id'] === $focus ? e((string) round($o['outstanding'], 2)) : '' ?>"></td></tr><?php endforeach; ?></tbody>
    </table><?php if (!$open) echo empty_state('check-circle-2', 'No open invoices', 'Anything received now is held as an advance on the customer’s account.'); ?></div>
  </div>
  <div class="card">
    <h3 class="mb-3">Payment</h3>
    <div class="form-group"><label>Amount received</label><input class="form-control num-input" style="font-size:18px;font-weight:700;" type="number" step="0.01" min="0.01" name="amount" id="amount" value="<?= $focusAmt ? e((string) round($focusAmt, 2)) : '' ?>" required autofocus></div>
    <div class="form-row"><div class="form-group"><label>Method</label><select class="form-control" name="method"><?php foreach (money_methods() as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === 'bank_transfer' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Date</label><input class="form-control" type="date" name="pay_date" value="<?= date('Y-m-d') ?>"></div></div>
    <div class="form-group"><label>Reference <span class="label-opt">(cheque / transfer no.)</span></label><input class="form-control" type="text" name="reference" maxlength="60"></div>
    <div class="form-group"><label>Notes <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="notes" maxlength="255"></div>
    <div class="callout" id="allocInfo"></div>
    <button class="btn btn-primary btn-lg btn-block mt-3" type="submit"><?= icon('check', 16) ?> Record payment</button>
  </div>
</div></form>
<script>
(function () {
  const amount = document.getElementById('amount'), money = CalimaxUI.money, info = document.getElementById('allocInfo');
  const mode = () => document.querySelector('input[name=alloc_mode]:checked').value;
  function sync() {
    const man = mode() === 'manual'; document.querySelectorAll('.manual-col').forEach((e) => { e.style.display = man ? '' : 'none'; });
    const open = [...document.querySelectorAll('.alloc')].reduce((s, i) => s + (+i.dataset.due || 0), 0), amt = +amount.value || 0;
    if (man) { const sum = [...document.querySelectorAll('.alloc')].reduce((s, i) => s + (+i.value || 0), 0); info.innerHTML = 'Applied to invoices: <strong>' + money(sum) + '</strong> of ' + money(amt) + (sum < amt ? ' — the rest (' + money(amt - sum) + ') is held as an advance.' : '') + (sum > amt ? '<br><span class="text-error">Allocations exceed the payment.</span>' : ''); }
    else info.innerHTML = amt > open ? 'Settles all open invoices (' + money(open) + ') and leaves <strong>' + money(amt - open) + '</strong> as an advance.' : 'Applied to the oldest invoices first.';
  }
  document.addEventListener('input', sync); document.querySelectorAll('input[name=alloc_mode]').forEach((r) => r.addEventListener('change', () => { if (mode() === 'manual') { let left = +amount.value || 0; document.querySelectorAll('.alloc').forEach((i) => { const a = Math.min(left, +i.dataset.due); i.value = a > 0 ? a.toFixed(2) : ''; left -= a; }); } sync(); }));
  if (<?= $focus ? 'true' : 'false' ?>) { document.querySelector('input[name=alloc_mode][value=manual]').checked = true; }
  sync();
})();
</script>
<?php else: ?><div class="card"><?= empty_state('hand-coins', 'Choose a customer', 'Select who paid to see their open invoices.') ?></div><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
