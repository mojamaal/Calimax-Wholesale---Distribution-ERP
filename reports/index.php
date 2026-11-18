<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reports.view');

$pdo = db();
$period = resolve_report_period('this_month');
$tot = sales_totals([], $period['from'], $period['to']);
$coll = collections_total($period['from'], $period['to']);
$val = inventory_valuation();
$pnl = can('reports.finance') ? gl_pnl($period['from'], $period['to']) : null;
$cards = [
    ['Sales analysis', 'Sales by customer, product, category, rep, warehouse and month — with margin.', 'trending-up', '/reports/sales.php', 'reports.view'],
    ['Inventory valuation', 'Stock value by warehouse and product, expiry exposure and low-stock list.', 'warehouse', '/reports/inventory.php', 'reports.view'],
    ['Sales rep performance', 'Sales, collections, active customers and targets per representative.', 'users', '/reps/index.php', 'reps.view'],
    ['Customer ageing', 'Who owes you money and for how long.', 'hand-coins', '/finance/receivables.php', 'finance.view'],
    ['Supplier ageing', 'What you owe suppliers, by due date.', 'file-clock', '/finance/payables.php', 'finance.view'],
    ['Profit & loss / balance sheet', 'Financial statements from the general ledger.', 'scale', '/finance/statements.php', 'reports.finance'],
    ['Tax summary', 'Output vs input tax by month.', 'percent', '/reports/tax.php', 'reports.finance'],
    ['Expiry monitor', 'Batches approaching expiry and value at risk.', 'calendar-clock', '/trace/expiry.php', 'trace.view'],
    ['Audit log', 'Who changed prices, limits, stock and approvals.', 'shield-check', '/audit.php', 'audit.view'],
];
$pageTitle = 'Reports';
require __DIR__ . '/../includes/header.php';
page_header('Reports', 'Answers for the owner without combining spreadsheets: what we sold, who bought, what was collected, what is outstanding and what we hold.');
nav_reports();
?>
<div class="card tight mb-4"><?php render_period_filter($period); ?></div>
<div class="grid grid-4 mb-4"><?= kpi('Sales', format_money_short($tot['net']), 'banknote', '', (int) $tot['invoices'] . ' invoices · ' . (int) $tot['customers'] . ' customers') ?><?= kpi('Collected', format_money_short($coll), 'hand-coins', 'green', e($period['label'])) ?><?= kpi('Outstanding', format_money_short(receivables_total()), 'wallet', 'amber', format_money_short(receivables_overdue()) . ' overdue') ?><?= kpi('Inventory value', format_money_short($val['total']), 'warehouse', 'purple', number_format($val['units']) . ' units') ?></div>
<div class="grid grid-3">
<?php foreach ($cards as [$t, $d, $ic, $href, $perm]): if (!can($perm)) continue; ?>
  <a class="card" href="<?= e(app_url($href)) ?>" style="display:block;"><div class="flex items-start gap-3"><span class="icon-tile"><?= icon($ic, 20) ?></span><div><div class="strong" style="font-size:15px;"><?= e($t) ?></div><div class="text-sm text-secondary mt-1"><?= e($d) ?></div></div></div></a>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
