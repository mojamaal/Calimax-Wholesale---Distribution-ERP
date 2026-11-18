<?php
/**
 * Authentication, role-based authorization (RBAC), brute-force protection
 * and the audit-log writer.
 */
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../config/db.php';

start_secure_session();

// ---------------------------------------------------------------------
// Roles & permissions
// ---------------------------------------------------------------------

/** Business roles of a trading / wholesale / distribution company. */
function role_definitions(): array
{
    return [
        'owner'             => ['label' => 'Owner / Director',  'desc' => 'Full access to every module, approvals, staff accounts and settings.'],
        'sales_manager'     => ['label' => 'Sales Manager',     'desc' => 'Approves orders and credit exceptions, manages customers, prices and sales reps.'],
        'sales_rep'         => ['label' => 'Sales Representative', 'desc' => 'Creates customer orders, records collections and sees their own customers.'],
        'procurement'       => ['label' => 'Procurement',       'desc' => 'Manages suppliers, purchase orders and goods receiving.'],
        'warehouse_manager' => ['label' => 'Warehouse Manager', 'desc' => 'Runs warehouses: stock, transfers, adjustments, dispatch, batches and recalls.'],
        'warehouse_staff'   => ['label' => 'Warehouse Staff',   'desc' => 'Receives goods, picks, packs and dispatches orders, counts stock.'],
        'accountant'        => ['label' => 'Accounts / Finance', 'desc' => 'Invoicing, collections, supplier payments, expenses and financial reports.'],
    ];
}

function role_label(?string $role): string
{
    return role_definitions()[$role]['label'] ?? ucwords(str_replace('_', ' ', (string) $role));
}

/** Permission => roles that hold it. The owner implicitly holds every permission. */
function permission_matrix(): array
{
    $sm = 'sales_manager'; $rep = 'sales_rep'; $pr = 'procurement'; $wm = 'warehouse_manager'; $ws = 'warehouse_staff'; $ac = 'accountant';
    return [
        'dashboard.view'      => [$sm, $ac, $wm, $pr],
        'catalog.view'        => [$sm, $rep, $pr, $wm, $ws, $ac],
        'catalog.manage'      => [$sm, $pr],
        'pricing.manage'      => [$sm],
        'products.view_cost'  => [$sm, $pr, $wm, $ac],
        'customers.view'      => [$sm, $rep, $ac, $wm],
        'customers.create'    => [$sm, $rep],
        'customers.edit'      => [$sm],
        'customers.credit'    => [$sm],
        'sales.view'          => [$sm, $rep, $ac, $wm, $ws],
        'sales.view_all'      => [$sm, $ac, $wm, $ws],
        'sales.create'        => [$sm, $rep],
        'sales.approve'       => [$sm],
        'sales.cancel'        => [$sm],
        'sales.override_price' => [$sm],
        'delivery.view'       => [$sm, $rep, $ac, $wm, $ws],
        'delivery.fulfil'     => [$wm, $ws],
        'delivery.manage'     => [$sm, $wm],
        'delivery.confirm'    => [$sm, $wm, $ws],
        'invoices.view'       => [$sm, $rep, $ac],
        'invoices.create'     => [$sm, $ac],
        'payments.receive'    => [$sm, $rep, $ac],
        'creditnotes.issue'   => [$sm, $ac],
        'returns.create'      => [$sm, $rep, $wm],
        'returns.approve'     => [$sm],
        'returns.receive'     => [$wm, $ws],
        'returns.inspect'     => [$wm],
        'inventory.view'      => [$sm, $rep, $pr, $wm, $ws, $ac],
        'inventory.adjust'    => [$wm, $ws],
        'inventory.approve'   => [$wm],
        'inventory.transfer'  => [$wm, $ws],
        'inventory.count'     => [$wm, $ws],
        'warehouses.manage'   => [$wm],
        'trace.view'          => [$sm, $rep, $pr, $wm, $ws, $ac],
        'batches.manage'      => [$wm, $sm],
        'warranty.manage'     => [$sm, $wm, $rep],
        'suppliers.view'      => [$pr, $wm, $ac, $sm],
        'suppliers.manage'    => [$pr],
        'purchase.view'       => [$pr, $wm, $ws, $ac, $sm],
        'purchase.create'     => [$pr],
        'purchase.approve'    => [$ac],
        'purchase.receive'    => [$pr, $wm, $ws],
        'supplier_bills.manage' => [$ac],
        'supplier.pay'        => [$ac],
        'finance.view'        => [$ac],
        'expenses.manage'     => [$ac],
        'reports.view'        => [$sm, $ac, $wm, $pr],
        'reports.finance'     => [$ac],
        'reps.view'           => [$sm],
        'audit.view'          => [$ac],
        'users.manage'        => [],
        'settings.manage'     => [],
    ];
}

function user_can(?array $user, string $permission): bool
{
    if (!$user || empty($user['role'])) { return false; }
    $role = $user['role'] === 'admin' ? 'owner' : $user['role'];
    if ($role === 'owner') { return true; }
    return in_array($role, permission_matrix()[$permission] ?? [], true);
}

function can(string $permission): bool
{
    return user_can(current_user(), $permission);
}

function can_any(array $permissions): bool
{
    foreach ($permissions as $p) { if (can($p)) { return true; } }
    return false;
}

function deny_access(): never
{
    http_response_code(403);
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'You do not have permission to do that.']);
        exit;
    }
    require __DIR__ . '/../403.php';
    exit;
}

/** Guard a page/endpoint: user must hold at least one of the permissions. */
function require_permission(string|array $permissions): void
{
    require_login();
    if (!can_any((array) $permissions)) { deny_access(); }
}

/** Where a user lands after login, based on what they can do. */
function home_path_for(?array $user): string
{
    if (user_can($user, 'dashboard.view') && !in_array($user['role'] ?? '', ['warehouse_manager', 'procurement'], true)) { return '/index.php'; }
    if (user_can($user, 'delivery.fulfil')) { return '/warehouse/fulfilment.php'; }
    if (user_can($user, 'sales.create')) { return '/sales/orders.php'; }
    if (user_can($user, 'purchase.create')) { return '/purchasing/orders.php'; }
    if (user_can($user, 'dashboard.view')) { return '/index.php'; }
    return '/catalog/products.php';
}

// ---------------------------------------------------------------------
// Session / login
// ---------------------------------------------------------------------

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Your session has expired. Please sign in again.']);
            exit;
        }
        header('Location: ' . app_url('/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '')));
        exit;
    }
    // Keep role/active flag fresh so a demoted or disabled user loses access immediately.
    static $refreshed = false;
    if (!$refreshed) {
        $refreshed = true;
        $stmt = db()->prepare('SELECT id, username, full_name, email, role, territory_id, is_active FROM users WHERE id = :id');
        $stmt->execute([':id' => $_SESSION['user']['id']]);
        $fresh = $stmt->fetch();
        if (!$fresh || (int) $fresh['is_active'] !== 1) {
            $_SESSION = [];
            header('Location: ' . app_url('/login.php'));
            exit;
        }
        $_SESSION['user'] = array_merge($_SESSION['user'], $fresh);
    }
}

function app_url(string $path = ''): string
{
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

function login_attempts_recent(string $username, string $ip): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE (username = :u OR ip_address = :ip) AND success = 0
           AND attempted_at > (NOW() - INTERVAL :mins MINUTE)'
    );
    $stmt->bindValue(':u', $username);
    $stmt->bindValue(':ip', $ip);
    $stmt->bindValue(':mins', LOGIN_LOCKOUT_MINUTES, PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function record_login_attempt(string $username, string $ip, bool $success): void
{
    db()->prepare('INSERT INTO login_attempts (username, ip_address, success) VALUES (:u, :ip, :s)')
        ->execute([':u' => $username, ':ip' => $ip, ':s' => $success ? 1 : 0]);
}

function attempt_login(string $username, string $password): array|string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (login_attempts_recent($username, $ip) >= LOGIN_MAX_ATTEMPTS) {
        return 'Too many failed attempts. Please try again in ' . LOGIN_LOCKOUT_MINUTES . ' minutes.';
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        record_login_attempt($username, $ip, false);
        return 'Invalid username or password.';
    }
    if ((int) $user['is_active'] !== 1) {
        record_login_attempt($username, $ip, false);
        return 'This account has been deactivated. Contact the business owner.';
    }
    record_login_attempt($username, $ip, true);
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
            ->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']]);
    }
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')->execute([':id' => $user['id']]);
    session_regenerate_id(true);
    unset($user['password_hash']);
    $_SESSION['user'] = $user;
    log_activity('login', 'Signed in', 'user', (int) $user['id']);
    return $user;
}

function logout(): void
{
    log_activity('logout', 'Signed out');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * Audit trail writer. Every important business action calls this with a
 * human-readable description and (ideally) the entity it touched, so the
 * audit log can answer "who changed what, and when".
 */
function log_activity(string $action, string $details = '', ?string $entityType = null, ?int $entityId = null): void
{
    try {
        db()->prepare(
            'INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (:u, :a, :et, :eid, :d, :ip)'
        )->execute([
            ':u' => current_user()['id'] ?? null, ':a' => $action, ':et' => $entityType, ':eid' => $entityId,
            ':d' => mb_substr($details, 0, 60000), ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('Audit log write failed: ' . $e->getMessage());
    }
}

/** Audit a field change: "Credit limit changed from X to Y". */
function log_change(string $action, string $label, string|float|int|null $old, string|float|int|null $new, ?string $entityType, ?int $entityId, string $subject = ''): void
{
    log_activity($action, ($subject !== '' ? $subject . ' · ' : '') . $label . ' changed from ' . ($old === null || $old === '' ? '—' : $old) . ' to ' . ($new === null || $new === '' ? '—' : $new), $entityType, $entityId);
}
