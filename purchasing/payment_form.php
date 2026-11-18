<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('supplier.pay');

$pdo = db();
$supId = input_int($_GET, 'supplier_id') ?: input_int($_POST, 'supplier_id'); $focus = input_int($_GET, 'bill_id');
$suppliers = $pdo->query('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name')->fetchAll();
$errors = [];
if (is_post() && $supId) {
    verify_csrf();
    $map = null;
    if (input($_POST, 'alloc_mode') === 'manual') { $map = []; foreach (($_POST['alloc'] ?? []) as $bid => $a) if ((float) $a > 0) $map[(int) $bid] = (float) $a; }
    try {
        $pid = supplier_payment_record($supId, round2(input_float($_POST, 'amount')), input($_POST, 'method', 'bank_transfer'), input_date($_POST, 'pay_date', date('Y-m-d')), input($_POST, 'reference'), input($_POST, 'notes'), $map);
        flash('success', 'Supplier payment recorded. The payable has been reduced.'); redirect('/purchasing/payments.php?highlight=' . $pid);
    } catch (RuntimeException $e) { $errors[] = $e->getMessage(); }
}
$open = $supId ? ap_open_bills($supId) : [];
$bal = $supId ? supplier_balance($supId) : 0;
$focusAmt = 0; foreach ($open as $o) if ((int) $o['id'] === $focus) $focusAmt = (float) $o['outstanding'];
$pageTitle = 'Pay supplier';
require __DIR__ . '/../includes/header.php';
page_header('Pay a supplier', 'Payments reduce what you owe. Apply them to the oldest invoices first, or choose exactly which invoices.', '<a class="btn btn-secondary" href="' . e(app_url('/purchasing/payments.php')) . '">Cancel</a>', [['Payments', '/purchasing/payments.php'], ['Pay supplier', null]]);
foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<form method="get" class="card tight mb-4"><div class="form-group mb-0"><label>Supplier</label><select class="form-control" name="supplier_id" onchange="this.form.submit()" style="max-width:420px;"><option value="">Select supplier…</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $supId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div></form>
<?php if ($supId): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="supplier_id" value="<?= $supId ?>">
<div class="layout-2-1">
  <div class="card flush"><div class="card-head"><div><h3>Unpaid invoices</h3><div class="sub">We owe this supplier <strong><?= format_money($bal) ?></strong></div></div><div class="segmented"><label><input type="radio" name="alloc_mode" value="auto" checked><span>Oldest due first</span></label><label><input type="radio" name="alloc_mode" value="manual" <?= $focus ? 'checked' : '' ?>><span>I’ll choose</span></label></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Invoice</th><th>Due</th><th class="num">Outstanding</th><th class="manual-col" style="width:150px;">Apply</th></tr></thead><tbody>
    <?php foreach ($open as $o): ?><tr><td class="strong"><a href="<?= e(app_url('/purchasing/bill_view.php?id=' . $o['id'])) ?>" target="_blank"><?= e($o['bill_no']) ?></a><div class="cell-sub"><?= e($o['supplier_ref']) ?></div></td><td class="nowrap <?= $o['days_overdue'] > 0 ? 'text-error fw-700' : 'text-secondary' ?>"><?= e(fmt_date($o['due_date'])) ?><?= $o['days_overdue'] > 0 ? ' · ' . (int) $o['days_overdue'] . 'd late' : '' ?></td><td class="num strong"><?= format_money($o['outstanding']) ?></td><td class="manual-col"><input class="form-control num-input alloc" type="number" step="0.01" min="0" max="<?= e((string) round($o['outstanding'], 2)) ?>" name="alloc[<?= (int) $o['id'] ?>]" data-due="<?= e((string) round($o['outstanding'], 2)) ?>" value="<?= (int) $o['id'] === $focus ? e((string) round($o['outstanding'], 2)) : '' ?>"></td></tr><?php endforeach; ?></tbody></table>
    <?php if (!$open) echo empty_state('check-circle-2', 'Nothing owed', 'There are no unpaid invoices for this supplier.'); ?></div></div>
  <div class="card"><h3 class="mb-3">Payment</h3>
    <div class="form-group"><label>Amount paid</label><input class="form-control num-input" style="font-size:18px;font-weight:700;" type="number" step="0.01" min="0.01" name="amount" id="amount" value="<?= $focusAmt ? e((string) round($focusAmt, 2)) : '' ?>" required></div>
    <div class="form-row"><div class="form-group"><label>Method</label><select class="form-control" name="method"><?php foreach (money_methods() as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === 'bank_transfer' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Date</label><input class="form-control" type="date" name="pay_date" value="<?= date('Y-m-d') ?>"></div></div>
    <div class="form-group"><label>Reference <span class="label-opt">(cheque / transfer no.)</span></label><input class="form-control" type="text" name="reference" maxlength="60"></div>
    <div class="form-group"><label>Notes</label><input class="form-control" type="text" name="notes" maxlength="255"></div>
    <div class="callout" id="info"></div><button class="btn btn-primary btn-lg btn-block mt-3" type="submit"><?= icon('check', 16) ?> Record payment</button></div>
</div></form>
<script>
(function () { const amount = document.getElementById('amount'), info = document.getElementById('info'), money = CalimaxUI.money, mode = () => document.querySelector('input[name=alloc_mode]:checked').value;
  function sync() { const man = mode() === 'manual'; document.querySelectorAll('.manual-col').forEach((e) => { e.style.display = man ? '' : 'none'; }); const amt = +amount.value || 0, open = [...document.querySelectorAll('.alloc')].reduce((s, i) => s + (+i.dataset.due || 0), 0);
    if (man) { const sum = [...document.querySelectorAll('.alloc')].reduce((s, i) => s + (+i.value || 0), 0); info.innerHTML = 'Applied: <strong>' + money(sum) + '</strong> of ' + money(amt) + (sum > amt ? '<br><span class="text-error">Allocations exceed the payment.</span>' : ''); } else info.innerHTML = amt > open ? 'Pays all open invoices (' + money(open) + ') — <strong>' + money(amt - open) + '</strong> will be held as an advance with the supplier.' : 'Applied to the oldest invoices first.'; }
  document.addEventListener('input', sync); document.querySelectorAll('input[name=alloc_mode]').forEach((r) => r.addEventListener('change', sync)); sync(); })();
</script>
<?php else: ?><div class="card"><?= empty_state('banknote', 'Choose a supplier', 'Select who you are paying.') ?></div><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
