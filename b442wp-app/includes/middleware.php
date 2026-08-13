<?php
/**
 * B442WP — Middleware / Guard Functions
 *
 * Route guards, session bootstrap, rate limiting, and conversion ownership
 * verification.
 *
 * Depends on:
 *   - includes/helpers.php  (config(), flash(), redirect(), is_logged_in(), current_user())
 *   - includes/auth.php     (logout_user())
 *   - includes/db.php       (db())
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

// ─── Application bootstrap ─────────────────────────────────────────────────────

/**
 * Bootstrap the application at the very start of every HTTP request.
 *
 * Responsibilities:
 *   - Start the PHP session with secure, hardened cookie parameters.
 *   - Set the session lifetime from config.
 *   - Start output buffering so redirects work even if partial output was sent.
 *
 * Call this once in index.php before any routing logic.
 */
function boot(): void
{
    // ── Output buffering ────────────────────────────────────────────────────────
    // Allows headers (Location, Set-Cookie) to be sent even if a template has
    // started producing output.
    if (ob_get_level() === 0) {
        ob_start();
    }

    // ── Session hardening ───────────────────────────────────────────────────────
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $lifetime = (int) config('session_lifetime', 86400 * 7); // default 7 days

        // Detect HTTPS: direct TLS or behind a trusted reverse proxy
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                 || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
                 || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
                     && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        // Maximise session ID entropy
        ini_set('session.sid_length',        '48');
        ini_set('session.sid_bits_per_char', '6');

        // Never transmit session ID in the URL
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid',    '0');

        // Reject unrecognised session IDs (prevents session fixation)
        ini_set('session.use_strict_mode', '1');

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'domain'   => '',         // Current domain only (no subdomain sharing)
            'secure'   => $is_https,  // HTTPS-only cookie in production
            'httponly' => true,       // Inaccessible to JavaScript
            'samesite' => 'Lax',      // CSRF mitigation; allows top-level GET navigations
        ]);

        session_name('b442wp_sess');
        session_start();
    }
}

// ─── Auth guards ───────────────────────────────────────────────────────────────

/**
 * Require an authenticated session.
 *
 * If the user is not logged in, stores a flash error message and redirects
 * to /login. Otherwise returns normally and execution continues.
 */
function require_auth(): void
{
    if (!is_logged_in()) {
        flash('error', 'Please log in to continue.', 'error');
        redirect('/login');
    }
}

/**
 * Require a guest (unauthenticated) session.
 *
 * Intended for pages like /login and /register that should not be accessible
 * to users who are already logged in. Redirects to the dashboard on success.
 */
function require_guest(): void
{
    if (is_logged_in()) {
        redirect('/');
    }
}

// ─── Rate limiting ─────────────────────────────────────────────────────────────

/**
 * Simple session-backed rate limiter.
 *
 * Stores counters in $_SESSION['rate_limits'][$key] so each user/IP
 * combination has its own bucket without a database round-trip.
 *
 * Usage example (5 login attempts per 5 minutes per IP):
 *   if (!rate_limit('login_' . $_SERVER['REMOTE_ADDR'], 5, 300)) {
 *       // Too many attempts — deny the request
 *   }
 *
 * @param  string $key            Unique identifier for the action + subject.
 * @param  int    $max            Maximum allowed attempts within the window.
 * @param  int    $window_seconds Duration of the sliding window in seconds.
 * @return bool                   true  = under limit, request allowed.
 *                                false = limit exceeded, request should be denied.
 */
function rate_limit(string $key, int $max, int $window_seconds): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Cannot rate-limit without a session; allow by default
        return true;
    }

    $now = time();

    if (!isset($_SESSION['rate_limits'][$key])) {
        $_SESSION['rate_limits'][$key] = [
            'count'      => 0,
            'window_end' => $now + $window_seconds,
        ];
    }

    $bucket = &$_SESSION['rate_limits'][$key];

    // Reset the window when it has expired
    if ($now >= $bucket['window_end']) {
        $bucket['count']      = 0;
        $bucket['window_end'] = $now + $window_seconds;
    }

    $bucket['count']++;

    return $bucket['count'] <= $max;
}

// ─── Conversion ownership ──────────────────────────────────────────────────────

/**
 * Verify that the given conversion UUID exists and belongs to the current user.
 *
 * On failure: stores a flash error and redirects to the dashboard.
 * On success: returns the full conversion row.
 *
 * @param  string               $uuid  The conversion UUID from the URL.
 * @return array<string, mixed>        Row from the conversions table.
 */
function verify_conversion_owner(string $uuid): array
{
    require_auth();

    $user = current_user();

    if ($user === null || $uuid === '') {
        flash('error', 'Conversion not found.', 'error');
        redirect('/');
    }

    try {
        $stmt = db()->prepare(
            'SELECT *
               FROM conversions
              WHERE uuid    = :uuid
                AND user_id = :user_id
              LIMIT 1'
        );
        $stmt->execute([':uuid' => $uuid, ':user_id' => (int) $user['id']]);
        $conversion = $stmt->fetch();
    } catch (PDOException) {
        flash('error', 'A database error occurred.', 'error');
        redirect('/');
    }

    if (!$conversion) {
        // Do not reveal whether the conversion exists to other users
        flash('error', 'Conversion not found.', 'error');
        redirect('/');
    }

    return $conversion;
}
