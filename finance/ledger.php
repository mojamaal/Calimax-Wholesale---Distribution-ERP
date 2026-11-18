<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('finance.view');

$pdo = db();
$period = resolve_report_period('this_year');
$code = input($_GET, 'acct', '');
$accounts = $pdo->query('SELECT * FROM accounts WHERE is_active = 1 ORDER BY code')->fetchAll();
$bal = account_balances(null, $period['to']);
$led = $code !== '' ? ledger_lines($code, $period['from'], $period['to']) : null;
$types = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'income' => 'Income', 'cogs' => 'Cost of sales', 'expense' => 'Expenses'];
$pageTitle = 'General ledger';
require __DIR__ . '/../includes/header.php';
page_header('General ledger', 'Every business event is posted as a balanced double-entry journal. Open an account to see every line behind its balance.', '<a class="btn btn-secondary" href="' . e(app_url('/finance/journal.php')) . '">' . icon('book-marked', 16) . ' Journal entries</a>');
nav_finance();
?>
<div class="card tight mb-4"><?php render_period_filter($period, ['acct' => $code]); ?></div>
<?php if ($led && $led['account']): $a = $led['account']; $d = array_sum(array_map(fn($l) => (float) $l['debit'], $led['lines'])); $c = array_sum(array_map(fn($l) => (float) $l['credit'], $led['lines'])); ?>
  <div class="flex items-center justify-between mb-3"><div><h3><?= e($a['code'] . ' · ' . $a['name']) ?></h3><div class="text-muted text-sm"><?= e($types[$a['type']]) ?> · <?= e($period['label']) ?></div></div><a class="btn btn-secondary btn-sm" href="<?= e(query_with(['acct' => null])) ?>">← All accounts</a></div>
  <div class="stat-strip"><div><div class="sl">Opening</div><div class="sv"><?= format_money($led['opening']) ?></div></div><div><div class="sl">Debits</div><div class="sv"><?= format_money($d) ?></div></div><div><div class="sl">Credits</div><div class="sv"><?= format_money($c) ?></div></div><div><div class="sl">Closing</div><div class="sv"><?= format_money(end($led['lines'])['balance'] ?? $led['opening']) ?></div></div></div>
  <div class="card flush"><div class="table-wrap"><table class="data-table"><thead><tr><th>Date</th><th>Journal</th><th>Description</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead><tbody>
  <?php foreach ($led['lines'] as $l): $lk = journal_ref_link($l['ref_type'], $l['ref_id']); ?><tr><td class="text-secondary nowrap"><?= e(fmt_date($l['entry_date'])) ?></td><td class="mono text-sm"><a class="link" href="<?= e(app_url('/finance/journal.php?id=' . $l['entry_id'])) ?>"><?= e($l['je_no']) ?></a></td><td><?= $lk ? '<a class="link" href="' . e(app_url($lk)) . '">' . e($l['description']) . '</a>' : e($l['description']) ?></td><td class="num"><?= (float) $l['debit'] ? format_money($l['debit']) : '' ?></td><td class="num"><?= (float) $l['credit'] ? format_money($l['credit']) : '' ?></td><td class="num strong"><?= format_money($l['balance']) ?></td></tr><?php endforeach; ?></tbody></table><?php if (!$led['lines']) echo empty_state('book-open', 'No postings in this period'); ?></div></div>
<?php else:
    $td = 0; $tc = 0; foreach ($bal as $b) { $net = (float) $b['debit'] - (float) $b['credit']; $td += $net > 0 ? $net : 0; $tc += $net < 0 ? -$net : 0; } ?>
  <div class="card flush"><div class="card-head"><div><h3>Trial balance</h3><div class="sub">As at <?= e(fmt_date($period['to'])) ?> · debits must equal credits</div></div><span class="badge badge-<?= abs($td - $tc) < 0.01 ? 'success' : 'error' ?>"><?= abs($td - $tc) < 0.01 ? 'Balanced' : 'Out of balance' ?></span></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Account</th><th>Type</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>
  <?php foreach ($bal as $b): $net = (float) $b['debit'] - (float) $b['credit']; if (abs($net) < 0.005) continue; ?><tr><td><a class="strong" href="<?= e(app_url('/finance/ledger.php?acct=' . $b['code'] . '&preset=custom&from=2000-01-01&to=' . $period['to'])) ?>"><span class="mono text-secondary"><?= e($b['code']) ?></span> &nbsp;<?= e($b['name']) ?></a></td><td class="text-secondary"><?= e($types[$b['type']]) ?></td><td class="num"><?= $net > 0 ? format_money($net) : '' ?></td><td class="num"><?= $net < 0 ? format_money(-$net) : '' ?></td></tr><?php endforeach; ?></tbody>
  <tfoot><tr><td colspan="2">Total</td><td class="num"><?= format_money($td) ?></td><td class="num"><?= format_money($tc) ?></td></tr></tfoot></table></div></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
