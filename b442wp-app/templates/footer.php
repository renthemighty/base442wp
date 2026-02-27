<?php
/**
 * footer.php — Application footer partial.
 * Included by layout.php.
 */
$_footer_year = date('Y');
?>
<footer class="site-footer" role="contentinfo">
    <div class="container site-footer__inner">
        <div class="site-footer__brand">
            <a href="<?= base_url('/') ?>" class="site-footer__logo" aria-label="Base44 to WP — home">
                Base44<span aria-hidden="true">→</span>WP
            </a>
            <p class="site-footer__tagline">Convert Base44 React apps to WordPress themes. Fast, automated, pay per conversion.</p>
        </div>

        <nav class="site-footer__nav" aria-label="Footer navigation">
            <div class="site-footer__nav-group">
                <h3 class="site-footer__nav-heading">Product</h3>
                <ul class="site-footer__nav-list">
                    <li><a href="<?= base_url('/') ?>" class="site-footer__link">Dashboard</a></li>
                    <li><a href="<?= base_url('/upload') ?>" class="site-footer__link">New Conversion</a></li>
                    <li><a href="<?= base_url('/pricing') ?>" class="site-footer__link">Pricing</a></li>
                </ul>
            </div>
            <div class="site-footer__nav-group">
                <h3 class="site-footer__nav-heading">Account</h3>
                <ul class="site-footer__nav-list">
                    <?php if (is_logged_in()): ?>
                    <li><a href="<?= base_url('/account') ?>" class="site-footer__link">Settings</a></li>
                    <?php else: ?>
                    <li><a href="<?= base_url('/login') ?>" class="site-footer__link">Log In</a></li>
                    <li><a href="<?= base_url('/register') ?>" class="site-footer__link">Register</a></li>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="site-footer__nav-group">
                <h3 class="site-footer__nav-heading">Support</h3>
                <ul class="site-footer__nav-list">
                    <li><a href="mailto:admin@base44towordpress.com" class="site-footer__link">Contact</a></li>
                    <li><a href="<?= base_url('/docs') ?>" class="site-footer__link">Docs</a></li>
                </ul>
            </div>
        </nav>
    </div>

    <div class="site-footer__bottom">
        <div class="container site-footer__bottom-inner">
            <p class="site-footer__copy">
                &copy; <?= $_footer_year ?> Base44 to WordPress.
                <a href="mailto:admin@base44towordpress.com" class="site-footer__link">admin@base44towordpress.com</a>
                &middot;
                <a href="https://base44towordpress.com" class="site-footer__link" target="_blank" rel="noopener noreferrer">base44towordpress.com</a>
            </p>
            <p class="site-footer__legal">
                <a href="<?= base_url('/privacy') ?>" class="site-footer__link">Privacy Policy</a>
                &middot;
                <a href="<?= base_url('/terms') ?>" class="site-footer__link">Terms of Service</a>
            </p>
        </div>
    </div>
</footer>
