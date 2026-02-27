#!/usr/bin/env php
<?php
/**
 * B442WP — Daily Cleanup Cron Script
 *
 * Recommended crontab entry:
 *   0 3 * * * php /path/to/b442wp-app/database/cleanup.php
 *
 * What this script does:
 *   1. Expire completed conversions whose download window has closed:
 *        - Deletes source_zip_path and output_zip_path files from disk.
 *        - Sets status = 'expired' and nulls the file path columns.
 *
 *   2. Mark stuck conversions as failed:
 *        - Conversions with status 'paid' or 'converting' created more than
 *          2 hours ago are assumed to have timed out.
 *        - Sets status = 'failed', error_message = 'Conversion timed out'.
 *
 *   3. Purge expired password-reset tokens older than 24 hours.
 *
 *   4. Prints a summary to STDOUT and appends it to storage/cleanup.log.
 *
 * Run manually: php database/cleanup.php
 */

declare(strict_types=1);

// ─── CLI guard ─────────────────────────────────────────────────────────────────

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.' . PHP_EOL);
}

// ─── Bootstrap ────────────────────────────────────────────────────────────────

$app_root = dirname(__DIR__);

// Load config (with fallback to config.example.php + warning)
$config_path         = $app_root . '/config.php';
$config_example_path = $app_root . '/config.example.php';

if (file_exists($config_path)) {
    $config = require $config_path;
} elseif (file_exists($config_example_path)) {
    echo '[WARN] config.php not found — falling back to config.example.php.' . PHP_EOL;
    echo '[WARN] Values may not reflect your production environment.' . PHP_EOL;
    $config = require $config_example_path;
} else {
    fwrite(STDERR, '[ERROR] Neither config.php nor config.example.php found. Aborting.' . PHP_EOL);
    exit(1);
}

if (!is_array($config)) {
    fwrite(STDERR, '[ERROR] Config file must return a PHP array. Aborting.' . PHP_EOL);
    exit(1);
}

// Connect to SQLite
$db_path = $config['db_path'] ?? ($app_root . '/storage/database.sqlite');

if (!file_exists((string) $db_path)) {
    fwrite(STDERR, '[ERROR] Database not found at: ' . $db_path . PHP_EOL);
    fwrite(STDERR, '        Run php database/migrate.php first.' . PHP_EOL);
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,   false);
    $pdo->exec('PRAGMA journal_mode=WAL;');
    $pdo->exec('PRAGMA foreign_keys=ON;');
    $pdo->exec('PRAGMA busy_timeout=5000;');
} catch (PDOException $e) {
    fwrite(STDERR, '[ERROR] Could not open database: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// ─── Logging helper ────────────────────────────────────────────────────────────

$log_path   = $app_root . '/storage/cleanup.log';
$log_buffer = [];

/**
 * Print a message to STDOUT and buffer it for the log file.
 *
 * @param string $message
 */
$log = function (string $message) use (&$log_buffer): void {
    $line          = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    $log_buffer[]  = $line;
    echo $line . PHP_EOL;
};

// ─── Run ──────────────────────────────────────────────────────────────────────

$log('=== B442WP Cleanup started ===');

// Counters
$expired_count     = 0;
$files_deleted     = 0;
$stuck_count       = 0;
$reset_purge_count = 0;

// ── Task 1: Expire completed conversions whose download window has passed ──────

$log('Task 1: Expiring overdue completed conversions…');

try {
    $stmt = $pdo->prepare(
        "SELECT id, uuid, source_zip_path, output_zip_path
           FROM conversions
          WHERE status     = 'complete'
            AND expires_at IS NOT NULL
            AND expires_at < CURRENT_TIMESTAMP"
    );
    $stmt->execute();
    $overdue = $stmt->fetchAll();
} catch (PDOException $e) {
    $log('[ERROR] Could not query expired conversions: ' . $e->getMessage());
    $overdue = [];
}

// Resolve the canonical storage path once for the security check
$storage_real = realpath($app_root . '/storage');

foreach ($overdue as $row) {
    $id   = (int) $row['id'];
    $uuid = (string) $row['uuid'];

    // Delete both zip files
    foreach (['source_zip_path', 'output_zip_path'] as $field) {
        $file_path = (string) ($row[$field] ?? '');

        if ($file_path === '') {
            continue;
        }

        $real_file = realpath($file_path);

        if ($real_file === false) {
            // File already gone — not an error
            continue;
        }

        // Security: only delete files inside storage/
        if (
            $storage_real !== false
            && strncmp($real_file, $storage_real . DIRECTORY_SEPARATOR, strlen($storage_real) + 1) === 0
        ) {
            if (@unlink($real_file)) {
                $files_deleted++;
                $log("  [DEL] {$uuid}: deleted " . basename($real_file));
            } else {
                $log("  [WARN] {$uuid}: could not delete " . $real_file);
            }
        } else {
            $log("  [SKIP] {$uuid}: path outside storage/, refusing to delete: {$file_path}");
        }
    }

    // Mark the conversion as expired and clear file paths
    try {
        $upd = $pdo->prepare(
            "UPDATE conversions
                SET status          = 'expired',
                    source_zip_path = NULL,
                    output_zip_path = NULL
              WHERE id = :id"
        );
        $upd->execute([':id' => $id]);
        $expired_count++;
        $log("  [OK] Conversion {$uuid} set to 'expired'.");
    } catch (PDOException $e) {
        $log("  [ERROR] Could not update conversion {$uuid}: " . $e->getMessage());
    }
}

if ($expired_count === 0) {
    $log('  No overdue conversions found.');
}

// ── Task 2: Mark stuck conversions as failed ───────────────────────────────────

$log('Task 2: Marking stuck conversions (paid/converting > 2 hours) as failed…');

try {
    $upd = $pdo->prepare(
        "UPDATE conversions
            SET status        = 'failed',
                error_message = 'Conversion timed out'
          WHERE status IN ('paid', 'converting')
            AND created_at < datetime('now', '-2 hours')"
    );
    $upd->execute();
    $stuck_count = $upd->rowCount();
} catch (PDOException $e) {
    $log('[ERROR] Could not mark stuck conversions: ' . $e->getMessage());
}

if ($stuck_count > 0) {
    $log("  [OK] {$stuck_count} stuck conversion(s) marked as 'failed'.");
} else {
    $log('  No stuck conversions found.');
}

// ── Task 3: Purge old password reset tokens ────────────────────────────────────

$log('Task 3: Purging expired password-reset tokens (older than 24 hours)…');

try {
    $del = $pdo->prepare(
        "DELETE FROM password_resets
          WHERE expires_at < datetime('now', '-24 hours')"
    );
    $del->execute();
    $reset_purge_count = $del->rowCount();
} catch (PDOException $e) {
    $log('[ERROR] Could not purge password-reset tokens: ' . $e->getMessage());
}

if ($reset_purge_count > 0) {
    $log("  [OK] {$reset_purge_count} expired reset token(s) purged.");
} else {
    $log('  No old reset tokens to purge.');
}

// ── Summary ────────────────────────────────────────────────────────────────────

$log('=== B442WP Cleanup complete ===');
$log(sprintf(
    'Summary — conversions expired: %d | files deleted: %d | stuck→failed: %d | reset tokens purged: %d',
    $expired_count,
    $files_deleted,
    $stuck_count,
    $reset_purge_count
));

// ── Write log file ─────────────────────────────────────────────────────────────

$log_dir = dirname($log_path);

if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}

$log_entry = implode(PHP_EOL, $log_buffer) . PHP_EOL;

if (file_put_contents($log_path, $log_entry, FILE_APPEND | LOCK_EX) === false) {
    fwrite(STDERR, '[WARN] Could not write to cleanup log: ' . $log_path . PHP_EOL);
}

exit(0);
