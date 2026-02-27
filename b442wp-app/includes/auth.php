<?php
/**
 * B442WP — Authentication Functions
 *
 * Depends on:
 *   - includes/db.php      (db() singleton)
 *   - includes/helpers.php (config(), flash(), redirect())
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

// ─── Session ───────────────────────────────────────────────────────────────────

/**
 * Start the PHP session with hardened settings.
 *
 * Must be called before any output and before accessing $_SESSION.
 * Safe to call multiple times; subsequent calls are no-ops.
 */
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $lifetime = (int) config('session_lifetime', 86400 * 7);
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
             || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    // Session cookie parameters
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => '',          // Current domain only
        'secure'   => $is_https,   // HTTPS-only in production
        'httponly' => true,        // Not accessible via JavaScript
        'samesite' => 'Lax',       // CSRF mitigation without breaking OAuth flows
    ]);

    // Use a long session ID (ini default is often 26; 48 chars = 288 bits entropy)
    ini_set('session.sid_length',        '48');
    ini_set('session.sid_bits_per_char', '6');

    // Do not pass session ID in the URL
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid',    '0');

    // Regenerate the session ID occasionally to mitigate fixation
    ini_set('session.use_strict_mode', '1');

    session_name('b442wp_sess');
    session_start();
}

// ─── Current user ──────────────────────────────────────────────────────────────

/**
 * Return the currently authenticated user row, or null if not logged in.
 *
 * @return array<string, mixed>|null
 */
function current_user(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user_id'])) {
        return null;
    }

    $user_id = (int) $_SESSION['user_id'];

    try {
        $stmt = db()->prepare(
            'SELECT id, email, name, created_at, updated_at
               FROM users
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $user_id]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        // Database error — treat as not logged in rather than crashing
        return null;
    }

    return $user ?: null;
}

/**
 * Return whether a user is currently logged in.
 *
 * @return bool
 */
function is_logged_in(): bool
{
    return current_user() !== null;
}

// ─── Login / logout ────────────────────────────────────────────────────────────

/**
 * Attempt to log in with email and password.
 *
 * @param  string      $email
 * @param  string      $password  Plaintext password (never stored).
 * @return true|string            true on success; error message string on failure.
 */
function login_user(string $email, string $password): bool|string
{
    $email    = trim(strtolower($email));
    $password = trim($password);

    if ($email === '' || $password === '') {
        return 'Invalid email or password.';
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, email, password_hash, name
               FROM users
              WHERE email = :email
              LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        return 'A database error occurred. Please try again.';
    }

    // Use the same generic error message for both "user not found" and
    // "wrong password" to avoid user enumeration.
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return 'Invalid email or password.';
    }

    // Regenerate session ID to prevent session fixation attacks
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];

    // Rehash password if the cost factor or algorithm has been upgraded
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => config('bcrypt_cost', 12)])) {
        try {
            $new_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => config('bcrypt_cost', 12)]);
            $upd = db()->prepare('UPDATE users SET password_hash = :hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $upd->execute([':hash' => $new_hash, ':id' => $user['id']]);
        } catch (PDOException) {
            // Non-critical — log but don't fail the login
        }
    }

    return true;
}

/**
 * Destroy the session and clear the session cookie.
 */
function logout_user(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    // Remove all session data
    $_SESSION = [];

    // Expire the session cookie in the browser
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

// ─── Registration ──────────────────────────────────────────────────────────────

/**
 * Register a new user account.
 *
 * Validation rules:
 *   - name:     non-empty, max 100 chars
 *   - email:    valid format, unique in the database, max 254 chars (RFC 5321)
 *   - password: minimum 8 characters
 *
 * @param  string      $name
 * @param  string      $email
 * @param  string      $password  Plaintext password (hashed before storage).
 * @return true|string            true on success; error message string on failure.
 */
function register_user(string $name, string $email, string $password): bool|string
{
    $name     = trim($name);
    $email    = trim(strtolower($email));
    $password = trim($password);

    // ── Validate inputs ───────────────────────────────────────────────────────

    if ($name === '') {
        return 'Name is required.';
    }

    if (strlen($name) > 100) {
        return 'Name must be 100 characters or fewer.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'A valid email address is required.';
    }

    if (strlen($email) > 254) {
        return 'Email address is too long.';
    }

    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }

    // ── Check email uniqueness ────────────────────────────────────────────────

    try {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            return 'An account with that email address already exists.';
        }
    } catch (PDOException $e) {
        return 'A database error occurred. Please try again.';
    }

    // ── Create account ────────────────────────────────────────────────────────

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => config('bcrypt_cost', 12)]);

    try {
        $stmt = db()->prepare(
            'INSERT INTO users (name, email, password_hash, created_at, updated_at)
             VALUES (:name, :email, :hash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            ':name'  => $name,
            ':email' => $email,
            ':hash'  => $hash,
        ]);
    } catch (PDOException $e) {
        return 'Could not create your account. Please try again.';
    }

    return true;
}

// ─── Profile management ────────────────────────────────────────────────────────

/**
 * Update a user's name and email address.
 *
 * Validates input, checks email uniqueness against other users, then writes
 * the update. Returns true on success or an error message string on failure.
 *
 * @param  int         $user_id
 * @param  string      $name   New display name.
 * @param  string      $email  New email address.
 * @return true|string         true on success; error message on failure.
 */
function update_user_profile(int $user_id, string $name, string $email): bool|string
{
    $name  = trim($name);
    $email = trim(strtolower($email));

    if ($name === '') {
        return 'Name is required.';
    }

    if (strlen($name) > 100) {
        return 'Name must be 100 characters or fewer.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'A valid email address is required.';
    }

    if (strlen($email) > 254) {
        return 'Email address is too long.';
    }

    // Check that the new email is not already taken by a *different* user
    try {
        $stmt = db()->prepare(
            'SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1'
        );
        $stmt->execute([':email' => $email, ':id' => $user_id]);
        if ($stmt->fetch()) {
            return 'That email address is already in use by another account.';
        }
    } catch (PDOException) {
        return 'A database error occurred. Please try again.';
    }

    try {
        $upd = db()->prepare(
            'UPDATE users
                SET name       = :name,
                    email      = :email,
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = :id'
        );
        $upd->execute([':name' => $name, ':email' => $email, ':id' => $user_id]);
    } catch (PDOException) {
        return 'Could not update your profile. Please try again.';
    }

    return true;
}

/**
 * Change a user's password after verifying the current one.
 *
 * @param  int         $user_id          User to update.
 * @param  string      $current_password Plaintext current password for verification.
 * @param  string      $new_password     Plaintext new password (min 8 chars).
 * @return true|string                   true on success; error message on failure.
 */
function change_password(int $user_id, string $current_password, string $new_password): bool|string
{
    $current_password = trim($current_password);
    $new_password     = trim($new_password);

    if ($current_password === '') {
        return 'Current password is required.';
    }

    if (strlen($new_password) < 8) {
        return 'New password must be at least 8 characters long.';
    }

    // Fetch the current hash
    try {
        $stmt = db()->prepare(
            'SELECT password_hash FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $user_id]);
        $user = $stmt->fetch();
    } catch (PDOException) {
        return 'A database error occurred. Please try again.';
    }

    if (!$user) {
        return 'User account not found.';
    }

    if (!password_verify($current_password, $user['password_hash'])) {
        return 'Current password is incorrect.';
    }

    $new_hash = password_hash(
        $new_password,
        PASSWORD_BCRYPT,
        ['cost' => config('bcrypt_cost', 12)]
    );

    try {
        $upd = db()->prepare(
            'UPDATE users
                SET password_hash = :hash,
                    updated_at    = CURRENT_TIMESTAMP
              WHERE id = :id'
        );
        $upd->execute([':hash' => $new_hash, ':id' => $user_id]);
    } catch (PDOException) {
        return 'Could not update your password. Please try again.';
    }

    return true;
}

// ─── Password reset ────────────────────────────────────────────────────────────

/**
 * Create a password reset token for the given email address.
 *
 * The token is 64 hex characters (256 bits), valid for 1 hour.
 * The function returns the raw token so the caller can construct and send
 * the reset email — this file does NOT send email.
 *
 * @param  string       $email
 * @return string|false  Token string on success; false if email not found.
 */
function create_password_reset(string $email): string|false
{
    $email = trim(strtolower($email));

    try {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
    } catch (PDOException) {
        return false;
    }

    if (!$user) {
        return false;
    }

    $token      = bin2hex(random_bytes(32)); // 64 hex chars, 256-bit entropy
    $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour

    try {
        // Invalidate any existing unused tokens for this user first
        $del = db()->prepare(
            'DELETE FROM password_resets WHERE user_id = :uid AND used = 0'
        );
        $del->execute([':uid' => $user['id']]);

        $ins = db()->prepare(
            'INSERT INTO password_resets (user_id, token, expires_at, used, created_at)
             VALUES (:uid, :token, :expires, 0, CURRENT_TIMESTAMP)'
        );
        $ins->execute([
            ':uid'     => $user['id'],
            ':token'   => $token,
            ':expires' => $expires_at,
        ]);
    } catch (PDOException) {
        return false;
    }

    return $token;
}

/**
 * Validate a password reset token.
 *
 * Returns the corresponding user row when the token:
 *   - exists in the database
 *   - has not been used (used = 0)
 *   - has not expired (expires_at > NOW)
 *
 * @param  string             $token  Raw hex token from the reset URL.
 * @return array<string,mixed>|false  User row on success; false otherwise.
 */
function validate_reset_token(string $token): array|false
{
    if ($token === '' || strlen($token) !== 64) {
        return false;
    }

    try {
        $stmt = db()->prepare(
            'SELECT pr.id AS reset_id, pr.expires_at, pr.used,
                    u.id, u.email, u.name
               FROM password_resets pr
               JOIN users u ON u.id = pr.user_id
              WHERE pr.token = :token
              LIMIT 1'
        );
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch();
    } catch (PDOException) {
        return false;
    }

    if (!$row) {
        return false;
    }

    if ((int) $row['used'] !== 0) {
        return false;
    }

    if (strtotime($row['expires_at']) <= time()) {
        return false;
    }

    return $row;
}

/**
 * Reset a user's password using a valid reset token.
 *
 * @param  string      $token        The raw hex token.
 * @param  string      $new_password Plaintext new password.
 * @return true|string               true on success; error message on failure.
 */
function reset_password(string $token, string $new_password): bool|string
{
    $new_password = trim($new_password);

    if (strlen($new_password) < 8) {
        return 'Password must be at least 8 characters long.';
    }

    $user_row = validate_reset_token($token);

    if ($user_row === false) {
        return 'This reset link is invalid or has expired. Please request a new one.';
    }

    $hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => config('bcrypt_cost', 12)]);

    try {
        $pdo = db();

        $pdo->beginTransaction();

        // Update the user's password
        $upd = $pdo->prepare(
            'UPDATE users
                SET password_hash = :hash,
                    updated_at    = CURRENT_TIMESTAMP
              WHERE id = :id'
        );
        $upd->execute([':hash' => $hash, ':id' => $user_row['id']]);

        // Mark the token as used so it cannot be replayed
        $mark = $pdo->prepare(
            'UPDATE password_resets
                SET used = 1
              WHERE token = :token'
        );
        $mark->execute([':token' => $token]);

        $pdo->commit();
    } catch (PDOException $e) {
        try { db()->rollBack(); } catch (PDOException) {}
        return 'Could not update your password. Please try again.';
    }

    return true;
}
