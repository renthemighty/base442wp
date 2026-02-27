<?php
/**
 * B442WP — Router
 *
 * Parses the incoming request URI, matches it against the route table, injects
 * any path parameters (e.g. {uuid}) into $_REQUEST, and includes the matched
 * page file.
 *
 * This file is required by index.php *after* boot() has been called. It does
 * not start the session or load config itself.
 *
 * Route table:
 *   GET    /                           → /login redirect or dashboard
 *   GET    /login                      → pages/login.php
 *   POST   /login                      → pages/login.php
 *   GET    /register                   → pages/register.php
 *   POST   /register                   → pages/register.php
 *   GET    /logout                     → logout_user() + redirect /login
 *   GET    /forgot-password            → pages/forgot-password.php
 *   POST   /forgot-password            → pages/forgot-password.php
 *   GET    /reset-password             → pages/reset-password.php
 *   POST   /reset-password             → pages/reset-password.php
 *   GET    /upload                     → pages/upload.php       (auth required)
 *   POST   /upload                     → pages/upload.php       (auth required)
 *   GET    /preview/{uuid}             → pages/preview.php      (auth required)
 *   GET    /pay/{uuid}                 → pages/pay.php          (auth required)
 *   POST   /pay/{uuid}                 → pages/pay.php          (auth required)
 *   GET    /convert/{uuid}             → pages/convert.php      (auth required)
 *   GET    /download/{uuid}            → pages/download.php     (auth required)
 *   GET    /account                    → pages/account.php      (auth required)
 *   POST   /account                    → pages/account.php      (auth required)
 *   POST   /webhook/stripe             → pages/webhook-stripe.php  (no auth)
 *   POST   /webhook/paypal             → pages/webhook-paypal.php  (no auth)
 *   GET    /api/convert-status/{uuid}  → JSON {status, progress, message}
 */

declare(strict_types=1);

// ─── Parse request URI ─────────────────────────────────────────────────────────

// Support both clean URLs (Apache/Nginx rewrite → REQUEST_URI) and the legacy
// query-string fallback (?_url=...) on shared hosting without mod_rewrite.
if (!empty($_GET['_url'])) {
    $raw_uri = '/' . ltrim((string) $_GET['_url'], '/');
} else {
    $raw_uri = $_SERVER['REQUEST_URI'] ?? '/';
}

// Strip the query string from the URI before matching
$path = parse_url($raw_uri, PHP_URL_PATH) ?? '/';

// Normalise: collapse any double-slashes, remove trailing slash except on root
$path = '/' . trim($path, '/');

// Decode percent-encoded characters so routes match plain UTF-8 strings
$path = rawurldecode($path);

// Hard security check: reject paths containing null bytes or directory traversal
if (str_contains($path, "\0") || str_contains($path, '..')) {
    http_response_code(400);
    exit('Bad Request.');
}

// HTTP method (uppercase)
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Split path into segments: "/preview/abc-123" → ['preview', 'abc-123']
$segments = array_values(array_filter(explode('/', $path), fn (string $s) => $s !== ''));

$seg0 = $segments[0] ?? '';
$seg1 = $segments[1] ?? '';
$seg2 = $segments[2] ?? '';

// ─── UUID validation helper ────────────────────────────────────────────────────

/**
 * Return $candidate if it is a well-formed UUID v4, or '' otherwise.
 *
 * Validates against the canonical UUID v4 format:
 *   xxxxxxxx-xxxx-4xxx-[89ab]xxx-xxxxxxxxxxxx
 */
$parse_uuid = static function (string $candidate): string {
    if (
        $candidate !== ''
        && preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $candidate
        )
    ) {
        return strtolower($candidate);
    }
    return '';
};

// ─── Page dispatcher ──────────────────────────────────────────────────────────

/**
 * Include a page file by its path relative to the app root.
 *
 * Responds with HTTP 404 if the file does not exist (which should only happen
 * during development if a page file has not been created yet).
 *
 * @param string $relative  e.g. 'pages/login.php'
 */
$dispatch = static function (string $relative): void {
    $full = APP_ROOT . '/' . $relative;

    if (!file_exists($full)) {
        http_response_code(404);

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($accept, 'application/json')) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'Not found.']);
        } else {
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
               . '<title>404 — B442WP</title></head><body>'
               . '<h1>404 Not Found</h1>'
               . '<p>The requested page could not be found.</p>'
               . '<p><a href="' . htmlspecialchars(base_url('/'), ENT_QUOTES, 'UTF-8') . '">Go home</a></p>'
               . '</body></html>';
        }

        exit;
    }

    require $full;
    exit;
};

// ─── Route dispatch ────────────────────────────────────────────────────────────

switch (true) {

    // ── GET / ──────────────────────────────────────────────────────────────────
    case $method === 'GET' && $path === '/':
        if (!is_logged_in()) {
            redirect('/login');
        }
        $dispatch('pages/dashboard.php');
        break;

    // ── GET|POST /login ────────────────────────────────────────────────────────
    case in_array($method, ['GET', 'POST'], true) && $path === '/login':
        $dispatch('pages/login.php');
        break;

    // ── GET|POST /register ─────────────────────────────────────────────────────
    case in_array($method, ['GET', 'POST'], true) && $path === '/register':
        $dispatch('pages/register.php');
        break;

    // ── GET /logout ────────────────────────────────────────────────────────────
    case $method === 'GET' && $path === '/logout':
        logout_user();
        flash('success', 'You have been logged out.', 'success');
        redirect('/login');
        break;

    // ── GET|POST /forgot-password ──────────────────────────────────────────────
    case in_array($method, ['GET', 'POST'], true) && $path === '/forgot-password':
        $dispatch('pages/forgot-password.php');
        break;

    // ── GET|POST /reset-password ───────────────────────────────────────────────
    case in_array($method, ['GET', 'POST'], true) && $path === '/reset-password':
        $dispatch('pages/reset-password.php');
        break;

    // ── GET|POST /upload ───────────────────────────────────────────────────────
    case in_array($method, ['GET', 'POST'], true) && $path === '/upload':
        require_auth();
        $dispatch('pages/upload.php');
        break;

    // ── GET|POST /account ──────────────────────────────────────────────────────
    case in_array($method, ['GET', 'POST'], true) && $path === '/account':
        require_auth();
        $dispatch('pages/account.php');
        break;

    // ── POST /webhook/stripe ───────────────────────────────────────────────────
    case $method === 'POST' && $path === '/webhook/stripe':
        $dispatch('pages/webhook-stripe.php');
        break;

    // ── POST /webhook/paypal ───────────────────────────────────────────────────
    case $method === 'POST' && $path === '/webhook/paypal':
        $dispatch('pages/webhook-paypal.php');
        break;

    // ── GET /preview/{uuid} ────────────────────────────────────────────────────
    case $method === 'GET' && $seg0 === 'preview': {
        $uuid = $parse_uuid($seg1);
        if ($uuid === '') { break; }
        $_REQUEST['uuid'] = $uuid;
        require_auth();
        $dispatch('pages/preview.php');
        break;
    }

    // ── GET|POST /pay/{uuid} ───────────────────────────────────────────────────
    case in_array($method, ['GET', 'POST'], true) && $seg0 === 'pay': {
        $uuid = $parse_uuid($seg1);
        if ($uuid === '') { break; }
        $_REQUEST['uuid'] = $uuid;
        require_auth();
        $dispatch('pages/pay.php');
        break;
    }

    // ── GET /convert/{uuid} ────────────────────────────────────────────────────
    case $method === 'GET' && $seg0 === 'convert': {
        $uuid = $parse_uuid($seg1);
        if ($uuid === '') { break; }
        $_REQUEST['uuid'] = $uuid;
        require_auth();
        $dispatch('pages/convert.php');
        break;
    }

    // ── GET /download/{uuid} ───────────────────────────────────────────────────
    case $method === 'GET' && $seg0 === 'download': {
        $uuid = $parse_uuid($seg1);
        if ($uuid === '') { break; }
        $_REQUEST['uuid'] = $uuid;
        require_auth();
        $dispatch('pages/download.php');
        break;
    }

    // ── GET /api/convert-status/{uuid} ────────────────────────────────────────
    case $method === 'GET' && $seg0 === 'api' && $seg1 === 'convert-status': {
        $uuid = $parse_uuid($seg2);

        if ($uuid === '') {
            http_response_code(400);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'Missing or invalid UUID.']);
            exit;
        }

        $_REQUEST['uuid'] = $uuid;

        // Inline: look up status and return JSON without requiring a page file
        if (!is_logged_in()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'Authentication required.']);
            exit;
        }

        $user = current_user();

        try {
            $stmt = db()->prepare(
                'SELECT status, error_message
                   FROM conversions
                  WHERE uuid    = :uuid
                    AND user_id = :uid
                  LIMIT 1'
            );
            $stmt->execute([':uuid' => $uuid, ':uid' => $user['id']]);
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'Database error.']);
            exit;
        }

        if (!$row) {
            http_response_code(404);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'Conversion not found.']);
            exit;
        }

        $status = $row['status'];

        // Map status to a user-facing progress percentage
        $progress_map = [
            'pending'    => 0,
            'parsed'     => 10,
            'paid'       => 20,
            'converting' => 60,
            'complete'   => 100,
            'failed'     => 0,
            'expired'    => 0,
        ];

        $message_map = [
            'pending'    => 'Waiting to start…',
            'parsed'     => 'Analysis complete. Awaiting payment.',
            'paid'       => 'Payment received. Queued for conversion.',
            'converting' => 'Conversion in progress…',
            'complete'   => 'Conversion complete! Your theme is ready.',
            'failed'     => $row['error_message'] ?? 'Conversion failed. Please contact support.',
            'expired'    => 'This conversion has expired.',
        ];

        http_response_code(200);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode([
            'status'   => $status,
            'progress' => $progress_map[$status] ?? 0,
            'message'  => $message_map[$status] ?? ucfirst($status),
        ]);
        exit;
    }

    // ── 404 ────────────────────────────────────────────────────────────────────
    default:
        http_response_code(404);

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_starts_with($path, '/api/') || str_contains($accept, 'application/json')) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'Not found.']);
            exit;
        }
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 Not Found — B442WP</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 480px; margin: 10vh auto; padding: 0 1rem; text-align: center; color: #1a1a2e; }
        h1   { font-size: 4rem; margin: 0; color: #6366f1; }
        p    { color: #555; }
        a    { color: #6366f1; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h1>404</h1>
    <h2>Page Not Found</h2>
    <p>The page you're looking for doesn't exist or has been moved.</p>
    <p><a href="<?= htmlspecialchars(base_url('/'), ENT_QUOTES, 'UTF-8') ?>">Go to Dashboard</a></p>
</body>
</html>
        <?php
        exit;
}
