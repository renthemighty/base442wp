<?php
/**
 * pages/reset-password.php — Set a new password using a reset token.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';
require_guest();

$token       = trim($_GET['token'] ?? '');
$token_data  = false;
$errors      = [];
$token_error = null;

// Validate token immediately on page load
if (empty($token)) {
    $token_error = 'No reset token provided. Please request a new password reset link.';
} else {
    $token_data = validate_reset_token($token);
    if ($token_data === false) {
        $token_error = 'This reset link is invalid or has expired. Please request a new one.';
    }
}

if (!$token_error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $new_password = $_POST['password']              ?? '';
    $confirm      = $_POST['password_confirmation'] ?? '';

    if (empty($new_password)) {
        $errors['password'] = 'New password is required.';
    } elseif (strlen($new_password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }

    if ($new_password !== $confirm) {
        $errors['confirm'] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $result = reset_password($token, $new_password);
        if ($result === true) {
            flash('success', 'Password reset successfully! Please log in with your new password.');
            redirect('/login');
        } else {
            $errors['general'] = is_string($result)
                ? $result
                : 'Reset failed. Your link may have expired — please request a new one.';
        }
    }
}

$page_title = 'Reset Password';
$body_class = 'page-auth';
ob_start();
?>
<div class="auth-wrapper">
    <div class="auth-card card">

        <div class="auth-card__header">
            <div class="auth-card__icon" aria-hidden="true">
                <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                    <rect width="32" height="32" rx="8" fill="#eff6ff"/>
                    <path d="M11 16.5a5 5 0 119.9 0" stroke="#3b82f6" stroke-width="1.5" stroke-linecap="round"/>
                    <rect x="8" y="16" width="16" height="9" rx="2" stroke="#3b82f6" stroke-width="1.5"/>
                    <circle cx="16" cy="20.5" r="1.25" fill="#3b82f6"/>
                </svg>
            </div>
            <h1 class="auth-card__title">Choose a new password</h1>
            <p class="auth-card__subtitle">
                <?php if ($token_data && isset($token_data['email'])): ?>
                Setting a new password for <strong><?= htmlspecialchars($token_data['email']) ?></strong>
                <?php else: ?>
                Enter a strong new password for your account
                <?php endif; ?>
            </p>
        </div>

        <?php if ($token_error): ?>
        <!-- Invalid / expired token -->
        <div class="form-alert form-alert--error" role="alert">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/>
                <path d="M10 6v5M10 13.5v.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            </svg>
            <span><?= htmlspecialchars($token_error) ?></span>
        </div>
        <div class="auth-card__actions">
            <a href="<?= base_url('/forgot-password') ?>" class="btn btn--primary btn--full">Request a new reset link</a>
            <a href="<?= base_url('/login') ?>" class="btn btn--outline btn--full">Back to Log In</a>
        </div>

        <?php else: ?>
        <!-- Valid token — show reset form -->
        <?php if (isset($errors['general'])): ?>
        <div class="form-alert form-alert--error" role="alert">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/>
                <path d="M10 6v5M10 13.5v.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            </svg>
            <span><?= htmlspecialchars($errors['general']) ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="<?= base_url('/reset-password') ?>?token=<?= urlencode($token) ?>" class="auth-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <div class="form-group">
                <label for="password" class="form-label">
                    New password
                    <span class="form-label-hint">min. 8 characters</span>
                </label>
                <div class="form-input-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-input<?= isset($errors['password']) ? ' form-input--error' : '' ?>"
                        placeholder="••••••••"
                        autocomplete="new-password"
                        minlength="8"
                        required
                        autofocus
                    >
                    <button type="button" class="form-input-toggle-pw" aria-label="Show/hide password" tabindex="-1">
                        <svg class="pw-show-icon" width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="M1 10s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6z" stroke="currentColor" stroke-width="1.5"/>
                            <circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                    </button>
                </div>
                <?php if (isset($errors['password'])): ?>
                <p class="form-error" role="alert"><?= htmlspecialchars($errors['password']) ?></p>
                <?php endif; ?>
                <div class="password-strength" id="pw-strength" aria-live="polite" hidden>
                    <div class="password-strength__bar">
                        <div class="password-strength__fill" id="pw-strength-fill"></div>
                    </div>
                    <span class="password-strength__label" id="pw-strength-label"></span>
                </div>
            </div>

            <div class="form-group">
                <label for="password_confirmation" class="form-label">Confirm new password</label>
                <div class="form-input-wrap">
                    <input
                        type="password"
                        id="password_confirmation"
                        name="password_confirmation"
                        class="form-input<?= isset($errors['confirm']) ? ' form-input--error' : '' ?>"
                        placeholder="••••••••"
                        autocomplete="new-password"
                        data-confirm-for="password"
                        required
                    >
                </div>
                <?php if (isset($errors['confirm'])): ?>
                <p class="form-error" id="confirm-error" role="alert"><?= htmlspecialchars($errors['confirm']) ?></p>
                <?php else: ?>
                <p class="form-error" id="confirm-error" role="alert" hidden></p>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn--primary btn--full btn--lg">
                <span class="btn-label">Reset password</span>
                <span class="btn-loading" hidden>
                    <span class="spinner spinner--sm" aria-hidden="true"></span>
                    Resetting…
                </span>
            </button>
        </form>
        <?php endif; ?>

        <p class="auth-card__footer-text">
            <a href="<?= base_url('/login') ?>" class="auth-card__footer-link">Back to Log In</a>
        </p>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
