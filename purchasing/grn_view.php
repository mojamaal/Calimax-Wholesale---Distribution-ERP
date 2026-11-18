<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.view');

$pdo = db();
$id = input_int($_GET, 'id');
$s = $pdo->prepare('SELECT g.*, s.name AS supplier, w.name AS wh, po.po_no, si.bill_no, u.full_name AS who FROM goods_receipts g JOIN suppliers s ON s.id = g.supplier_id JOIN warehouses w ON w.id = g.warehouse_id LEFT JOIN purchase_orders po ON po.id = g.po_id LEFT JOIN supplier_invoices si ON si.id = g.supplier_invoice_id LEFT JOIN users u ON u.id = g.created_by WHERE g.id = :id');
$s->execute([':id' => $id]); $g = $s->fetch();
if (!$g) { flash('error', 'Goods receipt not found.'); redirect('/purchasing/receipts.php'); }
$items = $pdo->prepare('SELECT gi.*, p.name, p.code, p.base_unit, p.tracking, b.batch_no, b.expiry_date, b.mfg_date, poi.qty AS ordered, poi.received_qty AS po_received FROM grn_items gi JOIN products p ON p.id = gi.product_id LEFT JOIN batches b ON b.id = gi.batch_id LEFT JOIN purchase_order_items poi ON poi.id = gi.po_item_id WHERE gi.grn_id = :g ORDER BY gi.id');
$items->execute([':g' => $id]); $items = $items->fetchAll();
$serials = []; foreach ($pdo->query("SELECT id, product_id, serial_no, status FROM serial_numbers WHERE grn_id = $id ORDER BY serial_no")->fetchAll() as $r) $serials[$r['product_id']][] = $r;
$pageTitle = $g['grn_no'];
require __DIR__ . '/../includes/header.php';
page_header($g['grn_no'], e($g['supplier']) . ' · ' . e(fmt_date($g['received_date'])) . ($g['po_no'] ? ' · <a class="link" href="' . e(app_url('/purchasing/order_view.php?id=' . $g['po_id'])) . '">' . e($g['po_no']) . '</a>' : ''),
    '<a class="btn btn-secondary" target="_blank" href="' . e(app_url('/purchasing/print.php?type=grn&id=' . $id)) . '">' . icon('printer', 16) . ' Print</a>' . (!$g['supplier_invoice_id'] && can('supplier_bills.manage') ? '<a class="btn btn-primary" href="' . e(app_url('/purchasing/bill_form.php?supplier_id=' . $g['supplier_id'] . '&grn_id=' . $id)) . '">' . icon('file-spreadsheet', 16) . ' Record supplier invoice</a>' : ''), [['Goods received', '/purchasing/receipts.php'], [$g['grn_no'], null]]);
?>
<div class="stat-strip"><div><div class="sl">Value received</div><div class="sv"><?= format_money($g['total_value']) ?></div></div><div><div class="sl">Warehouse</div><div class="sv" style="font-size:15px;"><?= e($g['wh']) ?></div></div><div><div class="sl">Supplier ref</div><div class="sv" style="font-size:15px;"><?= e($g['supplier_ref'] ?: '—') ?></div></div><div><div class="sl">Received by</div><div class="sv" style="font-size:15px;"><?= e($g['who'] ?? '—') ?></div></div><div><div class="sl">Supplier invoice</div><div class="sv" style="font-size:15px;"><?= $g['bill_no'] ? '<a class="link" href="' . e(app_url('/purchasing/bill_view.php?id=' . $g['supplier_invoice_id'])) . '">' . e($g['bill_no']) . '</a>' : 'Not yet' ?></div></div></div>
<?php if ($g['notes']): ?><div class="callout mb-4"><?= e($g['notes']) ?></div><?php endif; ?>
<div class="card flush"><div class="card-head"><h3>Received items</h3></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Batch / serial numbers</th><th class="num">Ordered</th><th class="num">Received</th><th class="num">Rejected</th><th class="num">Unit cost</th><th class="num">Value</th></tr></thead><tbody>
<?php foreach ($items as $i): $shown = []; ?>
  <tr><td><span class="strong"><?= e($i['name']) ?></span> <?= $i['tracking'] !== 'none' ? '<span class="tag ' . $i['tracking'] . '">' . e($i['tracking']) . '</span>' : '' ?><div class="cell-sub mono"><?= e($i['code']) ?></div></td>
    <td><?php if ($i['batch_no']): ?><a class="link mono" href="<?= e(app_url('/trace/batch_view.php?id=' . $i['batch_id'])) ?>"><?= e($i['batch_no']) ?></a><div class="cell-sub">Mfg <?= e(fmt_date($i['mfg_date'])) ?> · Exp <?= e(fmt_date($i['expiry_date'])) ?></div>
      <?php elseif ($i['tracking'] === 'serial' && empty($shown[$i['product_id']])): $shown[$i['product_id']] = 1; ?><div class="chip-list" style="max-width:420px;"><?php foreach (array_slice($serials[$i['product_id']] ?? [], 0, 12) as $sn): ?><a class="sn-chip" style="padding:2px 9px;" href="<?= e(app_url('/trace/serial_view.php?id=' . $sn['id'])) ?>"><?= e($sn['serial_no']) ?></a><?php endforeach; ?><?php if (count($serials[$i['product_id']] ?? []) > 12): ?><a class="link text-sm" href="<?= e(app_url('/trace/serials.php?product=' . $i['product_id'])) ?>">+<?= count($serials[$i['product_id']]) - 12 ?> more</a><?php endif; ?></div><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
    <td class="num text-secondary"><?= $i['ordered'] ? fmt_qty($i['ordered']) : '—' ?></td><td class="num strong text-success"><?= fmt_qty($i['qty_received']) ?></td><td class="num <?= $i['qty_rejected'] ? 'text-error strong' : 'text-muted' ?>"><?= $i['qty_rejected'] ? fmt_qty($i['qty_rejected']) . '<div class="cell-sub">' . e($i['reject_reason']) . '</div>' : '—' ?></td><td class="num"><?= format_money($i['unit_cost']) ?></td><td class="num strong"><?= format_money($i['qty_received'] * $i['unit_cost']) ?></td></tr>
<?php endforeach; ?></tbody><tfoot><tr><td colspan="6" class="text-right">Total value received</td><td class="num"><?= format_money($g['total_value']) ?></td></tr></tfoot></table></div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
