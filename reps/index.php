<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reps.view');

$pdo = db();
$month = preg_match('/^\d{4}-\d{2}$/', input($_GET, 'month')) ? input($_GET, 'month') : date('Y-m');
$from = $month . '-01'; $to = date('Y-m-t', strtotime($from)); if ($to > date('Y-m-d')) $to = date('Y-m-d');
if (is_post()) {
    verify_csrf();
    $uid = input_int($_POST, 'user_id'); $amt = max(0, input_float($_POST, 'target'));
    try {
        $old = $pdo->prepare('SELECT target_amount FROM rep_targets WHERE user_id = :u AND month = :m'); $old->execute([':u' => $uid, ':m' => $month]); $o = $old->fetchColumn();
        $pdo->prepare('INSERT INTO rep_targets (user_id, month, target_amount) VALUES (:u,:m,:t) ON DUPLICATE KEY UPDATE target_amount = :t2')->execute([':u' => $uid, ':m' => $month, ':t' => $amt, ':t2' => $amt]);
        $nm = $pdo->query('SELECT full_name FROM users WHERE id = ' . $uid)->fetchColumn(); log_change('target_update', "Sales target for $month", $o === false ? null : format_money($o), format_money($amt), 'user', $uid, (string) $nm);
        flash('success', 'Target saved.');
    } catch (Throwable $e) { flash('error', 'Could not save the target.'); }
    redirect('/reps/index.php?month=' . $month);
}
$reps = $pdo->query("SELECT u.id, u.full_name, u.phone, t.name AS territory FROM users u LEFT JOIN territories t ON t.id = u.territory_id WHERE u.role = 'sales_rep' AND u.is_active = 1 ORDER BY u.full_name")->fetchAll();
$data = []; $team = ['sales' => 0, 'orders' => 0, 'coll' => 0, 'out' => 0, 'target' => 0, 'custs' => 0];
foreach ($reps as $r) {
    $id = (int) $r['id'];
    $s = $pdo->prepare("SELECT COALESCE(SUM(sii.line_net),0) FROM sales_invoice_items sii JOIN sales_invoices si ON si.id = sii.invoice_id WHERE si.rep_id = :r AND si.status <> 'void' AND si.invoice_date BETWEEN :f AND :t"); $s->execute([':r' => $id, ':f' => $from, ':t' => $to]);
    $o = $pdo->prepare("SELECT COUNT(*) FROM sales_orders WHERE rep_id = :r AND status NOT IN ('draft','cancelled','rejected') AND order_date BETWEEN :f AND :t"); $o->execute([':r' => $id, ':f' => $from, ':t' => $to]);
    $c = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM customer_payments WHERE rep_id = :r AND pay_date BETWEEN :f AND :t'); $c->execute([':r' => $id, ':f' => $from, ':t' => $to]);
    $a = $pdo->prepare("SELECT COUNT(DISTINCT customer_id) FROM sales_invoices WHERE rep_id = :r AND status <> 'void' AND invoice_date >= DATE_SUB(:t, INTERVAL 90 DAY)"); $a->execute([':r' => $id, ':t' => $to]);
    $tc = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE rep_id = :r AND is_active = 1'); $tc->execute([':r' => $id]);
    $ou = $pdo->prepare("SELECT COALESCE(SUM(si.total - si.paid_amount - si.credited_amount),0), COALESCE(SUM(CASE WHEN si.due_date < CURDATE() THEN si.total - si.paid_amount - si.credited_amount ELSE 0 END),0) FROM sales_invoices si JOIN customers c ON c.id = si.customer_id WHERE c.rep_id = :r AND si.status IN ('unpaid','partial')"); $ou->execute([':r' => $id]); [$out, $ovd] = $ou->fetch(PDO::FETCH_NUM);
    $tg = $pdo->prepare('SELECT target_amount FROM rep_targets WHERE user_id = :u AND month = :m'); $tg->execute([':u' => $id, ':m' => $month]); $target = (float) $tg->fetchColumn();
    $d = $r + ['sales' => (float) $s->fetchColumn(), 'orders' => (int) $o->fetchColumn(), 'coll' => (float) $c->fetchColumn(), 'active' => (int) $a->fetchColumn(), 'custs' => (int) $tc->fetchColumn(), 'out' => (float) $out, 'ovd' => (float) $ovd, 'target' => $target];
    $data[] = $d; foreach (['sales', 'orders', 'coll', 'out', 'target', 'custs'] as $k) $team[$k] += $d[$k];
}
usort($data, fn($a, $b) => $b['sales'] <=> $a['sales']);
$max = max(1, ...array_map(fn($d) => max($d['sales'], $d['target']), $data ?: [['sales' => 1, 'target' => 0]]));
$months = []; for ($i = 0; $i < 12; $i++) $months[] = date('Y-m', strtotime("first day of -$i month"));
$pageTitle = 'Sales rep performance';
require __DIR__ . '/../includes/header.php';
page_header('Sales rep performance', 'Sales, collections, active customers and targets for each representative — ' . date('F Y', strtotime($from)) . ($to < date('Y-m-t', strtotime($from)) ? ' to date' : '') . '.');
nav_reports();
?>
<form method="get" class="filter-bar"><select class="form-control" name="month" onchange="this.form.submit()"><?php foreach ($months as $m): ?><option value="<?= e($m) ?>" <?= $month === $m ? 'selected' : '' ?>><?= e(date('F Y', strtotime($m . '-01'))) ?></option><?php endforeach; ?></select></form>
<div class="grid grid-4 mb-4"><?= kpi('Team sales', format_money_short($team['sales']), 'banknote', '', $team['target'] > 0 ? number_format($team['sales'] / $team['target'] * 100, 0) . '% of ' . format_money_short($team['target']) . ' target' : 'No targets set') ?><?= kpi('Orders', number_format($team['orders']), 'clipboard-list', 'blue', 'Confirmed or completed') ?><?= kpi('Collections', format_money_short($team['coll']), 'hand-coins', 'green', 'Customer payments received') ?><?= kpi('Customers owe', format_money_short($team['out']), 'wallet', 'amber', number_format($team['custs']) . ' customer accounts') ?></div>
<div class="card mb-4"><div class="card-head"><h3>Target vs actual</h3><span class="text-muted text-sm">Net sales (excl. tax)</span></div>
<?php foreach ($data as $d): $pct = $d['target'] > 0 ? min(150, $d['sales'] / $d['target'] * 100) : null; ?>
  <div style="margin-bottom:14px;"><div class="flex justify-between text-sm mb-1"><span class="strong"><?= e($d['full_name']) ?> <span class="text-muted"><?= e($d['territory'] ?? '') ?></span></span><span><strong><?= format_money_short($d['sales']) ?></strong> <?= $d['target'] > 0 ? 'of ' . format_money_short($d['target']) . ' · <strong class="' . ($pct >= 100 ? 'text-success' : ($pct >= 70 ? 'text-warning' : 'text-error')) . '">' . round($pct) . '%</strong>' : '<span class="text-muted">no target</span>' ?></span></div>
    <div class="meter <?= $pct === null ? '' : ($pct >= 100 ? '' : ($pct >= 70 ? 'warn' : 'bad')) ?>" style="height:10px;"><span style="width:<?= $pct === null ? round($d['sales'] / $max * 100) : min(100, round($pct)) ?>%"></span></div></div>
<?php endforeach; if (!$data) echo empty_state('users', 'No sales reps', 'Add staff with the Sales Representative role.'); ?></div>
<div class="card flush"><div class="table-wrap"><table class="data-table"><thead><tr><th>Representative</th><th class="num">Sales</th><th class="num">Orders</th><th class="num">Active customers</th><th class="num">Collections</th><th class="num">Outstanding</th><th class="num">Overdue</th><th class="num">Target</th><th></th></tr></thead><tbody>
<?php foreach ($data as $d): ?><tr><td><span class="strong"><?= e($d['full_name']) ?></span><div class="cell-sub"><?= e($d['territory'] ?? '—') ?> · <?= e($d['phone'] ?? '') ?></div></td><td class="num strong"><?= format_money($d['sales']) ?></td><td class="num"><?= (int) $d['orders'] ?></td><td class="num"><?= (int) $d['active'] ?> <span class="text-muted text-sm">/ <?= (int) $d['custs'] ?></span></td><td class="num text-success"><?= format_money($d['coll']) ?></td><td class="num"><?= format_money($d['out']) ?></td><td class="num <?= $d['ovd'] > 0 ? 'text-error strong' : 'text-muted' ?>"><?= $d['ovd'] > 0 ? format_money($d['ovd']) : '—' ?></td><td class="num"><?= $d['target'] > 0 ? format_money($d['target']) : '—' ?></td>
  <td class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/sales/customers.php?rep=' . $d['id'])) ?>">Customers</a><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/reports/sales.php?by=customer&rep=' . $d['id'] . '&preset=custom&from=' . $from . '&to=' . $to)) ?>">Sales</a><button class="btn btn-secondary btn-sm" type="button" onclick='setT(<?= js_json(['id' => $d['id'], 'name' => $d['full_name'], 'target' => $d['target']]) ?>)'>Set target</button></td></tr><?php endforeach; ?></tbody>
<tfoot><tr><td>Team</td><td class="num"><?= format_money($team['sales']) ?></td><td class="num"><?= (int) $team['orders'] ?></td><td></td><td class="num"><?= format_money($team['coll']) ?></td><td class="num"><?= format_money($team['out']) ?></td><td></td><td class="num"><?= format_money($team['target']) ?></td><td></td></tr></tfoot></table></div></div>
<div class="modal-overlay" id="tModal" hidden><div class="modal sm"><div class="modal-header"><h3>Sales target — <span id="tName"></span></h3><button class="modal-close" type="button" data-modal-close>&times;</button></div><form method="post"><?= csrf_field() ?><input type="hidden" name="user_id" id="tId"><div class="form-group"><label>Target for <?= e(date('F Y', strtotime($from))) ?> (net sales)</label><input class="form-control num-input" type="number" step="1000" min="0" name="target" id="tVal" required></div><div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Save target</button></div></form></div></div>
<script>function setT(d){document.getElementById('tId').value=d.id;document.getElementById('tName').textContent=d.name;document.getElementById('tVal').value=d.target||'';document.getElementById('tModal').hidden=false;}</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
