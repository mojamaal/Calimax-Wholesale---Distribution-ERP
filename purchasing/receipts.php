<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.view');

$pdo = db();
$period = resolve_report_period('this_month'); $sup = input_int($_GET, 'supplier_id'); $filter = input($_GET, 'filter'); $q = input($_GET, 'q');
$page = max(1, input_int($_GET, 'page', 1)); $perPage = 40;
$where = $filter === 'uninvoiced' ? 'g.supplier_invoice_id IS NULL' : 'g.received_date BETWEEN :f AND :t'; $params = $filter === 'uninvoiced' ? [] : [':f' => $period['from'], ':t' => $period['to']];
if ($sup) { $where .= ' AND g.supplier_id = :s'; $params[':s'] = $sup; }
if ($q !== '') { $where .= ' AND (g.grn_no LIKE :q1 OR g.supplier_ref LIKE :q2 OR po.po_no LIKE :q3)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%"]; }
$from = "FROM goods_receipts g JOIN suppliers s ON s.id = g.supplier_id JOIN warehouses w ON w.id = g.warehouse_id LEFT JOIN purchase_orders po ON po.id = g.po_id LEFT JOIN supplier_invoices si ON si.id = g.supplier_invoice_id WHERE $where";
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
$sm = $pdo->prepare("SELECT COALESCE(SUM(g.total_value),0) $from"); $sm->execute($params);
$st = $pdo->prepare("SELECT g.*, s.name AS supplier, w.code AS wh, po.po_no, si.bill_no, (SELECT COALESCE(SUM(qty_received),0) FROM grn_items WHERE grn_id = g.id) AS units, (SELECT COALESCE(SUM(qty_rejected),0) FROM grn_items WHERE grn_id = g.id) AS rejected $from ORDER BY g.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params); $rows = $st->fetchAll();
$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();
$uninv = (int) $pdo->query('SELECT COUNT(*) FROM goods_receipts WHERE supplier_invoice_id IS NULL')->fetchColumn();
$pageTitle = 'Goods received';
require __DIR__ . '/../includes/header.php';
page_header('Goods received', 'Every delivery recorded against a purchase order — separate from the order and from the supplier’s invoice.', can('purchase.receive') ? '<a class="btn btn-primary" href="' . e(app_url('/purchasing/receive.php')) . '">' . icon('package-plus', 16) . ' Receive goods</a>' : '');
nav_purchasing();
if ($filter !== 'uninvoiced') { echo '<div class="card tight mb-4">'; render_period_filter($period, ['supplier_id' => $sup ?: '', 'q' => $q]); echo '</div>'; }
?>
<div class="grid grid-3 mb-4"><?= kpi('Value received', format_money_short($sm->fetchColumn()), 'package-check', 'green', $filter === 'uninvoiced' ? 'Not yet invoiced' : e($period['label'])) ?><?= kpi('Receipts', number_format($total), 'receipt', 'blue') ?><?= kpi('Awaiting supplier invoice', (string) $uninv, 'file-spreadsheet', $uninv ? 'amber' : 'gray', 'Goods received, not invoiced', '/purchasing/receipts.php?filter=uninvoiced') ?></div>
<div class="card flush">
  <div style="padding:16px 16px 0;"><form method="get" class="filter-bar"><?php foreach (['preset' => $period['preset'] ?: 'custom', 'from' => $period['from'], 'to' => $period['to'], 'filter' => $filter] as $k => $v) if ($v !== '') echo '<input type="hidden" name="' . $k . '" value="' . e($v) . '">'; ?><div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="GRN, PO or delivery note"></div><select class="form-control" name="supplier_id" onchange="this.form.submit()"><option value="">All suppliers</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sup === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select><a class="chip <?= $filter === 'uninvoiced' ? 'active' : '' ?>" href="<?= e(query_with(['filter' => $filter ? null : 'uninvoiced'])) ?>">Not invoiced</a></form></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Receipt</th><th>Date</th><th>Supplier</th><th>Purchase order</th><th>Warehouse</th><th class="num">Units</th><th class="num">Value</th><th>Invoice</th></tr></thead><tbody>
  <?php foreach ($rows as $g): ?><tr><td class="strong"><a href="<?= e(app_url('/purchasing/grn_view.php?id=' . $g['id'])) ?>"><?= e($g['grn_no']) ?></a><div class="cell-sub"><?= e($g['supplier_ref'] ?? '') ?></div></td><td class="text-secondary nowrap"><?= e(fmt_date($g['received_date'])) ?></td><td><?= e($g['supplier']) ?></td><td><?= $g['po_id'] ? '<a class="link" href="' . e(app_url('/purchasing/order_view.php?id=' . $g['po_id'])) . '">' . e($g['po_no']) . '</a>' : '—' ?></td><td class="text-secondary"><?= e($g['wh']) ?></td><td class="num"><?= fmt_qty($g['units']) ?><?= (int) $g['rejected'] ? '<div class="cell-sub text-error">' . fmt_qty($g['rejected']) . ' rejected</div>' : '' ?></td><td class="num strong"><?= format_money($g['total_value']) ?></td><td><?= $g['bill_no'] ? '<a class="link" href="' . e(app_url('/purchasing/bill_view.php?id=' . $g['supplier_invoice_id'])) . '">' . e($g['bill_no']) . '</a>' : '<span class="badge badge-neutral">Not invoiced</span>' ?></td></tr><?php endforeach; ?></tbody></table>
  <?php if (!$rows) echo empty_state('package', 'No goods receipts', 'Goods are received against approved purchase orders.'); ?></div><?php render_pagination($page, $perPage, $total); ?></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
