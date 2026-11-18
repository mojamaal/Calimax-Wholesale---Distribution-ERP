<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('finance.view');

$pdo = db();
$period = resolve_report_period('this_month');
$acct = input($_GET, 'acct') === 'bank' ? 'bank' : 'cash'; $code = $acct === 'bank' ? '1010' : '1000';
if (is_post() && can('expenses.manage')) {
    verify_csrf();
    try { fund_transfer(input($_POST, 'kind'), input_date($_POST, 'date', date('Y-m-d')), round2(input_float($_POST, 'amount')), input($_POST, 'note')); flash('success', 'Recorded and posted to the ledger.'); }
    catch (RuntimeException $e) { flash('error', $e->getMessage()); }
    redirect('/finance/cashbook.php?acct=' . $acct);
}
$pos = cash_position();
$led = ledger_lines($code, $period['from'], $period['to']);
$in = array_sum(array_map(fn($l) => (float) $l['debit'], $led['lines'])); $out = array_sum(array_map(fn($l) => (float) $l['credit'], $led['lines']));
$pageTitle = 'Cash & bank';
require __DIR__ . '/../includes/header.php';
page_header('Cash & bank', 'Every receipt and payment through the cash drawer and the bank account, with a running balance.', can('expenses.manage') ? '<button class="btn btn-secondary" type="button" data-modal-open="#fundModal">' . icon('arrow-left-right', 16) . ' Funds / transfer</button>' : '');
nav_finance();
?>
<div class="grid grid-3 mb-4"><?= kpi('Cash in hand', format_money($pos['cash']), 'banknote', 'green', 'Petty cash and till', '/finance/cashbook.php?acct=cash') ?><?= kpi('Bank balance', format_money($pos['bank']), 'landmark', 'blue', 'Transfers, cheques, cards', '/finance/cashbook.php?acct=bank') ?><?= kpi('Total liquid funds', format_money($pos['cash'] + $pos['bank']), 'wallet', 'purple', 'Cash + bank') ?></div>
<div class="card tight mb-4"><div class="segmented mb-3"><label><a href="<?= e(query_with(['acct' => 'cash'])) ?>" style="display:contents;"><input type="radio" <?= $acct === 'cash' ? 'checked' : '' ?>><span>Cash in hand</span></a></label><label><a href="<?= e(query_with(['acct' => 'bank'])) ?>" style="display:contents;"><input type="radio" <?= $acct === 'bank' ? 'checked' : '' ?>><span>Bank account</span></a></label></div><?php render_period_filter($period, ['acct' => $acct]); ?></div>
<div class="stat-strip"><div><div class="sl">Opening balance</div><div class="sv"><?= format_money($led['opening']) ?></div></div><div><div class="sl">Money in</div><div class="sv text-success"><?= format_money($in) ?></div></div><div><div class="sl">Money out</div><div class="sv text-error"><?= format_money($out) ?></div></div><div><div class="sl">Closing balance</div><div class="sv"><?= format_money($led['opening'] + $in - $out) ?></div></div></div>
<div class="card flush"><div class="table-wrap"><table class="data-table"><thead><tr><th>Date</th><th>Entry</th><th>Description</th><th class="num">In</th><th class="num">Out</th><th class="num">Balance</th></tr></thead><tbody>
<tr class="row-muted"><td colspan="5"><em>Opening balance</em></td><td class="num"><?= format_money($led['opening']) ?></td></tr>
<?php foreach ($led['lines'] as $l): $lk = journal_ref_link($l['ref_type'], $l['ref_id']); ?><tr><td class="text-secondary nowrap"><?= e(fmt_date($l['entry_date'])) ?></td><td class="mono text-sm"><?= e($l['je_no']) ?></td><td><?= $lk ? '<a class="link" href="' . e(app_url($lk)) . '">' . e($l['description']) . '</a>' : e($l['description']) ?></td><td class="num text-success"><?= (float) $l['debit'] ? format_money($l['debit']) : '' ?></td><td class="num text-error"><?= (float) $l['credit'] ? format_money($l['credit']) : '' ?></td><td class="num strong"><?= format_money($l['balance']) ?></td></tr><?php endforeach; ?></tbody></table>
<?php if (!$led['lines']) echo empty_state('landmark', 'No transactions in this period'); ?></div></div>
<?php if (can('expenses.manage')): ?><div class="modal-overlay" id="fundModal" hidden><div class="modal sm"><div class="modal-header"><h3>Funds & transfers</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><div class="form-group"><label>Type</label><select class="form-control" name="kind"><option value="bank_deposit">Deposit cash into bank</option><option value="bank_withdraw">Withdraw cash from bank</option><option value="capital_bank">Owner capital introduced → bank</option><option value="capital_cash">Owner capital introduced → cash</option><option value="drawings_bank">Owner drawings ← bank</option><option value="drawings_cash">Owner drawings ← cash</option></select></div>
    <div class="form-row"><div class="form-group"><label>Amount</label><input class="form-control num-input" type="number" step="0.01" min="0.01" name="amount" required></div><div class="form-group"><label>Date</label><input class="form-control" type="date" name="date" value="<?= date('Y-m-d') ?>"></div></div><div class="form-group"><label>Note</label><input class="form-control" type="text" name="note" maxlength="150"></div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Record</button></div></form></div></div><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
