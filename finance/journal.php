<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('finance.view');

$pdo = db();
$period = resolve_report_period('this_month'); $one = input_int($_GET, 'id'); $q = input($_GET, 'q');
$page = max(1, input_int($_GET, 'page', 1)); $perPage = 25;
$where = $one ? 'je.id = :id' : 'je.entry_date BETWEEN :f AND :t'; $params = $one ? [':id' => $one] : [':f' => $period['from'], ':t' => $period['to']];
if ($q !== '' && !$one) { $where .= ' AND (je.description LIKE :q1 OR je.ref_no LIKE :q2 OR je.je_no LIKE :q3)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%"]; }
$cnt = $pdo->prepare("SELECT COUNT(*) FROM journal_entries je WHERE $where"); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
$st = $pdo->prepare("SELECT je.*, u.full_name FROM journal_entries je LEFT JOIN users u ON u.id = je.created_by WHERE $where ORDER BY je.entry_date DESC, je.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage)); $st->execute($params); $entries = $st->fetchAll();
$lines = [];
if ($entries) { $ids = implode(',', array_map('intval', array_column($entries, 'id'))); foreach ($pdo->query("SELECT jl.*, a.code, a.name FROM journal_lines jl JOIN accounts a ON a.id = jl.account_id WHERE jl.entry_id IN ($ids) ORDER BY jl.id")->fetchAll() as $l) $lines[$l['entry_id']][] = $l; }
$pageTitle = 'Journal entries';
require __DIR__ . '/../includes/header.php';
page_header('Journal entries', 'The double-entry record behind every invoice, receipt, dispatch, payment and expense.', '<a class="btn btn-secondary" href="' . e(app_url('/finance/ledger.php')) . '">General ledger</a>', [['General ledger', '/finance/ledger.php'], ['Journal', null]]);
nav_finance();
if (!$one) { echo '<div class="card tight mb-4">'; render_period_filter($period, ['q' => $q]); echo '<form method="get" class="filter-bar mb-0 mt-3">'; foreach (['preset' => $period['preset'] ?: 'custom', 'from' => $period['from'], 'to' => $period['to']] as $k => $v) echo '<input type="hidden" name="' . $k . '" value="' . e($v) . '">'; echo '<div class="search-input"><i data-lucide="search" style="width:15px;height:15px"></i><input class="form-control" type="search" name="q" value="' . e($q) . '" placeholder="Search description or document number"></div></form></div>'; }
?>
<?php foreach ($entries as $je): $lk = journal_ref_link($je['ref_type'], $je['ref_id']); $dr = array_sum(array_map(fn($l) => (float) $l['debit'], $lines[$je['id']] ?? [])); ?>
<div class="card flush mb-3"><div class="card-head" style="padding-bottom:10px;"><div><div class="strong"><span class="mono"><?= e($je['je_no']) ?></span> · <?= e($je['description']) ?></div><div class="text-muted text-sm"><?= e(fmt_date($je['entry_date'])) ?> · <?= e($je['full_name'] ?? 'System') ?><?= $lk ? ' · <a class="link" href="' . e(app_url($lk)) . '">Open ' . e($je['ref_no'] ?: 'document') . '</a>' : '' ?></div></div><strong><?= format_money($dr) ?></strong></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Account</th><th class="num" style="width:160px;">Debit</th><th class="num" style="width:160px;">Credit</th></tr></thead><tbody><?php foreach ($lines[$je['id']] ?? [] as $l): ?><tr><td style="<?= (float) $l['credit'] > 0 ? 'padding-left:36px;' : '' ?>"><span class="mono text-secondary"><?= e($l['code']) ?></span> &nbsp;<?= e($l['name']) ?></td><td class="num"><?= (float) $l['debit'] ? format_money($l['debit']) : '' ?></td><td class="num"><?= (float) $l['credit'] ? format_money($l['credit']) : '' ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php endforeach; ?>
<?php if (!$entries) echo '<div class="card">' . empty_state('book-marked', 'No journal entries', 'Nothing was posted in this period.') . '</div>'; render_pagination($page, $perPage, $total); ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
