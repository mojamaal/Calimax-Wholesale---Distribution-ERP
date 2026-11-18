<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('invoices.create');

$pdo = db();
$did = input_int($_GET, 'delivery_id');
$s = $pdo->prepare('SELECT d.*, so.so_no, so.payment_type, so.rep_id, c.business_name, c.payment_terms_days, c.id AS cust_id FROM deliveries d JOIN sales_orders so ON so.id = d.so_id JOIN customers c ON c.id = d.customer_id WHERE d.id = :id');
$s->execute([':id' => $did]); $d = $s->fetch();
if (!$d || $d['status'] !== 'delivered') { flash('error', 'Only delivered goods can be invoiced.'); redirect('/sales/deliveries.php?filter=uninvoiced'); }
$it = $pdo->prepare('SELECT di.*, p.name, p.code, p.base_unit, soi.unit_price, soi.discount_pct, soi.tax_rate, soi.pack_name, soi.pack_factor FROM delivery_items di JOIN products p ON p.id = di.product_id JOIN sales_order_items soi ON soi.id = di.so_item_id WHERE di.delivery_id = :d AND di.qty_accepted > di.qty_invoiced ORDER BY di.id');
$it->execute([':d' => $did]); $lines = $it->fetchAll();
if (!$lines) { flash('info', 'Everything on this delivery is already invoiced.'); redirect('/sales/delivery_view.php?id=' . $did); }

if (is_post()) {
    verify_csrf();
    try {
        $inv = invoice_create_from_delivery($did, input_date($_POST, 'invoice_date', date('Y-m-d')), input($_POST, 'method', 'cash'), input($_POST, 'notes'));
        flash('success', 'Invoice created' . ($d['payment_type'] === 'cash' ? ' and the cash payment recorded.' : '. The customer’s outstanding balance has increased.'));
        redirect('/sales/invoice_view.php?id=' . $inv);
    } catch (RuntimeException $e) { flash('error', $e->getMessage()); redirect('/sales/invoice_create.php?delivery_id=' . $did); }
}
$sub = 0; $tax = 0; $calc = [];
foreach ($lines as $l) { $q = (int) $l['qty_accepted'] - (int) $l['qty_invoiced']; $a = line_amounts($q, (float) $l['unit_price'], (float) $l['discount_pct'], (float) $l['tax_rate']); $calc[] = [$l, $q, $a]; $sub += $a['net']; $tax += $a['tax']; }
$total = round2($sub + $tax);
$cash = $d['payment_type'] === 'cash';
$ar = customer_ar((int) $d['cust_id']);
$pageTitle = 'Create invoice';
require __DIR__ . '/../includes/header.php';
page_header('Create invoice', 'Invoice the quantities the customer actually accepted on ' . e($d['dn_no']) . '.', '<a class="btn btn-secondary" href="' . e(app_url('/sales/delivery_view.php?id=' . $did)) . '">Cancel</a>', [['Deliveries', '/sales/deliveries.php'], [$d['dn_no'], '/sales/delivery_view.php?id=' . $did], ['Invoice', null]]);
?>
<form method="post"><?= csrf_field() ?>
<div class="layout-2-1">
  <div class="card flush">
    <div class="card-head"><h3>Invoice lines</h3><span class="text-muted text-sm"><?= e($d['business_name']) ?> · <?= e($d['so_no']) ?></span></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Product</th><th class="num">Qty accepted</th><th class="num">Unit price</th><th class="num">Disc.</th><th class="num"><?= e(setting('tax_name', 'VAT')) ?></th><th class="num">Total</th></tr></thead>
      <tbody><?php foreach ($calc as [$l, $q, $a]): ?><tr><td class="strong"><?= e($l['name']) ?><div class="cell-sub mono"><?= e($l['code']) ?></div></td><td class="num"><?= fmt_pack_qty($q, (int) $l['pack_factor'], $l['pack_name'], $l['base_unit']) ?></td><td class="num"><?= format_money($l['unit_price'] * $l['pack_factor']) ?><div class="cell-sub">per <?= e($l['pack_factor'] > 1 ? $l['pack_name'] : $l['base_unit']) ?></div></td><td class="num text-secondary"><?= (float) $l['discount_pct'] > 0 ? rtrim(rtrim(number_format($l['discount_pct'], 2), '0'), '.') . '%' : '—' ?></td><td class="num text-secondary"><?= format_money($a['tax']) ?></td><td class="num strong"><?= format_money($a['total']) ?></td></tr><?php endforeach; ?></tbody>
      <tfoot><tr><td colspan="5" class="text-right">Subtotal</td><td class="num"><?= format_money($sub) ?></td></tr><tr><td colspan="5" class="text-right"><?= e(setting('tax_name', 'VAT')) ?></td><td class="num"><?= format_money($tax) ?></td></tr><tr><td colspan="5" class="text-right">Invoice total</td><td class="num"><?= format_money($total) ?></td></tr></tfoot>
    </table></div>
    <?php $rej = array_sum(array_column($pdo->query("SELECT qty_rejected FROM delivery_items WHERE delivery_id = $did")->fetchAll(), 'qty_rejected')); if ($rej): ?><div class="callout warn" style="margin:14px 18px;"><strong>Shortage recorded:</strong> <?= fmt_qty($rej) ?> unit(s) were rejected at delivery and are not invoiced.</div><?php endif; ?>
  </div>
  <div class="grid">
    <div class="card">
      <h3 class="mb-3">Terms</h3>
      <div class="form-group"><label>Invoice date</label><input class="form-control" type="date" name="invoice_date" value="<?= date('Y-m-d') ?>"></div>
      <?php if ($cash): ?>
        <div class="callout good mb-3"><strong>Cash sale.</strong> The full amount is recorded as received now.</div>
        <div class="form-group"><label>Payment received by</label><select class="form-control" name="method"><?php foreach (money_methods() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <?php else: ?>
        <div class="callout info mb-3"><strong>Credit sale — <?= (int) $d['payment_terms_days'] ?> day terms.</strong> The amount is added to the customer’s receivable and falls due <?= e(fmt_date(date('Y-m-d', strtotime('+' . (int) $d['payment_terms_days'] . ' days')))) ?>.</div>
        <dl class="desc-list mb-2"><dt>Balance now</dt><dd><?= format_money($ar['balance']) ?></dd><dt>After this invoice</dt><dd><?= format_money($ar['balance'] + $total) ?></dd></dl>
      <?php endif; ?>
      <div class="form-group mt-3 mb-0"><label>Notes <span class="label-opt">(printed on invoice)</span></label><input class="form-control" type="text" name="notes" maxlength="255"></div>
    </div>
    <button class="btn btn-primary btn-lg btn-block" type="submit"><?= icon('receipt', 16) ?> Create invoice · <?= format_money($total) ?></button>
  </div>
</div>
</form>
<?php require __DIR__ . '/../includes/footer.php'; ?>
