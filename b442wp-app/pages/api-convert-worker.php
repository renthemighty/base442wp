<?php
/**
 * pages/api-convert-worker.php — Background conversion worker.
 *
 * POST /api/convert-worker
 *
 * Called internally by convert.php via a non-blocking fire-and-forget curl.
 * Closes the HTTP connection immediately, then runs the full conversion
 * pipeline detached from the caller's LiteSpeed connection timeout.
 *
 * Security: request must carry a valid HMAC token generated from the uuid
 * and the claude_api_key (shared secret never sent to the browser).
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

// ── Close the HTTP connection immediately ─────────────────────────────────────
// This lets LiteSpeed release the client connection while PHP keeps running.

set_time_limit(0);
ignore_user_abort(true);

header('Connection: close');
header('Content-Type: text/plain; charset=UTF-8');
header('Content-Length: 2');
echo 'OK';

if (ob_get_level() > 0) {
    ob_end_flush();
}
flush();

// ── Validate request ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit;
}

$uuid  = trim((string) ($_POST['uuid']  ?? ''));
$token = trim((string) ($_POST['token'] ?? ''));

if ($uuid === '' || $token === '') {
    exit;
}

// Verify HMAC — token = hmac(sha256, uuid, claude_api_key)
$expected = hash_hmac('sha256', $uuid, (string) config('claude_api_key'));
if (!hash_equals($expected, $token)) {
    error_log('[B442WP worker] Invalid token for uuid: ' . $uuid);
    exit;
}

// ── Load the conversion record ────────────────────────────────────────────────

try {
    $stmt = db()->prepare(
        "SELECT * FROM conversions WHERE uuid = :uuid AND status = 'converting' LIMIT 1"
    );
    $stmt->execute([':uuid' => $uuid]);
    $conversion = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[B442WP worker] DB error loading conversion ' . $uuid . ': ' . $e->getMessage());
    exit;
}

if (!$conversion) {
    // Already completed, expired, or not in 'converting' state — nothing to do.
    exit;
}

// ── Run the conversion pipeline ───────────────────────────────────────────────

require_once APP_ROOT . '/converter/Converter.php';
require_once APP_ROOT . '/converter/Assembler.php';

try {
    $decoded = json_decode((string) ($conversion['parsed_data'] ?? '{}'), true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Conversion record has no valid parsed_data — cannot convert.');
    }

    // upload.php stores parsed_data as { "analysis": {...}, "source_meta": {...} }.
    // Converter expects the analysis keys at the top level.
    $source_data = (isset($decoded['analysis']) && is_array($decoded['analysis']))
        ? $decoded['analysis']
        : $decoded;

    $converter = new Converter($conversion, $source_data);
    $files     = $converter->convert();

    $assembler = new Assembler((string) ($conversion['theme_name'] ?? 'wordpress-theme'));
    $zip_path  = $assembler->assemble($files, $conversion);

    if (!file_exists($zip_path)) {
        throw new RuntimeException('Assembler did not produce an output zip at: ' . $zip_path);
    }

    $expires_at = date('Y-m-d H:i:s', strtotime('+' . (int) config('download_expiry_days', 30) . ' days'));

    $done = db()->prepare(
        'UPDATE conversions
            SET status          = :status,
                output_zip_path = :zip_path,
                completed_at    = CURRENT_TIMESTAMP,
                expires_at      = :expires_at
          WHERE id = :id'
    );
    $done->execute([
        ':status'    => 'complete',
        ':zip_path'  => $zip_path,
        ':expires_at'=> $expires_at,
        ':id'        => (int) $conversion['id'],
    ]);

} catch (Throwable $e) {
    error_log('[B442WP worker] Conversion failed for ' . $uuid . ': ' . $e->getMessage());

    try {
        $fail = db()->prepare(
            'UPDATE conversions SET status = :s, error_message = :msg WHERE id = :id'
        );
        $fail->execute([
            ':s'   => 'failed',
            ':msg' => substr($e->getMessage(), 0, 1000),
            ':id'  => (int) $conversion['id'],
        ]);
    } catch (PDOException) {
        // Best effort
    }
}
