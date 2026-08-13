<?php
declare(strict_types=1);
/**
 * POST /api/fred-complete
 * Fred POSTs the finished theme ZIP back after conversion.
 *
 * POST fields:
 *   conversion_id   int
 *   ai_calls        int
 *   tokens_input    int
 *   tokens_output   int
 *   error           string  (only on failure — no zip_file sent)
 *
 * Uploaded file:
 *   zip_file        the outer download ZIP
 *
 * v3.3.0 (queue reliability fix, staged 2026-08-11 — see FIX-NOTES.md):
 *  - the completion UPDATE used to be unconditional (WHERE id = :id only), so
 *    if the stranded-claim requeue in api-fred-claim.php ever let a job get
 *    double-claimed (the exact failure mode that fix addresses), whichever
 *    worker's fred-complete call landed second would silently overwrite the
 *    first one's result with no trace of it happening. The completion write
 *    is now logged with before/after stage so a double-completion is visible
 *    in the log instead of invisible.
 *  - claimed_at is cleared on completion. It used to be left at whatever it
 *    was stamped to on the original claim, which meant a completed row could
 *    carry a stale claimed_at indefinitely — harmless on its own today
 *    because completion also moves conversion_stage off 'fred_claimed', but
 *    it is dead data that only makes future debugging harder, so it is
 *    cleared here as routine hygiene.
 */

if (!defined('APP_ROOT')) define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$token    = $_SERVER['HTTP_X_FRED_TOKEN'] ?? '';
$expected = (string) config('fred_token', '');
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    exit('{"error":"forbidden"}');
}

// An oversized request body makes PHP discard $_POST and $_FILES entirely,
// with no error, so a genuinely sent field looks absent. Report that honestly
// instead of blaming the field.
$content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if (empty($_POST) && $content_length > 0) {
    http_response_code(413);
    error_log("[B442WP] fred-complete: POST discarded, body {$content_length} bytes exceeds post_max_size " . ini_get('post_max_size'));
    exit(json_encode([
        'error'          => 'request body too large',
        'content_length' => $content_length,
        'post_max_size'  => ini_get('post_max_size'),
    ]));
}

$conv_id  = (int) ($_POST['conversion_id'] ?? 0);
$ai_calls = (int) ($_POST['ai_calls']      ?? 0);
$tok_in   = (int) ($_POST['tokens_input']  ?? 0);
$tok_out  = (int) ($_POST['tokens_output'] ?? 0);
$error    = trim($_POST['error'] ?? '');

if ($conv_id <= 0) {
    http_response_code(400);
    exit('{"error":"missing conversion_id"}');
}

$stmt = db()->prepare('SELECT * FROM conversions WHERE id = ? LIMIT 1');
$stmt->execute([$conv_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    exit('{"error":"not found"}');
}

// Diagnosability: record what state this job was actually in when Fred
// reported back, so a double-claim/double-complete (job 92/95/96/97/101/131
// history) shows up in the log instead of vanishing silently.
$prior_status = (string) ($row['status'] ?? '');
$prior_stage  = (string) ($row['conversion_stage'] ?? '');
if ($prior_status === 'complete') {
    error_log(sprintf(
        '[fred-complete] job %d: DUPLICATE completion received — status was already \'complete\' '
        . '(prior conversion_stage=%s). This job was likely double-claimed; overwriting with the '
        . 'newest result to avoid a corrupt state, but the earlier build was wasted spend.',
        $conv_id,
        $prior_stage
    ));
}

// Handle failure report
if ($error !== '') {
    db()->prepare(
        "UPDATE conversions SET status = 'failed', error_message = ?, conversion_stage = 'fred_error', claimed_at = NULL WHERE id = ?"
    )->execute([substr($error, 0, 1000), $conv_id]);
    error_log("[B442WP] Fred failed conversion {$conv_id}: {$error}");
    echo json_encode(['status' => 'recorded_failure']);
    exit;
}

// Require ZIP
if (!isset($_FILES['zip_file']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit('{"error":"no zip uploaded"}');
}

$uuid      = $row['uuid'];
$conv_dir  = rtrim((string) config('output_dir', APP_ROOT . '/storage/conversions'), '/') . '/' . $uuid;
if (!is_dir($conv_dir)) mkdir($conv_dir, 0750, true);

// Prefer the uploaded file's client basename (Fred names it correctly, e.g.
// coast-realty-solutions-theme-download.zip); fall back to DB theme_name.
$zip_filename = '';
$client_name  = strtolower(basename((string) ($_FILES['zip_file']['name'] ?? '')));
if ($client_name !== '' && preg_match('/^[a-z0-9][a-z0-9._-]*\.zip$/', $client_name)) {
    $zip_filename = $client_name;
}
if ($zip_filename === '') {
    $theme_name   = $row['theme_name'] ?? 'theme';
    $theme_slug   = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string) $theme_name), '-')) ?: 'theme';
    $zip_filename = $theme_slug . '-theme-download.zip';
}
$zip_path = $conv_dir . '/' . $zip_filename;

if (!move_uploaded_file($_FILES['zip_file']['tmp_name'], $zip_path)) {
    http_response_code(500);
    exit('{"error":"move_uploaded_file failed"}');
}

// One-time migration: add token columns if schema predates them
foreach (['tokens_input', 'tokens_output'] as $col) {
    try { db()->exec("ALTER TABLE conversions ADD COLUMN {$col} INTEGER DEFAULT 0"); } catch (\Throwable $_) {}
}

$expires_at = date('Y-m-d H:i:s', strtotime('+' . (int) config('download_expiry_days', 30) . ' days'));

db()->prepare(
    'UPDATE conversions
     SET status = :status, output_zip_path = :zip, ai_calls_used = :ai,
         tokens_input = :ti, tokens_output = :to,
         completed_at = CURRENT_TIMESTAMP, expires_at = :expires, conversion_stage = :stage,
         claimed_at = NULL, error_message = NULL
     WHERE id = :id'
)->execute([
    ':status'  => 'complete',
    ':zip'     => $zip_path,
    ':ai'      => $ai_calls,
    ':ti'      => $tok_in,
    ':to'      => $tok_out,
    ':expires' => $expires_at,
    ':stage'   => 'done',
    ':id'      => $conv_id,
]);

$zip_kb = round(filesize($zip_path) / 1024, 1);
error_log(sprintf(
    '[B442WP] Fred completed #%d (%d calls, %d+%d tokens, %sKB ZIP) — prior status=%s prior stage=%s',
    $conv_id,
    $ai_calls,
    $tok_in,
    $tok_out,
    $zip_kb,
    $prior_status,
    $prior_stage
));

echo json_encode(['status' => 'ok', 'zip_kb' => $zip_kb]);
