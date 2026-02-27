<?php
/**
 * pages/forgot-password.php — Password reset request page.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';
require_guest();

$submitted  = false;
$dev_token  = null;
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        $token = create_password_reset($email);

        if ($token !== false) {
            // In development: surface the token so dev can test without an SMTP server
            if (defined('APP_ENV') && APP_ENV === 'development') {
                $dev_token = $token;
            } else {
                // Production: send reset email via mail()
                $reset_url  = base_url('/reset-password') . '?token=' . urlencode($token);
                $subject    = 'Reset your Base44→WP password';
                $body       = implode("\r\n", [
                    'Hello,',
                    '',
                    'We received a request to reset the password for your Base44→WP account.',
                    '',
                    'Click the link below to choose a new password (valid for 1 hour):',
                    $reset_url,
                    '',
                    'If you did not request a password reset, you can safely ignore this email.',
                    '',
                    '— The Base44→WP team',
                    'admin@base44towordpress.com',
                ]);
                $headers = implode("\r\n", [
                    'From: Base44→WP <admin@base44towordpress.com>',
                    'Reply-To: admin@base44towordpress.com',
                    'Content-Type: text/plain; charset=UTF-8',
                    'X-Mailer: B442WP/1.0',
                ]);
                @mail($email, $subject, $body, $headers);
            }
        }
        // Always show success — never reveal whether the email exists
        $submitted = true;
    }
}

$page_title = 'Forgot Password';
$body_class = 'page-auth';
ob_start();
?>
<div class="auth-wrapper">
    <div class="auth-card card">

        <div class="auth-card__header">
            <div class="auth-card__icon" aria-hidden="true">
                <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                    <rect width="32" height="32" rx="8" fill="#fffbeb"/>
                    <path d="M16 9a5 5 0 00-5 5v1h-1a1 1 0 00-1 1v6a1 1 0 001 1h12a1 1 0 001-1v-6a1 1 0 00-1-1h-1v-1a5 5 0 00-5-5z" stroke="#f59e0b" stroke-width="1.5" stroke-linejoin="round"/>
                    <circle cx="16" cy="18.5" r="1.25" fill="#f59e0b"/>
                    <path d="M16 19.75v1.75" stroke="#f59e0b" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </div>
            <h1 class="auth-card__title">Reset your password</h1>
            <p class="auth-card__subtitle">Enter the email address on your account and we'll send you a reset link.</p>
        </div>

        <?php if ($submitted): ?>
        <!-- Success state — always show regardless of whether email matched -->
        <div class="form-alert form-alert--success" role="status">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/>
                <path d="M6.5 10.5l2.5 2.5 4.5-5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span>If an account exists for that address, we've sent reset instructions. Check your inbox (and spam folder).</span>
        </div>

        <?php if ($dev_token !== null): ?>
        <!-- DEV-ONLY notice: not shown in production -->
        <div class="dev-notice" role="alert">
            <div class="dev-notice__header">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="M7 8l-4 4 4 4M13 8l4 4-4 4M11 4l-2 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <strong>Development mode — token surfaced for testing</strong>
            </div>
            <p class="dev-notice__body">
                Reset URL:
                <a href="<?= base_url('/reset-password') . '?token=' . urlencode($dev_token) ?>" class="link dev-notice__link">
                    <?= base_url('/reset-password') ?>?token=<?= htmlspecialchars($dev_token) ?>
                </a>
            </p>
            <p class="dev-notice__note">This notice is hidden in production (APP_ENV != development).</p>
        </div>
        <?php endif; ?>

        <a href="<?= base_url('/login') ?>" class="btn btn--outline btn--full" style="margin-top:1rem;">Back to Log In</a>

        <?php else: ?>
        <!-- Form state -->
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

        <form method="POST" action="<?= base_url('/forgot-password') ?>" class="auth-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

            <div class="form-group">
                <label for="email" class="form-label">Email address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="form-input<?= !empty($errors) ? ' form-input--error' : '' ?>"
                    value="<?= htmlspecialchars(trim($_POST['email'] ?? '')) ?>"
                    placeholder="you@example.com"
                    autocomplete="email"
                    required
                    autofocus
                >
            </div>

            <button type="submit" class="btn btn--primary btn--full btn--lg">
                <span class="btn-label">Send reset link</span>
                <span class="btn-loading" hidden>
                    <span class="spinner spinner--sm" aria-hidden="true"></span>
                    Sending…
                </span>
            </button>
        </form>
        <?php endif; ?>

        <p class="auth-card__footer-text">
            Remembered your password?
            <a href="<?= base_url('/login') ?>" class="auth-card__footer-link">Back to Log In</a>
        </p>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
