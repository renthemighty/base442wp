<?php
/**
 * pages/api-convert-status.php — JSON status endpoint for the conversion progress poller.
 *
 * GET /api/convert-status/{uuid}
 *
 * Authenticated (session cookie). Returns a JSON object describing the
 * current state of the conversion so the convert.php page can update its
 * progress UI without a full page reload.
 *
 * Response shape:
 * {
 *   "status":        "converting",          // DB status value
 *   "ai_calls_used": 4,                     // integer
 *   "message":       "Generating CSS...",   // human-readable progress message
 *   "error":         null                   // string or null
 * }
 *
 * HTTP status codes:
 *   200 — normal response (for any known conversion state)
 *   400 — invalid / missing UUID
 *   401 — not authenticated
 *   404 — conversion not found or not owned by the current user
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/db.php';

// ── Auth check ─────────────────────────────────────────────────────────────────

if (!is_logged_in()) {
    json_response(['error' => 'Authentication required.'], 401);
}

$user = current_user();
if ($user === null) {
    json_response(['error' => 'Authentication required.'], 401);
}

// ── UUID from the router ───────────────────────────────────────────────────────

// The router stores the API UUID in $GLOBALS['ROUTE_API_UUID'] for this path
// because the normal ROUTE_UUID constant reads from segment 1, but this route
// is /api/convert-status/{uuid} (segment 2).
$api_uuid = $GLOBALS['ROUTE_API_UUID'] ?? '';

if ($api_uuid === '') {
    json_response(['error' => 'Missing or invalid UUID.'], 400);
}

// ── Load conversion ────────────────────────────────────────────────────────────

try {
    $stmt = db()->prepare(
        'SELECT id, user_id, uuid, status, ai_calls_used, error_message
           FROM conversions
          WHERE uuid = :uuid
          LIMIT 1'
    );
    $stmt->execute([':uuid' => $api_uuid]);
    $conversion = $stmt->fetch();
} catch (PDOException $e) {
    json_response(['error' => 'Database error.'], 500);
}

if (!$conversion) {
    json_response(['error' => 'Conversion not found.'], 404);
}

// Ownership check — same user only
if ((int) $conversion['user_id'] !== (int) $user['id']) {
    json_response(['error' => 'Conversion not found.'], 404);
}

// ── Build response message ─────────────────────────────────────────────────────

$status    = (string) ($conversion['status'] ?? 'pending');
$ai_calls  = (int)   ($conversion['ai_calls_used'] ?? 0);
$error_msg = $conversion['error_message'] ?? null;

// Generate a progress message based on AI call count (proxy for pipeline stage)
$message = match (true) {
    $status === 'paid'                        => 'Queued for conversion\u2026',
    $status === 'failed'                      => 'Conversion failed.',
    $status === 'complete'                    => 'Conversion complete!',
    $ai_calls === 0                           => 'Analysing your source code\u2026',
    $ai_calls < 3                             => 'Generating theme templates\u2026',
    $ai_calls < 5                             => 'Converting styles to WordPress CSS\u2026',
    $ai_calls < 7                             => 'Building page templates\u2026',
    $ai_calls < 9                             => 'Generating functions.php and theme files\u2026',
    $ai_calls < 12                            => 'Assembling theme package\u2026',
    default                                   => 'Finalising and packaging download\u2026',
};

json_response([
    'status'        => $status,
    'ai_calls_used' => $ai_calls,
    'message'       => $message,
    'error'         => $status === 'failed' ? ($error_msg ?? 'An unknown error occurred.') : null,
]);
