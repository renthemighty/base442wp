#!/usr/bin/env php
<?php
/**
 * B442WP Database Migration Script
 *
 * Run once on setup to create the SQLite database, apply the schema,
 * and set up the required storage directories.
 *
 * Usage: php database/migrate.php
 */

declare(strict_types=1);

// ─── CLI only ─────────────────────────────────────────────────────────────────

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.' . PHP_EOL);
}

// ─── ANSI color helpers ───────────────────────────────────────────────────────

$supports_color = function_exists('posix_isatty') && posix_isatty(STDOUT);

function ansi(string $text, string $code): string
{
    global $supports_color;
    if (!$supports_color) {
        return $text;
    }
    return "\033[{$code}m{$text}\033[0m";
}

function ok(string $msg): void
{
    echo ansi('[OK]    ', '32') . $msg . PHP_EOL;   // green
}

function skip(string $msg): void
{
    echo ansi('[SKIP]  ', '33') . $msg . PHP_EOL;   // yellow
}

function warn(string $msg): void
{
    echo ansi('[WARN]  ', '33;1') . $msg . PHP_EOL; // bold yellow
}

function fail(string $msg): never
{
    echo ansi('[ERROR] ', '31;1') . $msg . PHP_EOL; // bold red
    exit(1);
}

function info(string $msg): void
{
    echo ansi('[INFO]  ', '36') . $msg . PHP_EOL;   // cyan
}

// ─── Resolve app root ─────────────────────────────────────────────────────────

$app_root = dirname(__DIR__);

// ─── Load config (with fallback to config.example.php) ───────────────────────

$config_path         = $app_root . '/config.php';
$config_example_path = $app_root . '/config.example.php';

if (file_exists($config_path)) {
    $config = require $config_path;
} elseif (file_exists($config_example_path)) {
    warn('config.php not found. Falling back to config.example.php.');
    warn('Copy config.example.php to config.php and fill in real values before going live.');
    $config = require $config_example_path;
} else {
    fail('Neither config.php nor config.example.php found. Cannot continue.');
}

if (!is_array($config)) {
    fail('Config file must return a PHP array.');
}

// ─── Storage directories ──────────────────────────────────────────────────────

$storage_dir     = $app_root . '/storage';
$uploads_dir     = $storage_dir . '/uploads';
$conversions_dir = $storage_dir . '/conversions';

$directories = [
    $storage_dir,
    $uploads_dir,
    $conversions_dir,
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            fail('Failed to create directory: ' . $dir);
        }
        chmod($dir, 0755);
        ok('Created directory: ' . $dir);
    } else {
        skip('Directory already exists: ' . $dir);
    }
}

// ─── .htaccess: deny direct HTTP access to storage/ ──────────────────────────

$htaccess_path = $storage_dir . '/.htaccess';
if (!file_exists($htaccess_path)) {
    $htaccess = <<<'HTACCESS'
# Deny direct HTTP access to all files in this directory
Order Deny,Allow
Deny from all

# Apache 2.4+
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
HTACCESS;

    if (file_put_contents($htaccess_path, $htaccess) === false) {
        warn('Failed to write storage/.htaccess. Add it manually to block web access.');
    } else {
        ok('Created storage/.htaccess (deny all).');
    }
} else {
    skip('storage/.htaccess already exists.');
}

// ─── SQLite database ──────────────────────────────────────────────────────────

$db_path = $config['db_path'] ?? ($storage_dir . '/database.sqlite');

// Ensure the directory for the DB file exists (may differ from storage/)
$db_dir = dirname((string) $db_path);
if (!is_dir($db_dir)) {
    if (!mkdir($db_dir, 0755, true)) {
        fail('Failed to create database directory: ' . $db_dir);
    }
    chmod($db_dir, 0755);
    ok('Created database directory: ' . $db_dir);
}

$db_already_existed = file_exists((string) $db_path);

try {
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,   false);

    // Enable WAL for concurrent reads and enforce foreign key integrity
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');
} catch (PDOException $e) {
    fail('Could not open/create SQLite database: ' . $e->getMessage());
}

if ($db_already_existed) {
    skip('Database file already exists: ' . $db_path);
} else {
    ok('Created database file: ' . $db_path);
}

// ─── Apply schema ─────────────────────────────────────────────────────────────

$schema_path = __DIR__ . '/schema.sql';

if (!file_exists($schema_path)) {
    fail('schema.sql not found at: ' . $schema_path);
}

$schema_sql = file_get_contents($schema_path);
if ($schema_sql === false) {
    fail('Could not read schema.sql.');
}

// Check which expected tables already exist
$expected_tables = ['users', 'conversions', 'password_resets'];
$existing_tables = [];

try {
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tbl) {
        $existing_tables[$tbl] = true;
    }
} catch (PDOException $e) {
    fail('Could not query existing tables: ' . $e->getMessage());
}

$all_exist = array_reduce(
    $expected_tables,
    fn (bool $carry, string $t) => $carry && isset($existing_tables[$t]),
    true
);

if ($all_exist) {
    skip('All tables already exist — schema not re-applied.');
    info('Tables present: ' . implode(', ', array_keys($existing_tables)));
} else {
    // schema.sql uses IF NOT EXISTS — safe to re-run at any time
    try {
        $pdo->exec($schema_sql);
        ok('Schema applied successfully.');

        $stmt    = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $created = $stmt->fetchAll(PDO::FETCH_COLUMN);
        ok('Tables in database: ' . implode(', ', $created));
    } catch (PDOException $e) {
        fail('Schema execution failed: ' . $e->getMessage());
    }
}

// ─── File permissions ─────────────────────────────────────────────────────────

// Storage directories: 0755 (owner rwx, group rx, other rx)
foreach ($directories as $dir) {
    if (is_dir($dir)) {
        chmod($dir, 0755);
    }
}

// Database file: 0664 (owner rw, group rw, other r)
if (file_exists((string) $db_path)) {
    chmod((string) $db_path, 0664);
    ok('Database file permissions set to 0664.');

    // WAL mode also creates -wal and -shm companion files
    foreach (['-wal', '-shm'] as $suffix) {
        $companion = $db_path . $suffix;
        if (file_exists($companion)) {
            chmod($companion, 0664);
        }
    }
}

// ─── Done ─────────────────────────────────────────────────────────────────────

echo PHP_EOL;
echo ansi('B442WP database migration complete.', '32;1') . PHP_EOL;
echo PHP_EOL;
exit(0);
