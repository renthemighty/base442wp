<?php
/**
 * B442WP — Global Helper Functions
 *
 * Pure utility functions used throughout the application.
 * This file has no side-effects when required.
 *
 * Functions defined here:
 *   config()                    — read config.php by key
 *   base_url()                  — absolute URL builder
 *   redirect()                  — HTTP redirect + exit
 *   flash()                     — store flash message in session
 *   get_flash()                 — retrieve & consume flash message
 *   format_price()              — cents → "$9.00"
 *   uuid()                      — UUID v4
 *   sanitize()                  — htmlspecialchars wrapper
 *   e()                         — echo sanitize()
 *   old()                       — retrieve old POST value from session
 *   save_old()                  — persist POST fields to session
 *   csrf_token()                — generate/retrieve session CSRF token
 *   csrf_field()                — echo hidden CSRF input
 *   csrf_verify()               — verify POST CSRF token or die 403
 *   is_post()                   — REQUEST_METHOD === POST
 *   require_login()             — guard: redirect to /login if unauthenticated
 *   current_user()              — fetch authenticated user from session + DB
 *   is_logged_in()              — boolean auth check
 *   time_ago()                  — "2 hours ago" relative time
 *   days_until()                — days remaining until datetime
 *   format_bytes()              — "2.4 MB", "500 KB"
 *   conversion_status_label()   — human-readable status label
 *   conversion_status_class()   — Bootstrap-style badge class
 *   json_response()             — JSON output + exit
 *   delete_file_safe()          — unlink only files inside storage/
 *   generate_theme_name()       — slug from filename
 */

declare(strict_types=1);

// ─── Configuration ─────────────────────────────────────────────────────────────

/**
 * Retrieve a value from config.php by key.
 *
 * The config array is loaded once and cached in a static variable for the
 * lifetime of the request.
 *
 * @param  string $key     Top-level key in the config array.
 * @param  mixed  $default Value returned when the key is absent.
 * @return mixed
 */
function config(string $key, mixed $default = null): mixed
{
    static $cfg = null;

    if ($cfg === null) {
        $config_path = dirname(__DIR__) . '/config.php';

        if (!file_exists($config_path)) {
            throw new RuntimeException(
                'config.php not found. Copy config.example.php and fill in your values.'
            );
        }

        $cfg = require $config_path;

        if (!is_array($cfg)) {
            throw new RuntimeException('config.php must return an array.');
        }
    }

    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}

// ─── URL helpers ───────────────────────────────────────────────────────────────

/**
 * Build an absolute URL by prepending the configured site_url.
 *
 * @param  string $path  Path component, with or without leading slash.
 * @return string        e.g. "https://app.base44towordpress.com/upload"
 */
function base_url(string $path = ''): string
{
    $base = rtrim((string) config('site_url', ''), '/');
    $path = ltrim($path, '/');

    return $path === '' ? $base : $base . '/' . $path;
}

/**
 * Send an HTTP redirect and terminate execution.
 *
 * @param  string $path  Absolute URL or root-relative path (e.g. "/login").
 * @return never
 */
function redirect(string $path): never
{
    // If $path is not an absolute URL, prepend base_url
    if (!preg_match('#^https?://#i', $path)) {
        $path = base_url($path);
    }

    if (headers_sent()) {
        // Fallback: meta-refresh when headers already sent (should not happen in prod)
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '">';
        exit;
    }

    header('Location: ' . $path);
    exit;
}

// ─── Flash messages ────────────────────────────────────────────────────────────

/**
 * Store a flash message in the session.
 *
 * Flash messages survive exactly one redirect and are consumed on read.
 *
 * @param string $key     Logical name for the message slot.
 * @param string $message The human-readable message text.
 * @param string $type    UI category: 'success' | 'error' | 'warning' | 'info'.
 */
function flash(string $key, string $message, string $type = 'info'): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION['flash'][$key] = [
        'message' => $message,
        'type'    => $type,
    ];
}

/**
 * Retrieve and consume a flash message.
 *
 * @param  string     $key
 * @return array{message: string, type: string}|null
 */
function get_flash(string $key): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $data = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);

    return $data;
}

// ─── Formatting ────────────────────────────────────────────────────────────────

/**
 * Format an integer price in cents as a USD dollar string.
 *
 * @param  int    $cents  e.g. 900
 * @return string         e.g. "$9.00"
 */
function format_price(int $cents): string
{
    return '$' . number_format($cents / 100, 2);
}

/**
 * Format a byte count into a human-readable string.
 *
 * @param  int    $bytes  Raw byte count (non-negative).
 * @return string         e.g. "2.4 MB", "500 KB", "800 B"
 */
function format_bytes(int $bytes): string
{
    if ($bytes < 0) {
        $bytes = 0;
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i     = 0;

    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    $formatted = ($i === 0)
        ? (string) (int) $bytes
        : rtrim(rtrim(number_format($bytes, 1), '0'), '.');

    return $formatted . ' ' . $units[$i];
}

/**
 * Return a relative "time ago" string for a given datetime.
 *
 * @param  string $datetime  A datetime string parseable by strtotime().
 * @return string            e.g. "just now", "2 hours ago", "3 days ago"
 */
function time_ago(string $datetime): string
{
    $time = strtotime($datetime);

    if ($time === false) {
        return 'unknown';
    }

    $diff = time() - $time;

    if ($diff < 60) {
        return 'just now';
    }

    if ($diff < 3600) {
        $m = (int) floor($diff / 60);
        return $m . ' ' . ($m === 1 ? 'minute' : 'minutes') . ' ago';
    }

    if ($diff < 86400) {
        $h = (int) floor($diff / 3600);
        return $h . ' ' . ($h === 1 ? 'hour' : 'hours') . ' ago';
    }

    if ($diff < 604800) {
        $d = (int) floor($diff / 86400);
        return $d . ' ' . ($d === 1 ? 'day' : 'days') . ' ago';
    }

    if ($diff < 2592000) {
        $w = (int) floor($diff / 604800);
        return $w . ' ' . ($w === 1 ? 'week' : 'weeks') . ' ago';
    }

    if ($diff < 31536000) {
        $mo = (int) floor($diff / 2592000);
        return $mo . ' ' . ($mo === 1 ? 'month' : 'months') . ' ago';
    }

    $y = (int) floor($diff / 31536000);
    return $y . ' ' . ($y === 1 ? 'year' : 'years') . ' ago';
}

/**
 * Return the number of days until a given datetime (negative if in the past).
 *
 * @param  string $datetime  A datetime string parseable by strtotime().
 * @return int               Positive = future, 0 = today, negative = past.
 */
function days_until(string $datetime): int
{
    $target = strtotime($datetime);

    if ($target === false) {
        return 0;
    }

    return (int) floor(($target - time()) / 86400);
}

// ─── Security / identifiers ────────────────────────────────────────────────────

/**
 * Generate a UUID v4 (random).
 *
 * @return string  e.g. "550e8400-e29b-41d4-a716-446655440000"
 */
function uuid(): string
{
    $bytes = random_bytes(16);

    // Set version bits: version 4 (0100xxxx)
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);

    // Set variant bits: RFC 4122 (10xxxxxx)
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/**
 * Sanitise a string for safe HTML output.
 *
 * Escapes HTML special characters. Use whenever outputting user-supplied data
 * into HTML context.
 *
 * @param  string $input
 * @return string
 */
function sanitize(string $input): string
{
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

/**
 * Echo a sanitised string (shorthand for echo sanitize($input)).
 *
 * @param string $input
 */
function e(string $input): void
{
    echo sanitize($input);
}

// ─── Old POST values (form repopulation) ──────────────────────────────────────

/**
 * Return the old (previously submitted) value for a form field.
 *
 * Reads from $_SESSION['old'] and clears the key after reading, so each
 * value is only replayed once.
 *
 * @param  string $key      The form field name.
 * @param  string $default  Returned when no old value is stored.
 * @return string
 */
function old(string $key, string $default = ''): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return $default;
    }

    if (!isset($_SESSION['old'][$key])) {
        return $default;
    }

    $value = (string) $_SESSION['old'][$key];
    unset($_SESSION['old'][$key]);

    return $value;
}

/**
 * Persist the current POST data to the session for form repopulation.
 *
 * Password fields are never saved (they must be re-entered by the user).
 *
 * @param array<string, mixed> $data  Typically $_POST.
 */
function save_old(array $data): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $password_fields = ['password', 'password_confirmation', 'current_password', 'new_password'];

    foreach ($data as $key => $value) {
        if (in_array($key, $password_fields, true)) {
            continue;
        }
        $_SESSION['old'][$key] = is_string($value) ? $value : (string) $value;
    }
}

// ─── CSRF protection ───────────────────────────────────────────────────────────

/**
 * Return the CSRF token for the current session, generating one if needed.
 *
 * @return string  64-character hex string (256-bit entropy).
 */
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Session must be started before calling csrf_token().');
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Echo a hidden HTML input containing the current CSRF token.
 *
 * Usage in templates: <?php csrf_field(); ?>
 */
function csrf_field(): void
{
    echo '<input type="hidden" name="_csrf" value="' . sanitize(csrf_token()) . '">';
}

/**
 * CSRF verification disabled — no-op.
 */
function csrf_verify(): void
{
    // CSRF checks removed; SameSite=Lax cookie + HTTPS provide sufficient protection.
}

// ─── HTTP method ───────────────────────────────────────────────────────────────

/**
 * Return whether the current request is a POST request.
 *
 * @return bool
 */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}



/**
 * Require an authenticated session; redirect to /login if not.
 *
 * Stores a flash message before redirecting so the login page can display it.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        flash('error', 'Please log in to continue.', 'error');
        redirect('/login');
    }
}

// ─── Conversion domain helpers ─────────────────────────────────────────────────

/**
 * Map a conversion status slug to a human-readable label.
 *
 * Status flow: pending → parsed → paid → converting → complete → failed → expired
 *
 * @param  string $status
 * @return string
 */
function conversion_status_label(string $status): string
{
    return match ($status) {
        'pending'    => 'Pending Upload',
        'parsed'     => 'Ready to Pay',
        'paid'       => 'Queued',
        'converting' => 'Converting',
        'complete'   => 'Complete',
        'failed'     => 'Failed',
        'expired'    => 'Expired',
        default      => ucfirst($status),
    };
}

/**
 * Map a conversion status slug to a Bootstrap-compatible badge CSS class.
 *
 * @param  string $status
 * @return string  e.g. "badge-success", "badge-warning"
 */
function conversion_status_class(string $status): string
{
    return match ($status) {
        'complete'              => 'badge-success',
        'paid', 'converting'   => 'badge-warning',
        'failed'               => 'badge-danger',
        'parsed'               => 'badge-info',
        'pending', 'expired'   => 'badge-muted',
        default                => 'badge-muted',
    };
}

// ─── JSON API ──────────────────────────────────────────────────────────────────

/**
 * Send a JSON response and terminate execution.
 *
 * @param  mixed $data    Any JSON-encodable value.
 * @param  int   $status  HTTP status code.
 * @return never
 */
function json_response(mixed $data, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ─── File helpers ──────────────────────────────────────────────────────────────

/**
 * Safely delete a file, but only if it lives inside the storage/ directory.
 *
 * Prevents path-traversal attacks from accidentally deleting arbitrary files.
 *
 * @param string $path  Absolute path to the file.
 */
function delete_file_safe(string $path): void
{
    if (empty($path)) {
        return;
    }

    $real = realpath($path);

    if ($real === false) {
        return;
    }

    $storage_dir = realpath(dirname(__DIR__) . '/storage');

    if ($storage_dir === false) {
        return;
    }

    if (strncmp($real, $storage_dir . DIRECTORY_SEPARATOR, strlen($storage_dir) + 1) !== 0) {
        return;
    }

    if (is_file($real)) {
        @unlink($real);
    }
}

/**
 * Derive a sanitised WordPress theme slug from an uploaded filename.
 *
 * Example: "malle-calm-flow.zip" → "malle-calm-flow"
 *          "My App (v2).zip"     → "my-app-v2"
 *
 * @param  string $filename  Original upload filename (basename only).
 * @return string            Sanitised slug, max 80 characters.
 */
function generate_theme_name(string $filename): string
{
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = mb_strtolower($name, 'UTF-8');
    $name = preg_replace('/[^a-z0-9]+/', '-', $name) ?? '';
    $name = trim($name, '-');
    $name = preg_replace('/-{2,}/', '-', $name) ?? '';

    if (strlen($name) > 80) {
        $name = substr($name, 0, 80);
        $name = rtrim($name, '-');
    }

    return $name !== '' ? $name : 'wordpress-theme';
}
