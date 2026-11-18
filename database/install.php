<?php
/**
 * Creates the `calimax` database, loads the schema and (optionally) the demo data.
 *
 *   php database/install.php            create DB + schema + demo data
 *   php database/install.php --no-demo  create DB + schema only
 *   php database/install.php --force    DROP the calimax database first and rebuild it
 *
 * This script only ever touches the `calimax` database. It never reads or changes `calipos`.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }

require_once __DIR__ . '/../config/config.php';
$args = $argv ?? [];
$force = in_array('--force', $args, true);
$demo = !in_array('--no-demo', $args, true);

if (DB_NAME !== 'calimax') { fwrite(STDERR, "Refusing to run: DB_NAME is not 'calimax'.\n"); exit(1); }

$pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = (bool) $pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'calimax'")->fetchColumn();
if ($exists) {
    $tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'calimax'")->fetchColumn();
    if ($tables > 0 && !$force) { fwrite(STDERR, "Database 'calimax' already has $tables tables. Re-run with --force to rebuild it (all calimax data will be lost).\n"); exit(1); }
    if ($force) { $pdo->exec('DROP DATABASE `calimax`'); echo "Dropped existing calimax database.\n"; }
}

$sql = file_get_contents(__DIR__ . '/schema.sql');
$sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$n = 0;
foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') continue;
    $pdo->exec($stmt);
    $n++;
}
echo "Schema loaded ($n statements).\n";

if ($demo) {
    require __DIR__ . '/seed_demo.php';
} else {
    echo "Done. Sign in with admin / admin123.\n";
}
