<?php
/**
 * Global application configuration.
 * Not web-accessible directly (blocked by .htaccess in this folder).
 *
 * NOTE: Calimax ERP has its OWN database (`calimax`). It never touches `calipos`.
 */

// ---- Database ---------------------------------------------------------
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'calimax');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ---- App ----------------------------------------------------------------
define('APP_NAME', 'Calimax ERP');
define('APP_URL', 'http://localhost/calimax');
define('APP_ENV', 'development'); // 'development' | 'production'

// ---- Session / security --------------------------------------------------
define('SESSION_NAME', 'calimax_sid');
define('SESSION_LIFETIME', 60 * 60 * 8); // 8 hours
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

// ---- Uploads --------------------------------------------------------------
define('UPLOAD_EXPENSE_DIR', __DIR__ . '/../uploads/expenses/');
define('UPLOAD_EXPENSE_URL', APP_URL . '/uploads/expenses/');
define('MAX_UPLOAD_BYTES', 2 * 1024 * 1024); // 2MB

// ---- Error reporting --------------------------------------------------
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Colombo');
