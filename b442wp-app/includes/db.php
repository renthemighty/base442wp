<?php
/**
 * B442WP — Database Access
 *
 * Provides a PDO singleton for SQLite. Call db() anywhere after this file
 * has been required.
 */

declare(strict_types=1);

/**
 * Returns the application's SQLite PDO singleton.
 *
 * Connection settings:
 *   - ERRMODE_EXCEPTION      — all DB errors throw PDOException
 *   - FETCH_ASSOC            — all results returned as associative arrays
 *   - EMULATE_PREPARES false — use native prepared statements
 *   - WAL journal mode       — allows concurrent reads during writes
 *   - foreign_keys ON        — enforces referential integrity
 *   - busy_timeout 5000      — wait up to 5 s on SQLITE_BUSY before throwing
 *
 * @return PDO
 * @throws RuntimeException  If config.php is missing, db_path is not set,
 *                           or the database cannot be opened.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $config = require __DIR__ . '/../config.php';

        $pdo = new PDO('sqlite:' . $config['db_path']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,   false);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA busy_timeout=5000');
    }

    return $pdo;
}
