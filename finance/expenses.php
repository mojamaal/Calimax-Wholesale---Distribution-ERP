<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('finance.view');

$pdo = db();
$period = resolve_report_period('this_month'); $cat = input_int($_GET, 'cat'); $hl = input_int($_GET, 'highlight'); $q = input($_GET, 'q');
$page = max(1, input_int($_GET, 'page', 1)); $perPage = 40;
if (is_post() && can('expenses.manage')) {
    verify_csrf(); $a = input($_POST, 'action');
    try {
        if ($a === 'add') { $id = expense_record(input_int($_POST, 'category_id'), input_date($_POST, 'expense_date', date('Y-m-d')), round2(input_float($_POST, 'amount')), input($_POST, 'method', 'cash'), input($_POST, 'payee'), input($_POST, 'reference'), input($_POST, 'description')); flash('success', 'Expense recorded and posted to the ledger.'); redirect('/finance/expenses.php?highlight=' . $id); }
        elseif ($a === 'category') {
            $name = input($_POST, 'name'); $acc = input_int($_POST, 'account_id');
            if ($name === '' || !$acc) throw new RuntimeException('Enter a category name and choose its ledger account.');
            $pdo->prepare('INSERT INTO expense_categories (name, account_id) VALUES (:n,:a)')->execute([':n' => $name, ':a' => $acc]); log_activity('expense_create', "Created expense category $name"); flash('success', 'Category added.');
        }
    } catch (PDOException $e) { flash('error', 'That category already exists.'); } catch (RuntimeException $e) { flash('error', $e->getMessage()); }
    redirect('/finance/expenses.php');
}
$where = 'e.expense_date BETWEEN :f AND :t'; $params = [':f' => $period['from'], ':t' => $period['to']];
if ($cat) { $where .= ' AND e.category_id = :c'; $params[':c'] = $cat; }
if ($q !== '') { $where .= ' AND (e.payee LIKE :q1 OR e.description LIKE :q2 OR e.exp_no LIKE :q3)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%"]; }
$from = "FROM expenses e JOIN expense_categories ec ON ec.id = e.category_id LEFT JOIN users u ON u.id = e.created_by WHERE $where";
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
$sm = $pdo->prepare("SELECT COALESCE(SUM(e.amount),0) $from"); $sm->execute($params); $sum = (float) $sm->fetchColumn();
$by = $pdo->prepare("SELECT ec.name, SUM(e.amount) AS total, COUNT(*) AS n $from GROUP BY ec.id ORDER BY total DESC"); $by->execute($params); $byCat = $by->fetchAll();
$st = $pdo->prepare("SELECT e.*, ec.name AS category, u.full_name AS who $from ORDER BY e.expense_date DESC, e.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage)); $st->execute($params); $rows = $st->fetchAll();
$cats = $pdo->query('SELECT id, name FROM expense_categories ORDER BY name')->fetchAll();
$expAcc = $pdo->query("SELECT id, code, name FROM accounts WHERE type = 'expense' ORDER BY code")->fetchAll();
$maxC = max(1, ...array_map(fn($r) => (float) $r['total'], $byCat ?: [['total' => 1]]));
$pageTitle = 'Expenses';
require __DIR__ . '/../includes/header.php';
page_header('Expenses', 'Warehouse rent, salaries, fuel, transport, maintenance and other running costs — each posted to the ledger.', can('expenses.manage') ? '<button class="btn btn-primary" type="button" data-modal-open="#expModal">' . icon('plus', 16) . ' Record expense</button>' : '');
nav_finance();
?>
<div class="card tight mb-4"><?php render_period_filter($period, ['cat' => $cat ?: '', 'q' => $q]); ?></div>
<div class="layout-2-1 mb-4">
  <div class="grid grid-2" style="align-content:start;"><?= kpi('Total expenses', format_money_short($sum), 'receipt-text', 'amber', e($period['label'])) ?><?= kpi('Entries', number_format($total), 'list') ?></div>
  <div class="card"><div class="card-head"><h3>By category</h3></div><?php foreach ($byCat as $c): ?><div class="hbar-row"><span><?= e($c['name']) ?></span><span class="hbar-track"><span class="hbar-fill" style="display:block;width:<?= round($c['total'] / $maxC * 100) ?>%"></span></span><span class="hbar-val"><?= format_money_short($c['total']) ?></span></div><?php endforeach; if (!$byCat) echo '<div class="text-muted text-sm">No expenses in this period.</div>'; ?></div>
</div>
<div class="card flush">
  <div style="padding:16px 16px 0;"><form method="get" class="filter-bar"><?php foreach (['preset' => $period['preset'] ?: 'custom', 'from' => $period['from'], 'to' => $period['to']] as $k => $v) echo '<input type="hidden" name="' . $k . '" value="' . e($v) . '">'; ?><div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Payee, description or number"></div><select class="form-control" name="cat" onchange="this.form.submit()"><option value="">All categories</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $cat === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></form></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Expense</th><th>Date</th><th>Category</th><th>Payee / description</th><th>Paid by</th><th>Recorded by</th><th class="num">Amount</th></tr></thead><tbody>
  <?php foreach ($rows as $x): ?><tr class="<?= $hl === (int) $x['id'] ? 'row-warn' : '' ?>"><td class="strong mono"><?= e($x['exp_no']) ?></td><td class="text-secondary nowrap"><?= e(fmt_date($x['expense_date'])) ?></td><td><span class="badge badge-neutral"><?= e($x['category']) ?></span></td><td><?= e($x['payee'] ?? '') ?><div class="cell-sub"><?= e($x['description'] ?? '') ?></div></td><td class="text-secondary"><?= e(method_label($x['method'])) ?><?= $x['reference'] ? '<div class="cell-sub mono">' . e($x['reference']) . '</div>' : '' ?></td><td class="text-secondary"><?= e($x['who'] ?? '—') ?></td><td class="num strong"><?= format_money($x['amount']) ?></td></tr><?php endforeach; ?></tbody></table>
  <?php if (!$rows) echo empty_state('receipt-text', 'No expenses', 'Record rent, salaries, fuel and other costs to see true profit.'); ?></div><?php render_pagination($page, $perPage, $total); ?></div>
<?php if (can('expenses.manage')): ?>
<div class="modal-overlay" id="expModal" hidden><div class="modal"><div class="modal-header"><h3>Record an expense</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="form-row"><div class="form-group"><label>Category</label><select class="form-control" name="category_id" required><option value="">Select…</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Date</label><input class="form-control" type="date" name="expense_date" value="<?= date('Y-m-d') ?>"></div></div>
    <div class="form-row"><div class="form-group"><label>Amount</label><input class="form-control num-input" type="number" step="0.01" min="0.01" name="amount" required></div><div class="form-group"><label>Paid by</label><select class="form-control" name="method"><?php foreach (money_methods() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div></div>
    <div class="form-row"><div class="form-group"><label>Payee</label><input class="form-control" type="text" name="payee" maxlength="120"></div><div class="form-group"><label>Reference</label><input class="form-control" type="text" name="reference" maxlength="60"></div></div>
    <div class="form-group"><label>Description</label><input class="form-control" type="text" name="description" maxlength="255"></div>
    <div class="modal-footer"><a class="link text-sm" style="margin-right:auto;" href="#" onclick="document.getElementById('catModal').hidden=false;document.getElementById('expModal').hidden=true;return false;">+ New category</a><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Save expense</button></div></form></div></div>
<div class="modal-overlay" id="catModal" hidden><div class="modal sm"><div class="modal-header"><h3>New expense category</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="category"><div class="form-group"><label>Name</label><input class="form-control" type="text" name="name" required maxlength="100"></div><div class="form-group"><label>Ledger account</label><select class="form-control" name="account_id" required><?php foreach ($expAcc as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['code'] . ' · ' . $a['name']) ?></option><?php endforeach; ?></select></div><div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Add</button></div></form></div></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
