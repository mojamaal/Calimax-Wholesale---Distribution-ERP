<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('trace.view');

$pdo = db();
$id = input_int($_GET, 'id');
$s = $pdo->prepare('SELECT sn.*, p.name, p.code, p.warranty_months AS pw, w.name AS wh, su.name AS supplier, c.business_name, po.po_no, g.grn_no, si.inv_no, d.dn_no, so.so_no
    FROM serial_numbers sn JOIN products p ON p.id = sn.product_id LEFT JOIN warehouses w ON w.id = sn.warehouse_id LEFT JOIN suppliers su ON su.id = sn.supplier_id LEFT JOIN customers c ON c.id = sn.customer_id
    LEFT JOIN purchase_orders po ON po.id = sn.po_id LEFT JOIN goods_receipts g ON g.id = sn.grn_id LEFT JOIN sales_invoices si ON si.id = sn.invoice_id LEFT JOIN deliveries d ON d.id = sn.delivery_id LEFT JOIN sales_orders so ON so.id = sn.sales_order_id WHERE sn.id = :id');
$s->execute([':id' => $id]); $sn = $s->fetch();
if (!$sn) { flash('error', 'Serial number not found.'); redirect('/trace/serials.php'); }

if (is_post() && can('warranty.manage')) {
    verify_csrf();
    try {
        if (input($_POST, 'action') === 'claim') { $cid = warranty_claim_create($id, input($_POST, 'issue')); flash('success', 'Warranty claim recorded.'); redirect('/trace/warranty.php?highlight=' . $cid); }
    } catch (RuntimeException $e) { flash('error', $e->getMessage()); }
    redirect('/trace/serial_view.php?id=' . $id);
}
$events = $pdo->prepare('SELECT te.*, u.full_name FROM trace_events te LEFT JOIN users u ON u.id = te.user_id WHERE te.entity_type = \'serial\' AND te.entity_id = :id ORDER BY te.id'); $events->execute([':id' => $id]); $events = $events->fetchAll();
$claims = $pdo->prepare('SELECT wc.*, u.full_name FROM warranty_claims wc LEFT JOIN users u ON u.id = wc.created_by WHERE wc.serial_id = :id ORDER BY wc.id DESC'); $claims->execute([':id' => $id]); $claims = $claims->fetchAll();
$w = warranty_state($sn);
$refLink = fn($t, $rid) => match ($t) { 'goods_receipt' => '/purchasing/grn_view.php?id=' . $rid, 'delivery' => '/sales/delivery_view.php?id=' . $rid, 'sales_invoice' => '/sales/invoice_view.php?id=' . $rid, 'sales_return' => '/sales/return_view.php?id=' . $rid,
    'stock_adjustment' => '/warehouse/adjustment_view.php?id=' . $rid, 'stock_transfer' => '/warehouse/transfer_view.php?id=' . $rid, 'warranty_claim' => '/trace/warranty.php?highlight=' . $rid, 'purchase_return' => '/purchasing/returns.php?highlight=' . $rid, default => null };

$pageTitle = $sn['serial_no'];
require __DIR__ . '/../includes/header.php';
$act = '';
if ($sn['invoice_id'] && in_array($sn['status'], ['sold', 'dispatched'], true) && can('returns.create')) $act .= '<a class="btn btn-secondary" href="' . e(app_url('/sales/return_form.php?invoice_id=' . $sn['invoice_id'])) . '">' . icon('undo-2', 16) . ' Customer return</a>';
if ($sn['customer_id'] && can('warranty.manage')) $act .= '<button class="btn btn-primary" type="button" data-modal-open="#claimModal">' . icon('shield-plus', 16) . ' Warranty claim</button>';
page_header($sn['serial_no'], e($sn['name']) . ' · ' . status_badge($sn['status']), $act, [['Serial numbers', '/trace/serials.php'], [$sn['serial_no'], null]]);
?>
<div class="layout-2-1 mb-4">
  <div class="card">
    <div class="card-head"><div><h3>Lifecycle</h3><div class="sub">Complete history of this physical unit</div></div></div>
    <div class="trace">
      <?php foreach ($events as $e): $lk = $e['ref_type'] && $e['ref_id'] ? $refLink($e['ref_type'], $e['ref_id']) : null; ?>
        <div class="trace-item t-<?= e($e['event_type']) ?>"><span class="dot"></span><div class="t-title"><?= e($e['title']) ?></div><div class="t-when"><?= e(fmt_datetime($e['created_at'])) ?><?= $e['full_name'] ? ' · ' . e($e['full_name']) : '' ?><?= $e['ref_no'] ? ' · ' . ($lk ? '<a class="link" href="' . e(app_url($lk)) . '">' . e($e['ref_no']) . '</a>' : e($e['ref_no'])) : '' ?></div><?php if ($e['detail']): ?><div class="t-detail"><?= e($e['detail']) ?></div><?php endif; ?></div>
      <?php endforeach; ?>
      <?php if (!$events): ?><div class="text-muted">No history recorded.</div><?php endif; ?>
    </div>
  </div>
  <div class="grid">
    <div class="card"><h3 class="mb-3">Unit details</h3><dl class="desc-list">
      <dt>Product</dt><dd><a class="link" href="<?= e(app_url('/catalog/product_view.php?id=' . $sn['product_id'])) ?>"><?= e($sn['name']) ?></a></dd>
      <dt>Status</dt><dd><?= status_badge($sn['status']) ?></dd><dt>Warehouse</dt><dd><?= e($sn['wh'] ?? '—') ?></dd>
      <dt>Supplier</dt><dd><?= e($sn['supplier'] ?? '—') ?></dd><dt>Purchase order</dt><dd><?= $sn['po_id'] ? '<a class="link" href="' . e(app_url('/purchasing/order_view.php?id=' . $sn['po_id'])) . '">' . e($sn['po_no']) . '</a>' : '—' ?></dd>
      <dt>Goods receipt</dt><dd><?= $sn['grn_id'] ? '<a class="link" href="' . e(app_url('/purchasing/grn_view.php?id=' . $sn['grn_id'])) . '">' . e($sn['grn_no']) . '</a>' : '—' ?></dd><dt>Received</dt><dd><?= e(fmt_date($sn['received_date'])) ?></dd>
      <?php if (can('products.view_cost')): ?><dt>Purchase cost</dt><dd><?= format_money($sn['unit_cost']) ?></dd><?php endif; ?></dl></div>
    <div class="card"><h3 class="mb-3">Sale</h3><?php if ($sn['customer_id']): ?><dl class="desc-list"><dt>Customer</dt><dd><a class="link" href="<?= e(app_url('/sales/customer_view.php?id=' . $sn['customer_id'])) ?>"><?= e($sn['business_name']) ?></a></dd><dt>Order</dt><dd><?= e($sn['so_no'] ?? '—') ?></dd><dt>Delivery note</dt><dd><?= $sn['delivery_id'] ? '<a class="link" href="' . e(app_url('/sales/delivery_view.php?id=' . $sn['delivery_id'])) . '">' . e($sn['dn_no']) . '</a>' : '—' ?></dd><dt>Invoice</dt><dd><?= $sn['invoice_id'] ? '<a class="link" href="' . e(app_url('/sales/invoice_view.php?id=' . $sn['invoice_id'])) . '">' . e($sn['inv_no']) . '</a>' : '—' ?></dd><dt>Sold</dt><dd><?= e(fmt_date($sn['sold_date'])) ?></dd></dl>
      <?php else: ?><div class="text-muted text-sm">Not sold to a customer.</div><?php endif; ?></div>
    <div class="card <?= $w['state'] === 'active' ? '' : '' ?>"><h3 class="mb-3">Warranty</h3>
      <?php if ($w['state'] === 'not_sold'): ?><div class="text-muted text-sm">Warranty starts when the unit is delivered to a customer (<?= (int) $sn['warranty_months'] ?> months).</div>
      <?php elseif ($w['state'] === 'none'): ?><div class="text-muted text-sm">This product carries no warranty.</div>
      <?php else: ?><div class="callout <?= $w['state'] === 'active' ? 'good' : 'bad' ?>"><strong><?= e($w['label']) ?></strong></div><dl class="desc-list mt-3"><dt>Period</dt><dd><?= (int) $sn['warranty_months'] ?> months</dd><dt>Starts</dt><dd><?= e(fmt_date($sn['sold_date'])) ?></dd><dt>Expires</dt><dd><?= e(fmt_date($sn['warranty_expiry'])) ?></dd></dl><?php endif; ?>
      <?php foreach ($claims as $c): ?><div class="summary-row mt-2"><a class="link" href="<?= e(app_url('/trace/warranty.php?highlight=' . $c['id'])) ?>"><?= e($c['claim_no']) ?></a><?= status_badge($c['status']) ?></div><div class="text-sm text-secondary"><?= e($c['issue']) ?></div><?php endforeach; ?></div>
  </div>
</div>
<?php if ($sn['customer_id'] && can('warranty.manage')): ?>
<div class="modal-overlay" id="claimModal" hidden><div class="modal sm"><div class="modal-header"><h3>Warranty claim — <?= e($sn['serial_no']) ?></h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <div class="callout <?= $w['state'] === 'active' ? 'good' : 'bad' ?> mb-3"><?= e($w['label']) ?></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="claim"><div class="form-group"><label>Problem reported</label><input class="form-control" type="text" name="issue" required maxlength="255" placeholder="e.g. Screen flickering"></div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Record claim</button></div></form></div></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
