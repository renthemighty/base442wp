<?php
/**
 * pages/register.php — New user registration page.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';
require_guest();

$errors = [];
$values = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $name     = trim($_POST['name']             ?? '');
    $email    = trim($_POST['email']            ?? '');
    $password = $_POST['password']              ?? '';
    $confirm  = $_POST['password_confirmation'] ?? '';

    $values = ['name' => $name, 'email' => $email];

    // Server-side validation
    if (empty($name)) {
        $errors['name'] = 'Full name is required.';
    } elseif (strlen($name) < 2) {
        $errors['name'] = 'Name must be at least 2 characters.';
    } elseif (strlen($name) > 100) {
        $errors['name'] = 'Name must be 100 characters or fewer.';
    }

    if (empty($email)) {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if (empty($password)) {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }

    if ($password !== $confirm) {
        $errors['confirm'] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $result = register_user($name, $email, $password);
        if ($result === true) {
            login_user($email, $password);
            flash('success', 'Welcome! Upload your first Base44 project.');
            redirect('/upload');
        } else {
            $errors['general'] = is_string($result) ? $result : 'Registration failed. Please try again.';
        }
    }
}

$page_title = 'Create Account';
$body_class = 'page-auth';
ob_start();
?>
<div class="auth-wrapper">
    <div class="auth-card auth-card--wide card">

        <div class="auth-card__header">
            <div class="auth-card__icon" aria-hidden="true">
                <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                    <rect width="32" height="32" rx="8" fill="#f0fdf4"/>
                    <path d="M20 22v-1.5a3.5 3.5 0 00-3.5-3.5h-5A3.5 3.5 0 008 20.5V22" stroke="#22c55e" stroke-width="1.75" stroke-linecap="round"/>
                    <circle cx="13" cy="12" r="3" stroke="#22c55e" stroke-width="1.75"/>
                    <path d="M23 12v4M21 14h4" stroke="#22c55e" stroke-width="1.75" stroke-linecap="round"/>
                </svg>
            </div>
            <h1 class="auth-card__title">Create your account</h1>
            <p class="auth-card__subtitle">Start converting Base44 projects to WordPress themes</p>
        </div>

        <?php if (isset($errors['general'])): ?>
        <div class="form-alert form-alert--error" role="alert">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/>
                <path d="M10 6v5M10 13.5v.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            </svg>
            <span><?= htmlspecialchars($errors['general']) ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="<?= base_url('/register') ?>" class="auth-form" id="register-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

            <div class="form-group">
                <label for="name" class="form-label">Full name</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    class="form-input<?= isset($errors['name']) ? ' form-input--error' : '' ?>"
                    value="<?= htmlspecialchars($values['name']) ?>"
                    placeholder="Jane Smith"
                    autocomplete="name"
                    required
                    autofocus
                >
                <?php if (isset($errors['name'])): ?>
                <p class="form-error" role="alert"><?= htmlspecialchars($errors['name']) ?></p>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="email" class="form-label">Email address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="form-input<?= isset($errors['email']) ? ' form-input--error' : '' ?>"
                    value="<?= htmlspecialchars($values['email']) ?>"
                    placeholder="you@example.com"
                    autocomplete="email"
                    required
                >
                <?php if (isset($errors['email'])): ?>
                <p class="form-error" role="alert"><?= htmlspecialchars($errors['email']) ?></p>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="password" class="form-label">
                    Password
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
                <label for="password_confirmation" class="form-label">Confirm password</label>
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

            <div class="form-group form-group--checkbox">
                <label class="checkbox-label">
                    <input type="checkbox" name="agree_terms" value="1" required class="checkbox-input">
                    <span class="checkbox-custom"></span>
                    I agree to the <a href="<?= base_url('/terms') ?>" target="_blank" class="link">Terms of Service</a>
                    and <a href="<?= base_url('/privacy') ?>" target="_blank" class="link">Privacy Policy</a>
                </label>
            </div>

            <button type="submit" class="btn btn--primary btn--full btn--lg">
                <span class="btn-label">Create account</span>
                <span class="btn-loading" hidden>
                    <span class="spinner spinner--sm" aria-hidden="true"></span>
                    Creating account…
                </span>
            </button>
        </form>

        <p class="auth-card__footer-text">
            Already have an account?
            <a href="<?= base_url('/login') ?>" class="auth-card__footer-link">Log in</a>
        </p>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
