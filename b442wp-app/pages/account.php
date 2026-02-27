<?php
/**
 * pages/account.php — User account settings page.
 * Three sections: profile update, password change, account info.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';
require_auth();

$user   = current_user();
$action = $_POST['action'] ?? null;

$profile_errors  = [];
$password_errors = [];
$profile_values  = ['name' => $user['name'], 'email' => $user['email']];

// ---------------------------------------------------------------------------
// Handle POST: profile update
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'profile') {
    csrf_verify();

    $name  = trim($_POST['name']  ?? '');
    $email = trim($_POST['email'] ?? '');

    $profile_values = ['name' => $name, 'email' => $email];

    if (empty($name)) {
        $profile_errors['name'] = 'Full name is required.';
    } elseif (strlen($name) < 2) {
        $profile_errors['name'] = 'Name must be at least 2 characters.';
    } elseif (strlen($name) > 100) {
        $profile_errors['name'] = 'Name must be 100 characters or fewer.';
    }

    if (empty($email)) {
        $profile_errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $profile_errors['email'] = 'Please enter a valid email address.';
    }

    if (empty($profile_errors)) {
        // Check if email is already in use by another account
        $check = db()->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
        $check->execute([$email, $user['id']]);
        if ($check->fetch()) {
            $profile_errors['email'] = 'That email address is already in use by another account.';
        } else {
            $stmt = db()->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
            $stmt->execute([$name, $email, $user['id']]);
            flash('success', 'Profile updated successfully.');
            redirect('/account');
        }
    }
}

// ---------------------------------------------------------------------------
// Handle POST: password change
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'password') {
    csrf_verify();

    $current     = $_POST['current_password']       ?? '';
    $new_pw      = $_POST['new_password']            ?? '';
    $confirm     = $_POST['new_password_confirm']    ?? '';

    if (empty($current)) {
        $password_errors['current'] = 'Current password is required.';
    }

    if (empty($new_pw)) {
        $password_errors['new'] = 'New password is required.';
    } elseif (strlen($new_pw) < 8) {
        $password_errors['new'] = 'New password must be at least 8 characters.';
    }

    if ($new_pw !== $confirm) {
        $password_errors['confirm'] = 'Passwords do not match.';
    }

    if (empty($password_errors)) {
        // Verify current password
        if (!password_verify($current, $user['password_hash'] ?? '')) {
            $password_errors['current'] = 'Current password is incorrect.';
        } else {
            $new_hash = password_hash($new_pw, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$new_hash, $user['id']]);
            flash('success', 'Password changed successfully.');
            redirect('/account');
        }
    }
}

// ---------------------------------------------------------------------------
// Account stats
// ---------------------------------------------------------------------------
$stats_stmt = db()->prepare(
    'SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = "complete" THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status = "failed"   THEN 1 ELSE 0 END) AS failed
     FROM conversions WHERE user_id = ?'
);
$stats_stmt->execute([$user['id']]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$page_title = 'Account Settings';
$body_class = 'page-account';
ob_start();
?>
<div class="page-content">
    <div class="container container--narrow">

        <div class="page-header page-header--simple">
            <h1 class="page-header__title">Account Settings</h1>
        </div>

        <div class="account-sections">

            <!-- ============================================================
                 Section 1: Update Profile
            ============================================================ -->
            <section class="card account-section" aria-labelledby="section-profile">
                <div class="account-section__header">
                    <div class="account-section__icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="7" r="3.5" stroke="#3b82f6" stroke-width="1.5"/>
                            <path d="M3 17c0-3.314 3.134-6 7-6s7 2.686 7 6" stroke="#3b82f6" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <div>
                        <h2 id="section-profile" class="account-section__title">Profile</h2>
                        <p class="account-section__subtitle">Update your name and email address</p>
                    </div>
                </div>

                <?php if (!empty($profile_errors) && $action === 'profile'): ?>
                <div class="form-alert form-alert--error" role="alert">
                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M10 6v5M10 13.5v.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                    </svg>
                    <ul class="form-alert__list">
                        <?php foreach ($profile_errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <form method="POST" action="<?= base_url('/account') ?>" class="account-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="action" value="profile">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="profile-name" class="form-label">Full name</label>
                            <input
                                type="text"
                                id="profile-name"
                                name="name"
                                class="form-input<?= isset($profile_errors['name']) ? ' form-input--error' : '' ?>"
                                value="<?= htmlspecialchars($profile_values['name']) ?>"
                                autocomplete="name"
                                required
                            >
                            <?php if (isset($profile_errors['name'])): ?>
                            <p class="form-error" role="alert"><?= htmlspecialchars($profile_errors['name']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="profile-email" class="form-label">Email address</label>
                            <input
                                type="email"
                                id="profile-email"
                                name="email"
                                class="form-input<?= isset($profile_errors['email']) ? ' form-input--error' : '' ?>"
                                value="<?= htmlspecialchars($profile_values['email']) ?>"
                                autocomplete="email"
                                required
                            >
                            <?php if (isset($profile_errors['email'])): ?>
                            <p class="form-error" role="alert"><?= htmlspecialchars($profile_errors['email']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="account-form__footer">
                        <button type="submit" class="btn btn--primary">
                            <span class="btn-label">Save profile</span>
                            <span class="btn-loading" hidden>
                                <span class="spinner spinner--sm" aria-hidden="true"></span>
                                Saving…
                            </span>
                        </button>
                    </div>
                </form>
            </section>

            <!-- ============================================================
                 Section 2: Change Password
            ============================================================ -->
            <section class="card account-section" aria-labelledby="section-password">
                <div class="account-section__header">
                    <div class="account-section__icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <rect x="4" y="9" width="12" height="9" rx="2" stroke="#3b82f6" stroke-width="1.5"/>
                            <path d="M7 9V6.5a3 3 0 116 0V9" stroke="#3b82f6" stroke-width="1.5" stroke-linecap="round"/>
                            <circle cx="10" cy="13.5" r="1" fill="#3b82f6"/>
                        </svg>
                    </div>
                    <div>
                        <h2 id="section-password" class="account-section__title">Password</h2>
                        <p class="account-section__subtitle">Choose a strong, unique password</p>
                    </div>
                </div>

                <?php if (!empty($password_errors) && $action === 'password'): ?>
                <div class="form-alert form-alert--error" role="alert">
                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M10 6v5M10 13.5v.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                    </svg>
                    <ul class="form-alert__list">
                        <?php foreach ($password_errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <form method="POST" action="<?= base_url('/account') ?>" class="account-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="action" value="password">

                    <div class="form-group">
                        <label for="current_password" class="form-label">Current password</label>
                        <div class="form-input-wrap">
                            <input
                                type="password"
                                id="current_password"
                                name="current_password"
                                class="form-input<?= isset($password_errors['current']) ? ' form-input--error' : '' ?>"
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
                        <?php if (isset($password_errors['current'])): ?>
                        <p class="form-error" role="alert"><?= htmlspecialchars($password_errors['current']) ?></p>
                        <?php endif; ?>
                        <p class="form-hint">
                            <a href="<?= base_url('/forgot-password') ?>" class="link">Forgot your current password?</a>
                        </p>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_password" class="form-label">
                                New password
                                <span class="form-label-hint">min. 8 characters</span>
                            </label>
                            <div class="form-input-wrap">
                                <input
                                    type="password"
                                    id="new_password"
                                    name="new_password"
                                    class="form-input<?= isset($password_errors['new']) ? ' form-input--error' : '' ?>"
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
                            <?php if (isset($password_errors['new'])): ?>
                            <p class="form-error" role="alert"><?= htmlspecialchars($password_errors['new']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="new_password_confirm" class="form-label">Confirm new password</label>
                            <div class="form-input-wrap">
                                <input
                                    type="password"
                                    id="new_password_confirm"
                                    name="new_password_confirm"
                                    class="form-input<?= isset($password_errors['confirm']) ? ' form-input--error' : '' ?>"
                                    placeholder="••••••••"
                                    autocomplete="new-password"
                                    data-confirm-for="new_password"
                                    required
                                >
                            </div>
                            <?php if (isset($password_errors['confirm'])): ?>
                            <p class="form-error" id="confirm-error" role="alert"><?= htmlspecialchars($password_errors['confirm']) ?></p>
                            <?php else: ?>
                            <p class="form-error" id="confirm-error" role="alert" hidden></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="account-form__footer">
                        <button type="submit" class="btn btn--primary">
                            <span class="btn-label">Change password</span>
                            <span class="btn-loading" hidden>
                                <span class="spinner spinner--sm" aria-hidden="true"></span>
                                Updating…
                            </span>
                        </button>
                    </div>
                </form>
            </section>

            <!-- ============================================================
                 Section 3: Account Info (read-only)
            ============================================================ -->
            <section class="card account-section account-section--info" aria-labelledby="section-info">
                <div class="account-section__header">
                    <div class="account-section__icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="8.5" stroke="#3b82f6" stroke-width="1.5"/>
                            <path d="M10 9v5M10 6v.5" stroke="#3b82f6" stroke-width="1.75" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <div>
                        <h2 id="section-info" class="account-section__title">Account Info</h2>
                        <p class="account-section__subtitle">Your membership details</p>
                    </div>
                </div>

                <dl class="account-info-list">
                    <div class="account-info-item">
                        <dt class="account-info-item__label">Member since</dt>
                        <dd class="account-info-item__value">
                            <time datetime="<?= htmlspecialchars($user['created_at'] ?? '') ?>">
                                <?= htmlspecialchars(
                                    isset($user['created_at'])
                                        ? date('F j, Y', strtotime($user['created_at']))
                                        : '—'
                                ) ?>
                            </time>
                        </dd>
                    </div>
                    <div class="account-info-item">
                        <dt class="account-info-item__label">Total conversions</dt>
                        <dd class="account-info-item__value"><?= (int)($stats['total'] ?? 0) ?></dd>
                    </div>
                    <div class="account-info-item">
                        <dt class="account-info-item__label">Completed</dt>
                        <dd class="account-info-item__value account-info-item__value--success"><?= (int)($stats['completed'] ?? 0) ?></dd>
                    </div>
                    <?php if (($stats['failed'] ?? 0) > 0): ?>
                    <div class="account-info-item">
                        <dt class="account-info-item__label">Failed</dt>
                        <dd class="account-info-item__value account-info-item__value--error"><?= (int)$stats['failed'] ?></dd>
                    </div>
                    <?php endif; ?>
                    <div class="account-info-item">
                        <dt class="account-info-item__label">Account email</dt>
                        <dd class="account-info-item__value"><?= htmlspecialchars($user['email']) ?></dd>
                    </div>
                </dl>

                <div class="account-section__danger-zone">
                    <p class="account-section__danger-label">Need help or want to close your account?</p>
                    <a href="mailto:admin@base44towordpress.com?subject=Account%20request%20for%20<?= urlencode($user['email']) ?>"
                       class="btn btn--outline btn--sm">Contact support</a>
                </div>
            </section>

        </div><!-- /.account-sections -->
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
