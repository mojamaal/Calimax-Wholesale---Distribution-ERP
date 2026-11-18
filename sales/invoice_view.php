<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('invoices.view');

$pdo = db();
$id = input_int($_GET, 'id');
$s = $pdo->prepare('SELECT si.*, c.business_name, c.address, c.phone, c.tax_no, c.payment_terms_days, u.full_name AS rep, so.so_no, d.dn_no, w.name AS wh_name FROM sales_invoices si JOIN customers c ON c.id = si.customer_id LEFT JOIN users u ON u.id = si.rep_id LEFT JOIN sales_orders so ON so.id = si.so_id LEFT JOIN deliveries d ON d.id = si.delivery_id LEFT JOIN warehouses w ON w.id = si.warehouse_id WHERE si.id = :id');
$s->execute([':id' => $id]); $inv = $s->fetch();
if (!$inv || (!can('sales.view_all') && !can('invoices.create') && (int) $inv['rep_id'] !== (int) current_user()['id'])) { flash('error', 'Invoice not found.'); redirect('/sales/invoices.php'); }

if (is_post()) {
    verify_csrf();
    $a = input($_POST, 'action');
    try {
        if ($a === 'credit_lines' && can('creditnotes.issue')) {
            $lines = array_filter(array_map('intval', $_POST['cq'] ?? []));
            $cn = credit_note_issue($id, $lines, input($_POST, 'reason'));
            flash('success', 'Credit note issued. The customer’s balance has been reduced.'); redirect('/sales/credit_notes.php?highlight=' . $cn);
        } elseif ($a === 'credit_amount' && can('creditnotes.issue')) {
            $cn = credit_note_issue($id, [], input($_POST, 'reason'), null, input_float($_POST, 'amount'), input_float($_POST, 'tax_rate'));
            flash('success', 'Credit note issued.'); redirect('/sales/credit_notes.php?highlight=' . $cn);
        } elseif ($a === 'void' && can('invoices.create')) { invoice_void($id, input($_POST, 'reason')); flash('success', 'Invoice voided and the delivery re-opened for invoicing.'); }
    } catch (RuntimeException $e) { flash('error', $e->getMessage()); }
    redirect('/sales/invoice_view.php?id=' . $id);
}

$items = $pdo->prepare('SELECT sii.*, p.name, p.code, p.base_unit, p.tracking FROM sales_invoice_items sii JOIN products p ON p.id = sii.product_id WHERE sii.invoice_id = :i ORDER BY sii.id'); $items->execute([':i' => $id]); $items = $items->fetchAll();
$trace = [];
foreach ($items as $it) {
    if (!$it['delivery_item_id']) continue;
    $t = $pdo->prepare('SELECT da.qty, da.rejected_qty, b.id AS bid, b.batch_no, b.expiry_date, sn.id AS sid, sn.serial_no, sn.warranty_expiry FROM delivery_allocations da LEFT JOIN batches b ON b.id = da.batch_id LEFT JOIN serial_numbers sn ON sn.id = da.serial_id WHERE da.delivery_item_id = :i');
    $t->execute([':i' => $it['delivery_item_id']]); $trace[$it['id']] = $t->fetchAll();
}
$pays = $pdo->query("SELECT cpa.amount, cp.* FROM customer_payment_allocations cpa JOIN customer_payments cp ON cp.id = cpa.payment_id WHERE cpa.invoice_id = $id ORDER BY cp.pay_date, cp.id")->fetchAll();
$cns = $pdo->query("SELECT * FROM credit_notes WHERE invoice_id = $id ORDER BY id")->fetchAll();
$rets = $pdo->query("SELECT * FROM sales_returns WHERE invoice_id = $id ORDER BY id")->fetchAll();
$out = round2((float) $inv['total'] - (float) $inv['paid_amount'] - (float) $inv['credited_amount']);
$late = $out > 0.004 && $inv['due_date'] < date('Y-m-d') && $inv['status'] !== 'void';

$pageTitle = $inv['inv_no'];
require __DIR__ . '/../includes/header.php';
$act = '<a class="btn btn-secondary" href="' . e(app_url('/sales/print.php?type=invoice&id=' . $id)) . '" target="_blank">' . icon('printer', 16) . ' Print</a>';
if ($inv['status'] !== 'void' && $out > 0.004 && can('payments.receive')) $act .= '<a class="btn btn-primary" href="' . e(app_url('/sales/payment_form.php?customer_id=' . $inv['customer_id'] . '&invoice_id=' . $id)) . '">' . icon('hand-coins', 16) . ' Record payment</a>';
if ($inv['status'] !== 'void' && can('creditnotes.issue')) $act .= '<button class="btn btn-secondary" type="button" data-modal-open="#creditModal">' . icon('file-minus', 16) . ' Credit note</button>';
if ($inv['status'] !== 'void' && can('returns.create')) $act .= '<a class="btn btn-secondary" href="' . e(app_url('/sales/return_form.php?invoice_id=' . $id)) . '">' . icon('undo-2', 16) . ' Customer return</a>';
page_header($inv['inv_no'], '<a class="link" href="' . e(app_url('/sales/customer_view.php?id=' . $inv['customer_id'])) . '">' . e($inv['business_name']) . '</a> · ' . e(fmt_date($inv['invoice_date'])) . ' · ' . ($late ? '<span class="badge badge-error">Overdue</span>' : status_badge($inv['status'])), $act, [['Invoices', '/sales/invoices.php'], [$inv['inv_no'], null]]);
?>
<div class="stat-strip">
  <div><div class="sl">Invoice total</div><div class="sv"><?= format_money($inv['total']) ?></div></div>
  <div><div class="sl">Paid</div><div class="sv text-success"><?= format_money($inv['paid_amount']) ?></div></div>
  <div><div class="sl">Credited</div><div class="sv"><?= format_money($inv['credited_amount']) ?></div></div>
  <div><div class="sl">Outstanding</div><div class="sv <?= $out > 0.004 ? ($late ? 'text-error' : '') : 'text-success' ?>"><?= $out > 0.004 ? format_money($out) : 'Settled' ?></div></div>
  <div><div class="sl">Due date</div><div class="sv" style="font-size:16px;"><?= e(fmt_date($inv['due_date'])) ?></div></div>
</div>

<div class="layout-2-1 mb-4">
  <div class="card flush">
    <div class="card-head"><h3>Items</h3><span class="text-muted text-sm"><?= $inv['so_no'] ? 'Order ' . e($inv['so_no']) : '' ?><?= $inv['dn_no'] ? ' · Delivery ' . e($inv['dn_no']) : '' ?></span></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Product</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Disc.</th><th class="num"><?= e(setting('tax_name', 'VAT')) ?></th><th class="num">Total</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><span class="strong"><?= e($it['name']) ?></span> <span class="text-muted mono"><?= e($it['code']) ?></span>
            <?php foreach ($trace[$it['id']] ?? [] as $t): if ($t['sid']): ?><a class="sn-chip" style="padding:2px 9px;margin:3px 3px 0 0;" href="<?= e(app_url('/trace/serial_view.php?id=' . $t['sid'])) ?>"><?= e($t['serial_no']) ?></a><?php elseif ($t['bid']): ?><div class="cell-sub">Batch <a class="link mono" href="<?= e(app_url('/trace/batch_view.php?id=' . $t['bid'])) ?>"><?= e($t['batch_no']) ?></a> × <?= fmt_qty($t['qty'] - $t['rejected_qty']) ?><?= $t['expiry_date'] ? ' · exp ' . e(fmt_date($t['expiry_date'])) : '' ?></div><?php endif; endforeach; ?></td>
          <td class="num"><?= fmt_pack_qty((int) $it['qty'], (int) $it['pack_factor'], $it['pack_name'], $it['base_unit']) ?><?= (int) $it['returned_qty'] ? '<div class="cell-sub text-error">' . fmt_qty($it['returned_qty']) . ' credited</div>' : '' ?></td>
          <td class="num"><?= format_money($it['unit_price'] * $it['pack_factor']) ?></td>
          <td class="num text-secondary"><?= (float) $it['discount_pct'] > 0 ? rtrim(rtrim(number_format($it['discount_pct'], 2), '0'), '.') . '%' : '—' ?></td>
          <td class="num text-secondary"><?= format_money($it['tax_amount']) ?></td>
          <td class="num strong"><?= format_money($it['line_total']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="5" class="text-right">Subtotal</td><td class="num"><?= format_money($inv['subtotal']) ?></td></tr><tr><td colspan="5" class="text-right"><?= e(setting('tax_name', 'VAT')) ?></td><td class="num"><?= format_money($inv['tax_total']) ?></td></tr><tr><td colspan="5" class="text-right">Total</td><td class="num"><?= format_money($inv['total']) ?></td></tr></tfoot>
    </table></div>
  </div>
  <div class="grid">
    <div class="card"><h3 class="mb-3">Details</h3><dl class="desc-list">
      <dt>Customer</dt><dd><?= e($inv['business_name']) ?></dd><dt>Sales rep</dt><dd><?= e($inv['rep'] ?? '—') ?></dd><dt>Terms</dt><dd><?= $inv['payment_type'] === 'cash' ? 'Cash' : (int) $inv['payment_terms_days'] . ' days credit' ?></dd><dt>Warehouse</dt><dd><?= e($inv['wh_name'] ?? '—') ?></dd>
      <?php if ($inv['tax_no']): ?><dt>Customer tax no.</dt><dd><?= e($inv['tax_no']) ?></dd><?php endif; ?></dl>
      <?php if ($inv['notes']): ?><div class="callout mt-3"><?= e($inv['notes']) ?></div><?php endif; ?></div>
    <div class="card flush"><div class="card-head"><h3>Payments &amp; credits</h3></div>
      <div class="list-rows" style="padding:0 18px 12px;">
        <?php foreach ($pays as $p): ?><div><span><span class="strong mono"><?= e($p['receipt_no']) ?></span><div class="cell-sub"><?= e(fmt_date($p['pay_date'])) ?> · <?= e(method_label($p['method'])) ?></div></span><strong class="text-success"><?= format_money($p['amount']) ?></strong></div><?php endforeach; ?>
        <?php foreach ($cns as $c): ?><div><span><span class="strong mono"><?= e($c['cn_no']) ?></span><div class="cell-sub"><?= e(fmt_date($c['cn_date'])) ?> · credit note<?= $c['reason'] ? ' · ' . e($c['reason']) : '' ?></div></span><strong><?= format_money($c['total']) ?></strong></div><?php endforeach; ?>
        <?php if (!$pays && !$cns): ?><div class="text-muted text-sm">No payments or credit notes yet.</div><?php endif; ?>
      </div></div>
    <?php if ($rets): ?><div class="card"><h3 class="mb-2">Returns</h3><?php foreach ($rets as $r): ?><div class="summary-row"><a class="link" href="<?= e(app_url('/sales/return_view.php?id=' . $r['id'])) ?>"><?= e($r['rma_no']) ?></a><?= status_badge($r['status']) ?></div><?php endforeach; ?></div><?php endif; ?>
    <?php if ($inv['status'] !== 'void' && can('invoices.create') && (float) $inv['paid_amount'] == 0 && (float) $inv['credited_amount'] == 0): ?><button class="btn btn-danger-outline" type="button" data-modal-open="#voidModal">Void invoice</button><?php endif; ?>
    <a class="link text-sm" href="<?= e(app_url('/audit.php?entity=sales_invoice&entity_id=' . $id)) ?>">Audit trail →</a>
  </div>
</div>

<?php if (can('creditnotes.issue') && $inv['status'] !== 'void'): ?>
<div class="modal-overlay" id="creditModal" hidden><div class="modal lg">
  <div class="modal-header"><h3>Issue credit note — <?= e($inv['inv_no']) ?></h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <div class="segmented mb-3"><label><input type="radio" name="cmode" value="lines" checked onchange="cm()"><span>Credit returned / short goods</span></label><label><input type="radio" name="cmode" value="amount" onchange="cm()"><span>Price / discount adjustment</span></label></div>
  <form method="post" id="cLines"><?= csrf_field() ?><input type="hidden" name="action" value="credit_lines">
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th class="num">Invoiced</th><th class="num">Already credited</th><th style="width:140px;">Credit qty</th></tr></thead><tbody>
    <?php foreach ($items as $it): $can = (int) $it['qty'] - (int) $it['returned_qty']; ?><tr><td class="strong"><?= e($it['name']) ?></td><td class="num"><?= fmt_qty($it['qty']) ?></td><td class="num"><?= fmt_qty($it['returned_qty']) ?></td><td><input class="form-control num-input" type="number" min="0" max="<?= $can ?>" name="cq[<?= (int) $it['id'] ?>]" value="0" <?= $can <= 0 ? 'disabled' : '' ?>></td></tr><?php endforeach; ?></tbody></table></div>
    <div class="form-group mt-3"><label>Reason</label><input class="form-control" type="text" name="reason" required maxlength="255" placeholder="e.g. Goods returned damaged"></div>
    <div class="callout info text-sm">This credits the customer’s account only. To take returned goods back into the warehouse, raise a <strong>customer return</strong> instead — it issues the credit note after inspection.</div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Issue credit note</button></div></form>
  <form method="post" id="cAmount" hidden><?= csrf_field() ?><input type="hidden" name="action" value="credit_amount">
    <div class="form-row"><div class="form-group"><label>Credit amount (excl. tax)</label><input class="form-control" type="number" step="0.01" min="0.01" name="amount" required></div><div class="form-group"><label><?= e(setting('tax_name', 'VAT')) ?> rate (%)</label><input class="form-control" type="number" step="0.01" min="0" name="tax_rate" value="0"></div></div>
    <div class="form-group"><label>Reason</label><input class="form-control" type="text" name="reason" required maxlength="255" placeholder="e.g. Price adjustment agreed"></div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Issue credit note</button></div></form>
</div></div>
<script>function cm(){var a=document.querySelector('input[name=cmode]:checked').value==='amount';document.getElementById('cLines').hidden=a;document.getElementById('cAmount').hidden=!a;}</script>
<?php endif; ?>
<?php if (can('invoices.create')): ?>
<div class="modal-overlay" id="voidModal" hidden><div class="modal sm"><div class="modal-header"><h3>Void invoice</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="void"><div class="form-group"><label>Reason</label><input class="form-control" type="text" name="reason" required maxlength="255"></div><p class="text-secondary text-sm">Reverses the revenue and receivable. The delivered goods can be invoiced again.</p>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-danger" type="submit">Void invoice</button></div></form></div></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
