<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('supplier_bills.manage');

$pdo = db();
$supId = input_int($_GET, 'supplier_id') ?: input_int($_POST, 'supplier_id'); $focus = input_int($_GET, 'grn_id');
$suppliers = $pdo->query('SELECT id, name, payment_terms_days FROM suppliers WHERE is_active = 1 ORDER BY name')->fetchAll();
$errors = [];
if (is_post() && $supId) {
    verify_csrf();
    try {
        $grnIds = array_map('intval', $_POST['grn'] ?? []);
        $id = supplier_bill_create(['supplier_id' => $supId, 'supplier_ref' => input($_POST, 'supplier_ref'), 'invoice_date' => input_date($_POST, 'invoice_date', date('Y-m-d')), 'subtotal' => input_float($_POST, 'subtotal'), 'tax_total' => input_float($_POST, 'tax_total'), 'po_id' => null, 'notes' => input($_POST, 'notes')], $grnIds);
        $b = $pdo->query('SELECT match_status FROM supplier_invoices WHERE id = ' . $id)->fetchColumn();
        flash($b === 'variance' ? 'info' : 'success', $b === 'variance' ? 'Invoice recorded, but the amount differs from the goods received. Review the variance before paying.' : 'Invoice recorded' . ($b === 'matched' ? ' and matched to the goods received.' : '.'));
        redirect('/purchasing/bill_view.php?id=' . $id);
    } catch (RuntimeException $e) { $errors[] = $e->getMessage(); }
}
$grns = $supId ? $pdo->query("SELECT g.*, po.po_no, w.code AS wh FROM goods_receipts g LEFT JOIN purchase_orders po ON po.id = g.po_id JOIN warehouses w ON w.id = g.warehouse_id WHERE g.supplier_id = $supId AND g.supplier_invoice_id IS NULL ORDER BY g.id")->fetchAll() : [];
$tol = (float) setting('invoice_match_tolerance_pct', '1');
$pageTitle = 'Record supplier invoice';
require __DIR__ . '/../includes/header.php';
page_header('Record supplier invoice', 'Tick the goods receipts this invoice covers. The system compares the invoice with what was actually received.', '<a class="btn btn-secondary" href="' . e(app_url('/purchasing/bills.php')) . '">Cancel</a>', [['Supplier invoices', '/purchasing/bills.php'], ['Record', null]]);
foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<form method="get" class="card tight mb-4"><div class="form-group mb-0"><label>Supplier</label><select class="form-control" name="supplier_id" onchange="this.form.submit()" style="max-width:420px;"><option value="">Select supplier…</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $supId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div></form>
<?php if ($supId): ?>
<form method="post" id="billForm"><?= csrf_field() ?><input type="hidden" name="supplier_id" value="<?= $supId ?>">
<div class="layout-2-1">
  <div class="card flush"><div class="card-head"><div><h3>Goods received, not yet invoiced</h3><div class="sub">Select every delivery the supplier’s invoice includes.</div></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th style="width:40px;"></th><th>Receipt</th><th>Date</th><th>Purchase order</th><th class="num">Goods value</th><th class="num"><?= e(setting('tax_name', 'VAT')) ?></th></tr></thead><tbody>
    <?php foreach ($grns as $g): ?><tr><td><input type="checkbox" class="grn-cb" name="grn[]" value="<?= (int) $g['id'] ?>" data-v="<?= e((string) $g['total_value']) ?>" data-t="<?= e((string) $g['tax_value']) ?>" <?= $focus === (int) $g['id'] || in_array((int) $g['id'], array_map('intval', $_POST['grn'] ?? []), true) ? 'checked' : '' ?>></td><td class="strong"><a href="<?= e(app_url('/purchasing/grn_view.php?id=' . $g['id'])) ?>" target="_blank"><?= e($g['grn_no']) ?></a><div class="cell-sub"><?= e($g['supplier_ref'] ?? '') ?></div></td><td class="text-secondary nowrap"><?= e(fmt_date($g['received_date'])) ?></td><td><?= e($g['po_no'] ?? '—') ?></td><td class="num"><?= format_money($g['total_value']) ?></td><td class="num text-secondary"><?= format_money($g['tax_value']) ?></td></tr><?php endforeach; ?></tbody></table>
    <?php if (!$grns) echo empty_state('package', 'Nothing waiting to be invoiced', 'You can still record an invoice without goods — it will be marked “unmatched”.'); ?></div></div>
  <div class="card">
    <h3 class="mb-3">Invoice details</h3>
    <div class="form-group"><label>Supplier’s invoice number</label><input class="form-control" type="text" name="supplier_ref" value="<?= e($_POST['supplier_ref'] ?? '') ?>" required maxlength="60"></div>
    <div class="form-row"><div class="form-group"><label>Invoice date</label><input class="form-control" type="date" name="invoice_date" value="<?= e($_POST['invoice_date'] ?? date('Y-m-d')) ?>"></div></div>
    <div class="form-row"><div class="form-group"><label>Amount (excl. tax)</label><input class="form-control num-input" type="number" step="0.01" min="0.01" name="subtotal" id="sub" value="<?= e($_POST['subtotal'] ?? '') ?>" required></div><div class="form-group"><label><?= e(setting('tax_name', 'VAT')) ?></label><input class="form-control num-input" type="number" step="0.01" min="0" name="tax_total" id="tax" value="<?= e($_POST['tax_total'] ?? '0') ?>"></div></div>
    <div class="form-group"><label>Notes <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="notes" maxlength="255"></div>
    <div class="callout" id="matchBox">Select goods receipts to compare.</div>
    <button class="btn btn-primary btn-lg btn-block mt-3" type="submit"><?= icon('check', 16) ?> Record invoice</button>
  </div>
</div></form>
<script>
(function () {
  const money = CalimaxUI.money, sub = document.getElementById('sub'), tax = document.getElementById('tax'), box = document.getElementById('matchBox'), tol = <?= $tol ?>;
  let touched = false; [sub, tax].forEach((i) => i.addEventListener('input', () => { touched = true; calc(); }));
  function calc() {
    const cbs = [...document.querySelectorAll('.grn-cb:checked')], v = cbs.reduce((s, c) => s + +c.dataset.v, 0), t = cbs.reduce((s, c) => s + +c.dataset.t, 0);
    if (!touched) { sub.value = v ? v.toFixed(2) : sub.value; tax.value = v ? t.toFixed(2) : tax.value; }
    if (!cbs.length) { box.className = 'callout'; box.textContent = 'Select goods receipts to compare.'; return; }
    const inv = (+sub.value || 0) + (+tax.value || 0), exp = v + t, diff = inv - exp, ok = Math.abs(diff) <= Math.max(0.01, exp * tol / 100);
    box.className = 'callout ' + (ok ? 'good' : 'warn');
    box.innerHTML = '<strong>' + (ok ? '✓ Matches goods received' : '⚠ Difference from goods received') + '</strong><br>Goods received: ' + money(exp) + ' · Invoice: ' + money(inv) + (ok ? '' : '<br>Difference: <strong>' + money(diff) + '</strong> — the invoice will be flagged for review.');
  }
  document.querySelectorAll('.grn-cb').forEach((c) => c.addEventListener('change', () => { touched = false; calc(); })); calc();
})();
</script>
<?php else: ?><div class="card"><?= empty_state('file-spreadsheet', 'Choose a supplier', 'Select the supplier to see goods that are waiting to be invoiced.') ?></div><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
