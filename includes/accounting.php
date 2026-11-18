<?php
/**
 * Double-entry accounting engine.
 *
 * Every business event posts a balanced journal entry:
 *   Goods received         Dr Inventory            Cr Goods Received Not Invoiced
 *   Supplier invoice       Dr GRNI (+variance/tax) Cr Accounts Payable
 *   Supplier payment       Dr Accounts Payable     Cr Cash / Bank
 *   Goods dispatched       Dr Cost of Goods Sold   Cr Inventory
 *   Sales invoice          Dr Accounts Receivable  Cr Sales Revenue, Cr Output Tax
 *   Customer payment       Dr Cash / Bank          Cr Accounts Receivable
 *   Credit note            Dr Sales Returns, Output Tax   Cr Accounts Receivable
 *   Expense                Dr Expense account      Cr Cash / Bank
 */

function acct_id(string $code): int
{
    static $cache = [];
    if (!isset($cache[$code])) {
        $s = db()->prepare('SELECT id FROM accounts WHERE code = :c');
        $s->execute([':c' => $code]);
        $id = $s->fetchColumn();
        if (!$id) throw new RuntimeException("Account $code is missing from the chart of accounts.");
        $cache[$code] = (int) $id;
    }
    return $cache[$code];
}

/**
 * Post a balanced journal entry. $lines = [ [accountCode, debit, credit, memo?], ... ].
 * Must run inside the caller's transaction. Returns the entry id (or null when all lines are zero).
 */
function post_journal(PDO $pdo, string $date, string $description, ?string $refType, ?int $refId, ?string $refNo, array $lines): ?int
{
    $dr = 0.0; $cr = 0.0; $clean = [];
    foreach ($lines as $l) {
        $d = round2((float) ($l[1] ?? 0)); $c = round2((float) ($l[2] ?? 0));
        if ($d < 0 || $c < 0) { throw new RuntimeException('Journal amounts cannot be negative.'); }
        if ($d == 0.0 && $c == 0.0) continue;
        $dr += $d; $cr += $c;
        $clean[] = [$l[0], $d, $c, $l[3] ?? null];
    }
    if (!$clean) return null;
    if (abs($dr - $cr) > 0.009) {
        throw new RuntimeException(sprintf('Unbalanced journal for %s (Dr %.2f / Cr %.2f).', $description, $dr, $cr));
    }
    $no = next_no('journal', 'JV');
    $pdo->prepare('INSERT INTO journal_entries (je_no, entry_date, description, ref_type, ref_id, ref_no, created_by) VALUES (:n,:d,:ds,:rt,:ri,:rn,:u)')
        ->execute([':n' => $no, ':d' => $date, ':ds' => mb_substr($description, 0, 255), ':rt' => $refType, ':ri' => $refId, ':rn' => $refNo, ':u' => current_user()['id'] ?? null]);
    $id = (int) $pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO journal_lines (entry_id, account_id, debit, credit, memo) VALUES (:e,:a,:d,:c,:m)');
    foreach ($clean as [$code, $d, $c, $memo]) {
        $ins->execute([':e' => $id, ':a' => acct_id($code), ':d' => $d, ':c' => $c, ':m' => $memo]);
    }
    return $id;
}

/** Balance in the account's natural direction (assets/expenses: Dr-Cr, others: Cr-Dr). */
function account_balance(string $code, ?string $from = null, ?string $to = null): float
{
    $sql = 'SELECT a.type, COALESCE(SUM(jl.debit),0) d, COALESCE(SUM(jl.credit),0) c
            FROM accounts a LEFT JOIN journal_lines jl ON jl.account_id = a.id
            LEFT JOIN journal_entries je ON je.id = jl.entry_id
            WHERE a.code = :c' . ($from ? ' AND je.entry_date >= :f' : '') . ($to ? ' AND je.entry_date <= :t' : '') . ' GROUP BY a.id';
    $p = [':c' => $code];
    if ($from) $p[':f'] = $from;
    if ($to) $p[':t'] = $to;
    $s = db()->prepare($sql);
    $s->execute($p);
    $r = $s->fetch();
    if (!$r) return 0.0;
    return in_array($r['type'], ['asset', 'expense', 'cogs'], true) ? (float) $r['d'] - (float) $r['c'] : (float) $r['c'] - (float) $r['d'];
}

/** Natural-direction balances for all accounts in a period. [code => [id,code,name,type,debit,credit,balance]] */
function account_balances(?string $from = null, ?string $to = null): array
{
    $cond = ($from ? ' AND je.entry_date >= :f' : '') . ($to ? ' AND je.entry_date <= :t' : '');
    $p = [];
    if ($from) $p[':f'] = $from;
    if ($to) $p[':t'] = $to;
    $s = db()->prepare("SELECT a.id, a.code, a.name, a.type, COALESCE(SUM(x.debit),0) AS debit, COALESCE(SUM(x.credit),0) AS credit
        FROM accounts a LEFT JOIN (SELECT jl.account_id, jl.debit, jl.credit FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id WHERE 1=1 $cond) x ON x.account_id = a.id
        GROUP BY a.id ORDER BY a.code");
    $s->execute($p);
    $out = [];
    foreach ($s->fetchAll() as $r) {
        $r['balance'] = in_array($r['type'], ['asset', 'expense', 'cogs'], true) ? (float) $r['debit'] - (float) $r['credit'] : (float) $r['credit'] - (float) $r['debit'];
        $out[$r['code']] = $r;
    }
    return $out;
}

/** Profit & loss from the general ledger for a period. */
function gl_pnl(string $from, string $to): array
{
    $b = account_balances($from, $to);
    $bal = fn($c) => (float) ($b[$c]['balance'] ?? 0);
    $revenue = $bal('4000'); $returns = $bal('4100'); $other = $bal('4900');
    $cogs = $bal('5000'); $adj = $bal('5100'); $ppv = $bal('5200');
    $net_sales = $revenue - $returns;
    $gross = $net_sales - $cogs - $adj - $ppv;
    $opex = [];
    $opexTotal = 0.0;
    foreach ($b as $code => $r) {
        if ($r['type'] === 'expense' && abs($r['balance']) > 0.004) { $opex[] = $r; $opexTotal += $r['balance']; }
    }
    return [
        'revenue' => $revenue, 'returns' => $returns, 'net_sales' => $net_sales, 'cogs' => $cogs, 'adjustments' => $adj, 'ppv' => $ppv,
        'gross_profit' => $gross, 'gross_margin' => $net_sales > 0 ? $gross / $net_sales * 100 : 0,
        'other_income' => $other, 'opex' => $opex, 'opex_total' => $opexTotal,
        'net_profit' => $gross + $other - $opexTotal, 'net_margin' => $net_sales > 0 ? ($gross + $other - $opexTotal) / $net_sales * 100 : 0,
    ];
}

/** Position of the cash and bank accounts. */
function cash_position(): array
{
    return ['cash' => account_balance('1000'), 'bank' => account_balance('1010')];
}

/** Cash/bank statement lines for an account code between dates (running balance). */
function ledger_lines(string $code, ?string $from, ?string $to): array
{
    $acc = db()->prepare('SELECT * FROM accounts WHERE code = :c'); $acc->execute([':c' => $code]); $a = $acc->fetch();
    if (!$a) return ['account' => null, 'opening' => 0, 'lines' => []];
    $natural = in_array($a['type'], ['asset', 'expense', 'cogs'], true) ? 1 : -1;
    $open = 0.0;
    if ($from) {
        $o = db()->prepare('SELECT COALESCE(SUM(jl.debit - jl.credit),0) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id WHERE jl.account_id = :a AND je.entry_date < :f');
        $o->execute([':a' => $a['id'], ':f' => $from]);
        $open = (float) $o->fetchColumn() * $natural;
    }
    $q = 'SELECT je.id AS entry_id, je.je_no, je.entry_date, je.description, je.ref_type, je.ref_id, je.ref_no, jl.debit, jl.credit, jl.memo
          FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id WHERE jl.account_id = :a'
        . ($from ? ' AND je.entry_date >= :f' : '') . ($to ? ' AND je.entry_date <= :t' : '') . ' ORDER BY je.entry_date, je.id, jl.id';
    $p = [':a' => $a['id']];
    if ($from) $p[':f'] = $from;
    if ($to) $p[':t'] = $to;
    $s = db()->prepare($q);
    $s->execute($p);
    $run = $open; $lines = [];
    foreach ($s->fetchAll() as $r) {
        $run += ((float) $r['debit'] - (float) $r['credit']) * $natural;
        $r['balance'] = $run;
        $lines[] = $r;
    }
    return ['account' => $a, 'opening' => $open, 'lines' => $lines];
}

/** Where a journal's source document lives (for drill-down links). */
function journal_ref_link(?string $type, ?int $id): ?string
{
    $map = ['sales_invoice' => '/sales/invoice_view.php?id=', 'customer_payment' => '/sales/payments.php?highlight=', 'delivery' => '/sales/delivery_view.php?id=',
        'credit_note' => '/sales/credit_notes.php?highlight=', 'goods_receipt' => '/purchasing/grn_view.php?id=', 'supplier_invoice' => '/purchasing/bill_view.php?id=',
        'supplier_payment' => '/purchasing/payments.php?highlight=', 'expense' => '/finance/expenses.php?highlight=', 'stock_adjustment' => '/warehouse/adjustment_view.php?id=',
        'sales_return' => '/sales/return_view.php?id=', 'purchase_return' => '/purchasing/returns.php?highlight='];
    return $type && $id && isset($map[$type]) ? $map[$type] . $id : null;
}

// ---------------------------------------------------------------------
// Expenses
// ---------------------------------------------------------------------

function expense_record(int $categoryId, string $date, float $amount, string $method, ?string $payee, ?string $reference, ?string $description): int
{
    $pdo = db();
    if ($amount <= 0) throw new RuntimeException('Expense amount must be greater than zero.');
    $cat = $pdo->prepare('SELECT ec.*, a.code AS acct_code FROM expense_categories ec JOIN accounts a ON a.id = ec.account_id WHERE ec.id = :id');
    $cat->execute([':id' => $categoryId]);
    $c = $cat->fetch();
    if (!$c) throw new RuntimeException('Choose an expense category.');
    $started = !$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();
    try {
        $no = next_no('expense', 'EXP');
        $pdo->prepare('INSERT INTO expenses (exp_no, category_id, expense_date, amount, method, payee, reference, description, created_by) VALUES (:n,:c,:d,:a,:m,:p,:r,:ds,:u)')
            ->execute([':n' => $no, ':c' => $categoryId, ':d' => $date, ':a' => $amount, ':m' => $method, ':p' => $payee ?: null, ':r' => $reference ?: null, ':ds' => $description ?: null, ':u' => current_user()['id'] ?? null]);
        $id = (int) $pdo->lastInsertId();
        post_journal($pdo, $date, $c['name'] . ($payee ? ' Â· ' . $payee : ''), 'expense', $id, $no, [
            [$c['acct_code'], $amount, 0, $description], [method_account($method), 0, $amount],
        ]);
        if ($started) $pdo->commit();
    } catch (Throwable $e) { if ($started && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity('expense_create', "$no Â· {$c['name']} Â· " . format_money($amount) . ($payee ? " Â· $payee" : ''), 'expense', $id);
    return $id;
}

/** Owner capital / drawings / bank deposit / bank withdrawal. */
function fund_transfer(string $kind, string $date, float $amount, string $note): void
{
    $pdo = db();
    if ($amount <= 0) throw new RuntimeException('Amount must be greater than zero.');
    $map = [
        'capital_cash'   => ['Owner capital introduced (cash)',   [['1000', $amount, 0], ['3000', 0, $amount]]],
        'capital_bank'   => ['Owner capital introduced (bank)',   [['1010', $amount, 0], ['3000', 0, $amount]]],
        'drawings_cash'  => ['Owner drawings (cash)',             [['3000', $amount, 0], ['1000', 0, $amount]]],
        'drawings_bank'  => ['Owner drawings (bank)',             [['3000', $amount, 0], ['1010', 0, $amount]]],
        'bank_deposit'   => ['Cash deposited to bank',            [['1010', $amount, 0], ['1000', 0, $amount]]],
        'bank_withdraw'  => ['Cash withdrawn from bank',          [['1000', $amount, 0], ['1010', 0, $amount]]],
    ];
    if (!isset($map[$kind])) throw new RuntimeException('Unknown transfer type.');
    $pdo->beginTransaction();
    try {
        $id = post_journal($pdo, $date, $map[$kind][0] . ($note ? ' Â· ' . $note : ''), 'fund_transfer', null, null, $map[$kind][1]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    log_activity('fund_transfer', $map[$kind][0] . ' Â· ' . format_money($amount) . ($note ? ' Â· ' . $note : ''), 'journal', $id);
}
