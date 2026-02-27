<?php
/**
 * pages/preview.php — Preview analysis results and choose a payment method.
 *
 * GET /preview/{uuid}
 *
 * Authenticated. Displays the analysis panel for a 'parsed' conversion:
 * detected pages, design tokens, WooCommerce badge, price, and payment buttons.
 *
 * Status transitions handled:
 *   - 'paid' | 'converting' → redirect to /convert/{uuid}
 *   - 'complete'            → redirect to /download/{uuid}
 *   - 'failed'              → show error state
 *   - 'pending'             → show "still processing" message
 *   - 'parsed'              → show full preview (normal case)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/db.php';

// Must be authenticated; loads and ownership-checks the conversion
$conversion = verify_conversion_owner(ROUTE_UUID);
$status      = $conversion['status'] ?? 'pending';

// ─── Status routing ────────────────────────────────────────────────────────────

if (in_array($status, ['paid', 'converting'], true)) {
    redirect('/convert/' . $conversion['uuid']);
}

if ($status === 'complete') {
    redirect('/download/' . $conversion['uuid']);
}

// ─── Decode analysis data ──────────────────────────────────────────────────────

$parsed_data  = json_decode((string) ($conversion['parsed_data'] ?? '{}'), true) ?? [];
$analysis     = $parsed_data['analysis'] ?? [];
$source_meta  = $parsed_data['source_meta'] ?? [];

// Detect pages
$detected_pages = $analysis['pages'] ?? [];

// Design tokens
$fonts  = $analysis['fonts']  ?? [];
$colors = $analysis['colors'] ?? [];

// Estimated output files
$estimated_files = $analysis['estimated_output_files'] ?? (count($detected_pages) * 3 + 8);

// WooCommerce flag
$has_woocommerce = !empty($conversion['has_woocommerce']);

// Price
$price_cents = (int) ($conversion['price_cents'] ?? ($has_woocommerce ? 1900 : 900));
$price_label = $has_woocommerce
    ? 'WooCommerce Conversion'
    : 'Basic Conversion';

// Theme name
$theme_name = (string) ($conversion['theme_name'] ?? 'wordpress-theme');

// Page icons — map common page names to SVG path data
function _preview_page_icon(string $page_name): string
{
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $page_name) ?? '');

    // Home / landing
    if (str_contains($slug, 'home') || str_contains($slug, 'index') || str_contains($slug, 'landing')) {
        return '<path d="M3 9.5L8 4l9.5 9H16v6H5v-6H3.5L3 9.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" fill="none"/>';
    }
    // Product / shop
    if (str_contains($slug, 'product') || str_contains($slug, 'shop') || str_contains($slug, 'store') || str_contains($slug, 'cart')) {
        return '<rect x="3" y="7" width="14" height="10" rx="1.5" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M7 7V5a3 3 0 016 0v2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" fill="none"/>';
    }
    // About
    if (str_contains($slug, 'about') || str_contains($slug, 'team')) {
        return '<circle cx="10" cy="7" r="3" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M4 18c0-3.31 2.69-6 6-6s6 2.69 6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" fill="none"/>';
    }
    // Blog / news / post
    if (str_contains($slug, 'blog') || str_contains($slug, 'news') || str_contains($slug, 'post') || str_contains($slug, 'article')) {
        return '<path d="M4 5h12M4 8h8M4 11h10M4 14h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" fill="none"/>';
    }
    // Contact
    if (str_contains($slug, 'contact')) {
        return '<rect x="3" y="5" width="14" height="10" rx="1.5" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M3 6l7 5 7-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" fill="none"/>';
    }
    // Auth / login
    if (str_contains($slug, 'login') || str_contains($slug, 'sign') || str_contains($slug, 'auth')) {
        return '<rect x="5" y="3" width="10" height="7" rx="1" stroke="currentColor" stroke-width="1.5" fill="none"/><rect x="3" y="10" width="14" height="8" rx="1" stroke="currentColor" stroke-width="1.5" fill="none"/>';
    }
    // Dashboard / admin
    if (str_contains($slug, 'dashboard') || str_contains($slug, 'admin') || str_contains($slug, 'portal')) {
        return '<rect x="3" y="3" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.5" fill="none"/><rect x="11" y="3" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.5" fill="none"/><rect x="3" y="11" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.5" fill="none"/><rect x="11" y="11" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.5" fill="none"/>';
    }
    // Default: generic file/page icon
    return '<path d="M6 3h6l4 4v11a1 1 0 01-1 1H5a1 1 0 01-1-1V4a1 1 0 011-1z" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M13 3v4h4" stroke="currentColor" stroke-width="1.5" fill="none"/>';
}

// Flash from cancelled payment
$payment_cancelled = isset($_GET['payment']) && $_GET['payment'] === 'cancelled';

// ─── Render ────────────────────────────────────────────────────────────────────

$page_title = 'Preview — ' . $theme_name;
$body_class = 'page-preview';
ob_start();
?>
<div class="page-content">
    <div class="container">

        <!-- Back to dashboard -->
        <div class="page-back">
            <a href="<?= base_url('/') ?>" class="link link--muted">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Back to dashboard
            </a>
        </div>

        <?php if ($payment_cancelled): ?>
        <div class="alert alert--warning" role="alert">
            <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <path d="M10 3L18 17H2L10 3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" fill="none"/>
                <path d="M10 9v4M10 14.5v.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            </svg>
            Payment was cancelled. You can try again whenever you're ready.
        </div>
        <?php endif; ?>

        <?php if ($status === 'failed'): ?>
        <!-- Error state -->
        <div class="alert alert--error" role="alert">
            <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/>
                <path d="M10 6v5M10 13v1" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            </svg>
            <strong>Analysis failed.</strong>
            <?php if (!empty($conversion['error_message'])): ?>
            <?= htmlspecialchars($conversion['error_message'], ENT_QUOTES, 'UTF-8') ?>
            <?php else: ?>
            We couldn't analyse your project zip. Please check it's a valid Base44 export, then
            <a href="<?= base_url('/upload') ?>">try uploading again</a>.
            <?php endif; ?>
            <br>
            <a href="mailto:<?= htmlspecialchars((string) config('contact_email', 'support@base44towordpress.com'), ENT_QUOTES, 'UTF-8') ?>?subject=Conversion+Failed+<?= urlencode($conversion['uuid']) ?>"
               class="link">Contact support</a>
        </div>

        <?php elseif ($status === 'pending'): ?>
        <!-- Still processing (shouldn't normally appear) -->
        <div class="alert alert--info" role="status" aria-live="polite">
            <span class="spinner spinner--sm" aria-hidden="true"></span>
            Your project is still being analysed. This page will refresh automatically.
        </div>
        <script>setTimeout(function(){ location.reload(); }, 5000);</script>

        <?php else: ?>
        <!-- Normal preview state: status === 'parsed' (or 'unpaid' after failed payment) -->

        <div class="preview-layout">

            <!-- Left: Analysis Details -->
            <div class="preview-analysis">

                <!-- Theme Heading -->
                <div class="preview-theme-header">
                    <h1 class="preview-theme-name">
                        <?= htmlspecialchars($theme_name, ENT_QUOTES, 'UTF-8') ?>
                    </h1>
                    <p class="preview-theme-sub">
                        WordPress theme slug &mdash; ready for conversion
                    </p>
                </div>

                <!-- WooCommerce Badge -->
                <?php if ($has_woocommerce): ?>
                <div class="preview-badge preview-badge--woo">
                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M6 10l3 3 5-6" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    WooCommerce detected
                </div>
                <?php endif; ?>

                <!-- Detected Pages -->
                <?php if (!empty($detected_pages)): ?>
                <div class="preview-section">
                    <h2 class="preview-section__title">
                        Detected Pages
                        <span class="preview-section__count"><?= count($detected_pages) ?></span>
                    </h2>
                    <ul class="preview-pages" role="list">
                        <?php foreach ($detected_pages as $page):
                            $page_name = is_array($page) ? ($page['name'] ?? (string) $page) : (string) $page;
                        ?>
                        <li class="preview-pages__item">
                            <span class="preview-pages__icon" aria-hidden="true">
                                <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                                    <?= _preview_page_icon($page_name) ?>
                                </svg>
                            </span>
                            <span class="preview-pages__name">
                                <?= htmlspecialchars($page_name, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Design Tokens -->
                <?php if (!empty($fonts) || !empty($colors)): ?>
                <div class="preview-section">
                    <h2 class="preview-section__title">Design Tokens</h2>

                    <?php if (!empty($fonts)): ?>
                    <div class="preview-tokens preview-tokens--fonts">
                        <h3 class="preview-tokens__label">Fonts</h3>
                        <ul class="preview-tokens__list" role="list">
                            <?php foreach (array_slice($fonts, 0, 6) as $font): ?>
                            <li class="preview-tokens__font">
                                <?= htmlspecialchars((string) $font, ENT_QUOTES, 'UTF-8') ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($colors)): ?>
                    <div class="preview-tokens preview-tokens--colors">
                        <h3 class="preview-tokens__label">Colors</h3>
                        <ul class="preview-tokens__swatches" role="list">
                            <?php foreach (array_slice($colors, 0, 12) as $color):
                                // Sanitize color value — allow only safe CSS color strings
                                $safe_color = preg_match('/^#[0-9a-f]{3,8}$|^rgb\([\d,\s]+\)$|^rgba\([\d,.\s]+\)$|^hsl\([\d,%\s]+\)$/i', (string) $color)
                                    ? (string) $color
                                    : 'transparent';
                            ?>
                            <li class="preview-tokens__swatch-item">
                                <span
                                    class="preview-tokens__swatch"
                                    style="background: <?= htmlspecialchars($safe_color, ENT_QUOTES, 'UTF-8') ?>"
                                    title="<?= htmlspecialchars($safe_color, ENT_QUOTES, 'UTF-8') ?>"
                                    aria-label="Color: <?= htmlspecialchars($safe_color, ENT_QUOTES, 'UTF-8') ?>"
                                    role="img"
                                ></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Stats row -->
                <div class="preview-stats">
                    <div class="preview-stat">
                        <span class="preview-stat__value"><?= count($detected_pages) ?: '—' ?></span>
                        <span class="preview-stat__label">Pages</span>
                    </div>
                    <div class="preview-stat">
                        <span class="preview-stat__value"><?= (int) ($source_meta['component_count'] ?? 0) ?></span>
                        <span class="preview-stat__label">Components</span>
                    </div>
                    <div class="preview-stat">
                        <span class="preview-stat__value">~<?= $estimated_files ?></span>
                        <span class="preview-stat__label">Output Files</span>
                    </div>
                    <?php if (!empty($source_meta['has_typescript'])): ?>
                    <div class="preview-stat">
                        <span class="preview-stat__value">TS</span>
                        <span class="preview-stat__label">TypeScript</span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($source_meta['has_tailwind'])): ?>
                    <div class="preview-stat">
                        <span class="preview-stat__value">TW</span>
                        <span class="preview-stat__label">Tailwind</span>
                    </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Right: Payment Card -->
            <aside class="preview-payment" aria-label="Payment options">

                <div class="payment-card card">

                    <div class="payment-card__header">
                        <div class="payment-card__type">
                            <?php if ($has_woocommerce): ?>
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <rect x="2" y="6" width="16" height="11" rx="2" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M6 6V4a4 4 0 018 0v2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            WooCommerce Conversion
                            <?php else: ?>
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <path d="M4 4h12a1 1 0 011 1v10a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M4 7h12" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            Basic Conversion
                            <?php endif; ?>
                        </div>
                        <div class="payment-card__price" aria-label="Price: <?= format_price($price_cents) ?>">
                            <?= format_price($price_cents) ?>
                        </div>
                    </div>

                    <ul class="payment-card__features" role="list">
                        <li>
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.25"/>
                                <path d="M5 8l2 2 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Full WordPress theme (.zip)
                        </li>
                        <li>
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.25"/>
                                <path d="M5 8l2 2 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <?= count($detected_pages) ?> page<?= count($detected_pages) !== 1 ? 's' : '' ?> converted
                        </li>
                        <?php if ($has_woocommerce): ?>
                        <li>
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.25"/>
                                <path d="M5 8l2 2 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            WooCommerce templates included
                        </li>
                        <?php endif; ?>
                        <li>
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.25"/>
                                <path d="M5 8l2 2 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Download available for <?= (int) config('download_expiry_days', 30) ?> days
                        </li>
                        <li>
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.25"/>
                                <path d="M5 8l2 2 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            One-time payment, no subscription
                        </li>
                    </ul>

                    <!-- Payment buttons -->
                    <div class="payment-card__buttons">

                        <!-- Stripe -->
                        <form method="POST" action="<?= base_url('/pay/' . $conversion['uuid']) ?>">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="provider" value="stripe">
                            <button
                                type="submit"
                                class="btn btn--primary btn--full payment-btn payment-btn--stripe"
                                aria-label="Pay <?= format_price($price_cents) ?> with Stripe"
                            >
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <rect x="2" y="5" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.5"/>
                                    <path d="M2 9h20" stroke="currentColor" stroke-width="1.5"/>
                                    <path d="M6 14h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                </svg>
                                Pay with Stripe &mdash; <?= format_price($price_cents) ?>
                            </button>
                        </form>

                        <div class="payment-card__divider" aria-hidden="true">or</div>

                        <!-- PayPal -->
                        <form method="POST" action="<?= base_url('/pay/' . $conversion['uuid']) ?>">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="provider" value="paypal">
                            <button
                                type="submit"
                                class="btn btn--secondary btn--full payment-btn payment-btn--paypal"
                                aria-label="Pay <?= format_price($price_cents) ?> with PayPal"
                            >
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M7 21h3l1-4h3c3 0 5-2 5-5s-2-5-5-5H8L5 21" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M11 12h2c2 0 3-1 3-3s-1-3-3-3H9L7 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Pay with PayPal &mdash; <?= format_price($price_cents) ?>
                            </button>
                        </form>

                    </div>

                    <p class="payment-card__note">
                        <svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                            <path d="M8 2a6 6 0 100 12A6 6 0 008 2z" stroke="currentColor" stroke-width="1.25"/>
                            <path d="M8 5v4M8 10v1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        Secure, encrypted payment. You will be redirected to the payment provider.
                    </p>

                </div>

                <!-- Source file info -->
                <div class="preview-source-info">
                    <p>
                        <strong>File:</strong>
                        <?= htmlspecialchars($conversion['original_filename'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <?php if (!empty($conversion['live_url'])): ?>
                    <p>
                        <strong>Live URL:</strong>
                        <a href="<?= htmlspecialchars($conversion['live_url'], ENT_QUOTES, 'UTF-8') ?>"
                           target="_blank" rel="noopener noreferrer" class="link">
                            <?= htmlspecialchars($conversion['live_url'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </p>
                    <?php endif; ?>
                    <p>
                        <strong>Analysed:</strong>
                        <time datetime="<?= htmlspecialchars($conversion['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(date('M j, Y \a\t g:ia', strtotime($conversion['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                        </time>
                    </p>
                </div>

            </aside>
        </div>

        <?php endif; // end status check ?>

    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
