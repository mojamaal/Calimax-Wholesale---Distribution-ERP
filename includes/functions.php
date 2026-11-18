<?php
/**
 * General-purpose helpers shared across the app: settings, formatting,
 * document numbering, payment methods and UI building blocks.
 */
require_once __DIR__ . '/../config/db.php';

// ---------------------------------------------------------------------
// Settings & formatting
// ---------------------------------------------------------------------

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT setting_key, setting_value FROM settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

function format_money(float|string|null $amount): string
{
    $n = (float) $amount;
    return ($n < 0 ? '-' : '') . setting('currency_symbol', 'Rs.') . ' ' . number_format(abs($n), 2);
}

/** Compact money for KPI tiles: Rs. 1.25M / Rs. 845.2K / Rs. 950.00 */
function format_money_short(float|string|null $amount): string
{
    $n = (float) $amount;
    $abs = abs($n);
    $sym = setting('currency_symbol', 'Rs.');
    $sign = $n < 0 ? '-' : '';
    if ($abs >= 1_000_000) { return $sign . $sym . ' ' . number_format($abs / 1_000_000, 2) . 'M'; }
    if ($abs >= 100_000)   { return $sign . $sym . ' ' . number_format($abs / 1_000, 1) . 'K'; }
    return $sign . $sym . ' ' . number_format($abs, 2);
}

function fmt_date(?string $value): string
{
    return $value ? date('j M Y', strtotime($value)) : '—';
}

function fmt_datetime(?string $value): string
{
    return $value ? date('j M Y, g:i A', strtotime($value)) : '—';
}

/** Quantity with thousands separator. */
function fmt_qty(int|float|string|null $q): string
{
    return number_format((float) $q);
}

/** "10 Carton (240 Bottle)" – base-unit quantity expressed in a pack. */
function fmt_pack_qty(int $baseQty, int $factor, ?string $packName, string $baseUnit): string
{
    if ($factor > 1 && $packName && $baseQty % $factor === 0) {
        return number_format($baseQty / $factor) . ' ' . $packName . ' <span class="text-muted">(' . number_format($baseQty) . ' ' . e($baseUnit) . ')</span>';
    }
    return number_format($baseQty) . ' ' . e($baseUnit);
}

function redirect(string $path): never
{
    header('Location: ' . app_url($path));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function input(array $source, string $key, string $default = ''): string
{
    return isset($source[$key]) && is_string($source[$key]) ? trim($source[$key]) : $default;
}

function input_int(array $source, string $key, int $default = 0): int
{
    return isset($source[$key]) && is_numeric($source[$key]) ? (int) $source[$key] : $default;
}

function input_float(array $source, string $key, float $default = 0.0): float
{
    return isset($source[$key]) && is_numeric($source[$key]) ? (float) $source[$key] : $default;
}

/** A Y-m-d date from input, or the default when missing/invalid. */
function input_date(array $source, string $key, ?string $default = null): ?string
{
    $v = input($source, $key);
    if ($v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false) { return $v; }
    return $default;
}

/** Build a query string from the current GET params with overrides. */
function query_with(array $overrides): string
{
    $q = array_merge($_GET, $overrides);
    foreach ($q as $k => $v) { if ($v === null || $v === '') unset($q[$k]); }
    return $q ? '?' . http_build_query($q) : '?';
}

function round2(float $v): float { return round($v + 0.0000001, 2); }

/** "Today" for business transactions. Normally the real date; the demo-data seeder overrides it to back-date history. */
function app_today(): string
{
    return $GLOBALS['__today'] ?? date('Y-m-d');
}

// ---------------------------------------------------------------------
// Document numbering — race-safe sequences (PO-00045, INV-00125 ...)
// ---------------------------------------------------------------------

function next_no(string $key, string $prefix): string
{
    $pdo = db();
    $pdo->prepare('INSERT IGNORE INTO document_sequences (seq_key, next_value) VALUES (:k, 1)')->execute([':k' => $key]);
    // Atomic: LAST_INSERT_ID(expr) is connection-local, so concurrent users never share a number.
    $pdo->prepare('UPDATE document_sequences SET next_value = LAST_INSERT_ID(next_value + 1) WHERE seq_key = :k')->execute([':k' => $key]);
    $n = (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn() - 1;
    return sprintf('%s-%05d', $prefix, $n);
}

// ---------------------------------------------------------------------
// Payment methods
// ---------------------------------------------------------------------

function money_methods(): array
{
    return ['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'card' => 'Card', 'mobile_wallet' => 'Mobile payment'];
}

function method_label(?string $method): string
{
    return money_methods()[$method] ?? ucwords(str_replace('_', ' ', (string) $method));
}

/** GL account code a payment method settles into. */
function method_account(string $method): string
{
    return $method === 'cash' ? '1000' : '1010';
}

// ---------------------------------------------------------------------
// Reporting period resolver
// ---------------------------------------------------------------------

function resolve_report_period(string $defaultPreset = 'this_month'): array
{
    $quarterStartMonth = (int) (floor((((int) date('n')) - 1) / 3) * 3 + 1);
    $presets = [
        'today'        => ['label' => 'Today',        'from' => date('Y-m-d'), 'to' => date('Y-m-d')],
        'this_week'    => ['label' => 'This Week',    'from' => date('Y-m-d', strtotime('monday this week')), 'to' => date('Y-m-d')],
        'last_30'      => ['label' => 'Last 30 Days', 'from' => date('Y-m-d', strtotime('-29 days')), 'to' => date('Y-m-d')],
        'this_month'   => ['label' => 'This Month',   'from' => date('Y-m-01'), 'to' => date('Y-m-d')],
        'last_month'   => ['label' => 'Last Month',   'from' => date('Y-m-01', strtotime('first day of last month')), 'to' => date('Y-m-t', strtotime('last day of last month'))],
        'this_quarter' => ['label' => 'This Quarter', 'from' => date('Y') . '-' . str_pad((string) $quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01', 'to' => date('Y-m-d')],
        'this_year'    => ['label' => 'This Year',    'from' => date('Y-01-01'), 'to' => date('Y-m-d')],
    ];
    $preset = input($_GET, 'preset');
    if ($preset === '' && !isset($_GET['from']) && !isset($_GET['to'])) { $preset = $defaultPreset; }
    if ($preset !== '' && isset($presets[$preset])) {
        $from = $presets[$preset]['from']; $to = $presets[$preset]['to']; $label = $presets[$preset]['label'];
    } else {
        $from = input_date($_GET, 'from', date('Y-m-01'));
        $to = input_date($_GET, 'to', date('Y-m-d'));
        if ($from > $to) { [$from, $to] = [$to, $from]; }
        $preset = '';
        $label = fmt_date($from) . ' – ' . fmt_date($to);
    }
    return ['from' => $from, 'to' => $to, 'preset' => $preset, 'presets' => $presets, 'label' => $label];
}

/** [from 00:00:00, to+1day 00:00:00) bounds so DATETIME indexes are usable. */
function period_bounds(string $from, string $to): array
{
    return [':f' => $from . ' 00:00:00', ':t' => date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
}

// ---------------------------------------------------------------------
// UI helpers
// ---------------------------------------------------------------------

function icon(string $name, int $size = 16, string $extra = ''): string
{
    return '<i data-lucide="' . e($name) . '" style="width:' . $size . 'px;height:' . $size . 'px" ' . $extra . '></i>';
}

/** Colored status badge for any document / entity status. */
function status_badge(?string $status): string
{
    $map = [
        // good / done
        'completed' => 'success', 'received' => 'success', 'approved' => 'success', 'paid' => 'success', 'active' => 'success', 'delivered' => 'success',
        'available' => 'success', 'matched' => 'success', 'resolved' => 'success', 'closed' => 'neutral', 'sold' => 'info', 'recovered' => 'success',
        // in progress
        'confirmed' => 'info', 'sent' => 'info', 'dispatched' => 'orange', 'picked' => 'info', 'packed' => 'info', 'in_transit' => 'orange', 'open' => 'info',
        'in_repair' => 'info', 'contacted' => 'info', 'reserved' => 'info', 'requested' => 'warning',
        // needs attention
        'pending_approval' => 'warning', 'pending_pick' => 'warning', 'pending' => 'warning', 'partially_fulfilled' => 'warning', 'partially_received' => 'warning',
        'partial' => 'warning', 'unpaid' => 'warning', 'variance' => 'warning', 'unmatched' => 'neutral', 'under_inspection' => 'warning', 'under_repair' => 'warning',
        'returned' => 'warning', 'quarantined' => 'warning', 'submitted' => 'warning',
        // bad
        'cancelled' => 'error', 'rejected' => 'error', 'failed' => 'error', 'recalled' => 'error', 'expired' => 'error', 'damaged' => 'error', 'void' => 'error',
        'returned_to_supplier' => 'neutral', 'draft' => 'neutral', 'replaced' => 'info',
    ];
    $labels = ['pending_approval' => 'Awaiting approval', 'partially_fulfilled' => 'Part fulfilled', 'partially_received' => 'Part received', 'pending_pick' => 'To pick',
        'in_transit' => 'In transit', 'in_repair' => 'In repair', 'under_inspection' => 'Under inspection', 'under_repair' => 'Under repair', 'returned_to_supplier' => 'Returned to supplier',
        'unpaid' => 'Unpaid', 'partial' => 'Part paid'];
    $s = (string) $status;
    return '<span class="badge badge-' . ($map[$s] ?? 'neutral') . '">' . e($labels[$s] ?? ucwords(str_replace('_', ' ', $s))) . '</span>';
}

/** Expiry badge for a date: Expired / 12 days / 3 months. */
function expiry_badge(?string $expiry, ?string $batchStatus = null): string
{
    if (!$expiry) return '<span class="text-muted">No expiry</span>';
    $days = (int) floor((strtotime($expiry) - strtotime(date('Y-m-d'))) / 86400);
    $crit = (int) setting('expiry_critical_days', '30'); $warn = (int) setting('expiry_warning_days', '90');
    if ($days < 0) return '<span class="badge badge-error">Expired ' . abs($days) . 'd ago</span>';
    if ($days <= $crit) return '<span class="badge badge-error">Expiring · ' . $days . 'd</span>';
    if ($days <= $warn) return '<span class="badge badge-warning">Upcoming · ' . $days . 'd</span>';
    return '<span class="badge badge-success">Normal · ' . $days . 'd</span>';
}

function empty_state(string $iconName, string $title, string $hint = '', string $actionHtml = ''): string
{
    return '<div class="empty-state"><div class="icon-tile">' . icon($iconName, 22) . '</div><div class="title">' . e($title) . '</div>'
        . ($hint ? '<div class="hint">' . e($hint) . '</div>' : '') . $actionHtml . '</div>';
}

/** Module tab bar. $items: [ [href, label, permission?, icon?, [alias scripts]] ]. */
function render_subnav(array $items): void
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    echo '<nav class="subnav" aria-label="Section">';
    foreach ($items as $it) {
        [$href, $label] = $it;
        $perm = $it[2] ?? null;
        $ico = $it[3] ?? null;
        if ($perm && !can_any((array) $perm)) continue;
        $path = parse_url($href, PHP_URL_PATH);
        $active = str_ends_with($script, $path) ? ' active' : '';
        foreach ($it[4] ?? [] as $alias) { if (str_ends_with($script, $alias)) $active = ' active'; }
        echo '<a class="subnav-item' . $active . '" href="' . e(app_url($href)) . '">' . ($ico ? icon($ico, 15) : '') . '<span>' . e($label) . '</span></a>';
    }
    echo '</nav>';
}

/** Standard page header. */
function page_header(string $title, string $subtitle = '', string $actionsHtml = '', array $crumbs = []): void
{
    echo '<div class="page-head"><div class="page-head-text">';
    if ($crumbs) {
        echo '<div class="crumbs">';
        foreach ($crumbs as $i => [$label, $href]) {
            echo ($i ? '<span class="sep">/</span>' : '') . ($href ? '<a href="' . e(app_url($href)) . '">' . e($label) . '</a>' : '<span>' . e($label) . '</span>');
        }
        echo '</div>';
    }
    echo '<h1 class="page-title">' . e($title) . '</h1>';
    if ($subtitle !== '') echo '<p class="page-subtitle">' . $subtitle . '</p>';
    echo '</div>';
    if ($actionsHtml !== '') echo '<div class="page-actions">' . $actionsHtml . '</div>';
    echo '</div>';
}

/** KPI tile. */
function kpi(string $label, string $value, string $iconName = '', string $tone = '', string $desc = '', ?string $href = null): string
{
    $tag = $href ? 'a href="' . e(app_url($href)) . '"' : 'div';
    $close = $href ? 'a' : 'div';
    return '<' . $tag . ' class="kpi-card' . ($href ? ' is-link' : '') . '">'
        . '<div class="kpi-top"><span class="kpi-label">' . e($label) . '</span>' . ($iconName ? '<span class="icon-tile sm ' . e($tone) . '">' . icon($iconName, 16) . '</span>' : '') . '</div>'
        . '<div class="kpi-value">' . $value . '</div>'
        . ($desc !== '' ? '<div class="kpi-desc">' . $desc . '</div>' : '')
        . '</' . $close . '>';
}

/** Pagination controls. */
function render_pagination(int $page, int $perPage, int $total): void
{
    $pages = (int) ceil($total / max(1, $perPage));
    if ($pages <= 1) return;
    echo '<div class="pagination"><span class="text-muted">Page ' . $page . ' of ' . $pages . ' · ' . number_format($total) . ' records</span><div class="flex gap-2">';
    if ($page > 1) echo '<a class="btn btn-secondary btn-sm" href="' . e(query_with(['page' => $page - 1])) . '">' . icon('chevron-left', 14) . ' Prev</a>';
    if ($page < $pages) echo '<a class="btn btn-secondary btn-sm" href="' . e(query_with(['page' => $page + 1])) . '">Next ' . icon('chevron-right', 14) . '</a>';
    echo '</div></div>';
}

/** Period preset chips + custom range, as a GET form. */
function render_period_filter(array $period, array $extraHidden = []): void
{
    echo '<form method="get" class="filter-bar period-filter">';
    foreach ($extraHidden as $k => $v) { if ($v !== '' && $v !== null) echo '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">'; }
    echo '<div class="chip-group">';
    foreach ($period['presets'] as $key => $p) {
        echo '<a class="chip' . ($period['preset'] === $key ? ' active' : '') . '" href="' . e(query_with(['preset' => $key, 'from' => null, 'to' => null, 'page' => null])) . '">' . e($p['label']) . '</a>';
    }
    echo '</div><div class="range-inputs"><input class="form-control sm" type="date" name="from" value="' . e($period['from']) . '" aria-label="From date">'
        . '<span class="text-muted">to</span><input class="form-control sm" type="date" name="to" value="' . e($period['to']) . '" aria-label="To date">'
        . '<input type="hidden" name="preset" value="custom"><button class="btn btn-secondary btn-sm" type="submit">Apply</button></div></form>';
}

/**
 * Horizontal workflow tracker. $steps: [key => label]; $current: key of the active step;
 * steps before it are "done". Pass $failed = true to colour the current step red.
 */
function workflow_steps(array $steps, string $current, bool $failed = false): string
{
    $keys = array_keys($steps);
    $idx = array_search($current, $keys, true);
    $html = '<div class="steps wf">';
    $n = 0;
    foreach ($steps as $k => $label) {
        $n++;
        $cls = $idx === false ? '' : ($n - 1 < $idx ? 'done' : ($n - 1 === $idx ? ($failed ? 'failed' : 'current') : ''));
        $html .= '<div class="step ' . $cls . '"><span class="n">' . ($cls === 'done' ? icon('check', 12) : $n) . '</span>' . e($label) . '</div>';
    }
    return $html . '</div>';
}

/** "What to do next" call-out used on document pages to guide the user. */
function next_step(string $iconName, string $title, string $text, string $actionsHtml = '', string $tone = 'info'): string
{
    return '<div class="next-step ' . e($tone) . '"><span class="icon-tile ' . ($tone === 'warn' ? 'amber' : ($tone === 'bad' ? 'red' : ($tone === 'good' ? 'green' : 'blue'))) . '">' . icon($iconName, 20) . '</span>'
        . '<div class="ns-text"><div class="ns-title">' . e($title) . '</div><div class="ns-sub">' . $text . '</div></div>'
        . ($actionsHtml !== '' ? '<div class="ns-actions">' . $actionsHtml . '</div>' : '') . '</div>';
}

/** Ageing bucket for days overdue. */
function ageing_bucket(int $daysOverdue): string
{
    if ($daysOverdue <= 0) return 'current';
    if ($daysOverdue <= 30) return 'd1_30';
    if ($daysOverdue <= 60) return 'd31_60';
    if ($daysOverdue <= 90) return 'd61_90';
    return 'd90p';
}

function ageing_labels(): array
{
    return ['current' => 'Not due', 'd1_30' => '1–30 days', 'd31_60' => '31–60 days', 'd61_90' => '61–90 days', 'd90p' => '90+ days'];
}

/** Escape + keep as a link to a document view. */
function doc_link(string $path, int|string $id, string $label): string
{
    return '<a class="link" href="' . e(app_url($path . '?id=' . $id)) . '">' . e($label) . '</a>';
}

/** Json for embedding in <script>. */
function js_json(mixed $data): string
{
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}
