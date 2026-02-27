<?php
/**
 * header.php — Application navigation bar partial.
 * Included by layout.php. Uses is_logged_in() and current_user().
 */
$_nav_user    = current_user();
$_nav_loggedin = is_logged_in();
$_current_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

/**
 * Helper: returns ' aria-current="page"' when $path matches current URL.
 */
function _nav_active(string $path): string {
    global $_current_path;
    return $_current_path === $path ? ' aria-current="page" class="nav__link nav__link--active"' : ' class="nav__link"';
}
?>
<header class="nav" role="banner">
    <div class="container nav__inner">

        <!-- Logo -->
        <a href="<?= base_url('/') ?>" class="nav__logo" aria-label="Base44 to WP — home">
            <svg class="nav__logo-icon" width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true">
                <rect x="1" y="1" width="8" height="8" rx="1.5" fill="#3b82f6" opacity=".9"/>
                <rect x="1" y="13" width="8" height="8" rx="1.5" fill="#3b82f6" opacity=".6"/>
                <rect x="13" y="1" width="8" height="8" rx="1.5" fill="#3b82f6" opacity=".6"/>
                <rect x="13" y="13" width="8" height="8" rx="1.5" fill="#3b82f6" opacity=".9"/>
                <path d="M9.5 11h3M11 9.5l1.5 1.5-1.5 1.5" stroke="#3b82f6" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span class="nav__logo-text">Base44<span class="nav__logo-arrow" aria-hidden="true">→</span>WP</span>
        </a>

        <!-- Desktop Navigation Links -->
        <nav class="nav__links" role="navigation" aria-label="Main navigation">
            <?php if ($_nav_loggedin): ?>
                <a href="<?= base_url('/') ?>"<?= _nav_active('/') ?>>Dashboard</a>
                <a href="<?= base_url('/upload') ?>"<?= _nav_active('/upload') ?>>New Conversion</a>
                <a href="<?= base_url('/account') ?>"<?= _nav_active('/account') ?>>
                    <span class="nav__account-avatar" aria-hidden="true">
                        <?= htmlspecialchars(mb_strtoupper(mb_substr($_nav_user['name'] ?? 'U', 0, 1))) ?>
                    </span>
                    <?= htmlspecialchars($_nav_user['name'] ?? 'Account') ?>
                </a>
                <form method="POST" action="<?= base_url('/logout') ?>" class="nav__logout-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <button type="submit" class="nav__link nav__logout-btn">Log out</button>
                </form>
            <?php else: ?>
                <a href="<?= base_url('/login') ?>"<?= _nav_active('/login') ?>>Log in</a>
                <a href="<?= base_url('/register') ?>" class="btn btn--primary btn--sm<?= $_current_path === '/register' ? ' nav__link--active' : '' ?>">Get Started</a>
            <?php endif; ?>
        </nav>

        <!-- Mobile Hamburger Toggle -->
        <button
            class="nav__mobile-toggle"
            aria-label="Toggle navigation menu"
            aria-expanded="false"
            aria-controls="mobile-menu"
            type="button"
        >
            <span class="nav__hamburger-bar"></span>
            <span class="nav__hamburger-bar"></span>
            <span class="nav__hamburger-bar"></span>
        </button>
    </div>

    <!-- Mobile Menu Overlay -->
    <div class="nav__mobile-menu" id="mobile-menu" aria-hidden="true" role="navigation" aria-label="Mobile navigation">
        <nav class="nav__mobile-links">
            <?php if ($_nav_loggedin): ?>
                <a href="<?= base_url('/') ?>" class="nav__mobile-link<?= $_current_path === '/' ? ' nav__mobile-link--active' : '' ?>">
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="M3 9l7-7 7 7v8a1 1 0 01-1 1H4a1 1 0 01-1-1V9z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                    </svg>
                    Dashboard
                </a>
                <a href="<?= base_url('/upload') ?>" class="nav__mobile-link<?= $_current_path === '/upload' ? ' nav__mobile-link--active' : '' ?>">
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="M10 14V6M7 9l3-3 3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M4 16h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    New Conversion
                </a>
                <a href="<?= base_url('/account') ?>" class="nav__mobile-link<?= $_current_path === '/account' ? ' nav__mobile-link--active' : '' ?>">
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <circle cx="10" cy="7" r="3.5" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M3 17c0-3.314 3.134-6 7-6s7 2.686 7 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    <?= htmlspecialchars($_nav_user['name'] ?? 'Account') ?>
                </a>
                <div class="nav__mobile-divider"></div>
                <form method="POST" action="<?= base_url('/logout') ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <button type="submit" class="nav__mobile-link nav__mobile-logout">
                        <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="M7 17H4a1 1 0 01-1-1V4a1 1 0 011-1h3M13 14l3-4-3-4M16 10H8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Log out
                    </button>
                </form>
            <?php else: ?>
                <a href="<?= base_url('/login') ?>" class="nav__mobile-link<?= $_current_path === '/login' ? ' nav__mobile-link--active' : '' ?>">Log in</a>
                <a href="<?= base_url('/register') ?>" class="nav__mobile-link nav__mobile-link--cta<?= $_current_path === '/register' ? ' nav__mobile-link--active' : '' ?>">Get Started — Free</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
