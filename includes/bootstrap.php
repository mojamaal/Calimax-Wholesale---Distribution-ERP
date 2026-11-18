<?php
/**
 * Single entry point every page includes first.
 * Order matters: config -> db -> security/session -> auth -> functions -> domain engines.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/accounting.php';
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/sales.php';
require_once __DIR__ . '/purchasing.php';
require_once __DIR__ . '/reporting.php';
require_once __DIR__ . '/navs.php';
