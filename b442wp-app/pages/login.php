<?php
/**
 * pages/login.php — User login page.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';
require_guest();

// Simple in-memory rate limiting via session
session_start_if_not_started: // label not used, session started in auth.php
$_ip        = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$_rate_key  = 'login_attempts_' . md5($_ip);
$_rate_data = $_SESSION[$_rate_key] ?? ['count' => 0, 'window_start' => time()];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Rate limit: 5 attempts per 5 minutes per IP
    $now = time();
    if ($now - $_rate_data['window_start'] > 300) {
        $_rate_data = ['count' => 0, 'window_start' => $now];
    }
    $_rate_data['count']++;
    $_SESSION[$_rate_key] = $_rate_data;

    if ($_rate_data['count'] > 5) {
        $errors[] = 'Too many login attempts. Please wait a few minutes before trying again.';
    } else {
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email)) {
            $errors[] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif (empty($password)) {
            $errors[] = 'Password is required.';
        } else {
            $result = login_user($email, $password);
            if ($result === true) {
                unset($_SESSION[$_rate_key]);
                redirect('/');
            } else {
                $errors[] = is_string($result) ? $result : 'Invalid email or password.';
            }
        }
    }
}

$email_value = htmlspecialchars(trim($_POST['email'] ?? ''));

$page_title = 'Log In';
$body_class = 'page-auth';
ob_start();
?>
<div class="auth-wrapper">
    <div class="auth-card card">

        <div class="auth-card__header">
            <div class="auth-card__icon" aria-hidden="true">
                <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                    <rect width="32" height="32" rx="8" fill="#eff6ff"/>
                    <path d="M16 10a3.5 3.5 0 100 7 3.5 3.5 0 000-7zM10 24c0-3.314 2.686-6 6-6s6 2.686 6 6" stroke="#3b82f6" stroke-width="1.75" stroke-linecap="round"/>
                </svg>
            </div>
            <h1 class="auth-card__title">Welcome back</h1>
            <p class="auth-card__subtitle">Log in to your Base44→WP account</p>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="form-alert form-alert--error" role="alert">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/>
                <path d="M10 6v5M10 13.5v.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            </svg>
            <ul class="form-alert__list">
                <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" action="<?= base_url('/login') ?>" class="auth-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

            <div class="form-group">
                <label for="email" class="form-label">Email address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="form-input<?= !empty($errors) ? ' form-input--error' : '' ?>"
                    value="<?= $email_value ?>"
                    placeholder="you@example.com"
                    autocomplete="email"
                    required
                    autofocus
                >
            </div>

            <div class="form-group">
                <div class="form-label-row">
                    <label for="password" class="form-label">Password</label>
                    <a href="<?= base_url('/forgot-password') ?>" class="form-label-link">Forgot password?</a>
                </div>
                <div class="form-input-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-input<?= !empty($errors) ? ' form-input--error' : '' ?>"
                        placeholder="••••••••"
                        autocomplete="current-password"
                        required
                    >
                    <button type="button" class="form-input-toggle-pw" aria-label="Show/hide password" tabindex="-1">
                        <svg class="pw-show-icon" width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="M1 10s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6z" stroke="currentColor" stroke-width="1.5"/>
                            <circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn--primary btn--full btn--lg">
                <span class="btn-label">Log in</span>
                <span class="btn-loading" hidden>
                    <span class="spinner spinner--sm" aria-hidden="true"></span>
                    Logging in…
                </span>
            </button>
        </form>

        <p class="auth-card__footer-text">
            Don't have an account?
            <a href="<?= base_url('/register') ?>" class="auth-card__footer-link">Create one — it's free</a>
        </p>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
