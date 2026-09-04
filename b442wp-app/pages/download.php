<?php
/**
 * pages/download.php — Serve the completed WordPress theme zip file.
 *
 * GET /download/{uuid}
 *
 * Authenticated. Shows a download page and serves the file on demand.
 *
 * Query parameters:
 *   ?action=download  — Serve the actual binary file (Content-Disposition: attachment).
 *                       Without this param, the page renders the UI so the user
 *                       can review details before clicking the download button.
 *
 * Status checks:
 *   - Not 'complete'      → redirect appropriately or show error.
 *   - expires_at < NOW    → show "Download expired" message.
 *   - output_zip_path missing / inaccessible → show error.
 *   - All good            → serve file (if ?action=download) or render download page.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/middleware.php';

// Must be authenticated
require_auth();

$uuid = defined('ROUTE_UUID') ? ROUTE_UUID : ($_REQUEST['uuid'] ?? '');

if (empty($uuid)) {
    flash('error', 'Invalid download link.', 'error');
    redirect('/');
}

// Load conversion — must belong to current user
$user = current_user();
$stmt = db()->prepare('SELECT * FROM conversions WHERE uuid = ? AND user_id = ? LIMIT 1');
$stmt->execute([$uuid, $user['id']]);
$conversion = $stmt->fetch();

if (!$conversion) {
    flash('error', 'Conversion not found.', 'error');
    redirect('/');
}

$status = (string) ($conversion['status'] ?? '');

// ─── Redirect for in-progress statuses ────────────────────────────────────────

if (in_array($status, ['paid', 'converting'], true)) {
    redirect('/convert/' . $uuid);
}

if (in_array($status, ['parsed', 'unpaid'], true)) {
    redirect('/preview/' . $uuid);
}

// ─── Expiry calculations ───────────────────────────────────────────────────────

$expires_at_str = (string) ($conversion['expires_at'] ?? '');
$expires_ts     = $expires_at_str !== '' ? strtotime($expires_at_str) : false;
$is_expired_dl  = ($expires_ts !== false && time() > $expires_ts);
$days_left      = ($expires_ts !== false && !$is_expired_dl)
    ? (int) ceil(($expires_ts - time()) / 86400)
    : null;

// ─── Install-zip unwrap (v3.3.1) ──────────────────────────────────────────────
// output_zip_path stores the OUTER bundle zip (theme/<slug>-theme.zip +
// data/ WXR exports; pre-v4.3.1 builds also carried the unpacked theme tree
// at theme/<slug>-theme/). Uploading that wrapper to WordPress fails with
// "The theme is missing the style.css stylesheet" because WP's installer
// only looks for style.css at the zip root or one directory deep — style.css
// sits at depth 2 (or inside a nested zip). Confirmed live on job #147
// (customer complaint, 2026-08-11). Default download now serves the INNER
// installable theme zip, extracted once and cached alongside the outer file;
// ?file=bundle serves the original full bundle for the data/WXR exports.

/**
 * Extract the inner installable theme zip (theme/<slug>-theme.zip) out of an
 * outer bundle zip, cache it next to the outer file, and return its path.
 * Returns '' when no inner zip exists or extraction fails — callers then
 * serve the outer file unchanged (never break the download).
 */
function b442_materialize_install_zip(string $outer_zip): string
{
    $cache = $outer_zip . '.install.zip';
    if (is_file($cache) && filesize($cache) > 0 && filemtime($cache) >= filemtime($outer_zip)) {
        return $cache;
    }
    if (!class_exists('ZipArchive')) {
        return '';
    }
    $za = new ZipArchive();
    if ($za->open($outer_zip) !== true) {
        return '';
    }
    $entry = '';
    for ($i = 0; $i < $za->numFiles; $i++) {
        $name = (string) $za->getNameIndex($i);
        if (preg_match('#^theme/[^/]+\.zip$#', $name)) {
            $entry = $name;
            break;
        }
    }
    if ($entry === '') {
        $za->close();
        return '';
    }
    $stream = $za->getStream($entry);
    if ($stream === false) {
        $za->close();
        return '';
    }
    $tmp = $cache . '.tmp';
    $out = @fopen($tmp, 'wb');
    if ($out === false) {
        fclose($stream);
        $za->close();
        return '';
    }
    stream_copy_to_stream($stream, $out);
    fclose($out);
    fclose($stream);
    $za->close();
    if (!is_file($tmp) || filesize($tmp) === 0) {
        @unlink($tmp);
        return '';
    }
    @rename($tmp, $cache);
    return (is_file($cache) && filesize($cache) > 0) ? $cache : '';
}

// ─── Direct download trigger (?action=download) ───────────────────────────────

if (isset($_GET['action']) && $_GET['action'] === 'download') {

    if ($status !== 'complete') {
        flash('error', 'Your theme is not ready for download yet.', 'error');
        redirect('/download/' . $uuid);
    }

    if ($is_expired_dl) {
        flash('error', 'This download link has expired (' . (int) config('download_expiry_days', 30) . '-day limit). Please start a new conversion.', 'error');
        redirect('/download/' . $uuid);
    }

    $zip_path = (string) ($conversion['output_zip_path'] ?? '');

    if ($zip_path === '' || !file_exists($zip_path)) {
        flash('error', 'Theme file not found on the server. Please contact support.', 'error');
        redirect('/download/' . $uuid);
    }

    // Security: ensure the file lives inside the storage/ directory
    $real_zip     = realpath($zip_path);
    $real_storage = realpath(dirname(__DIR__) . '/storage');

    if (
        $real_zip === false
        || $real_storage === false
        || strncmp($real_zip, $real_storage . DIRECTORY_SEPARATOR, strlen($real_storage) + 1) !== 0
    ) {
        error_log('[B442WP] Download path traversal attempt for conversion ' . $uuid);
        flash('error', 'Security error: invalid file path. Please contact support.', 'error');
        redirect('/download/' . $uuid);
    }

    $theme_name     = (string) ($conversion['theme_name'] ?? 'wordpress-theme');
    $safe_slug      = preg_replace('/[^a-z0-9\-_]/i', '-', $theme_name) ?? 'wordpress-theme';
    $download_name  = strtolower($safe_slug) . '-wordpress-theme.zip';

    // v3.3.1: default = the installable inner theme zip; ?file=bundle = the
    // original full bundle (theme zip + data/ WXR + product CSV exports).
    $variant = (string) ($_GET['file'] ?? 'install');
    if ($variant === 'bundle') {
        $download_name = strtolower($safe_slug) . '-full-bundle.zip';
    } else {
        $install_zip = b442_materialize_install_zip($real_zip);
        if ($install_zip !== '') {
            $real_zip = $install_zip;
        } else {
            // No inner zip found (unknown/legacy layout) — serve the stored
            // file unchanged rather than failing the download.
            error_log('[B442WP] Install unwrap: no inner theme zip found in ' . $real_zip . ' — serving stored file as-is');
        }
    }

    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $download_name . '"');
    header('Content-Length: ' . filesize($real_zip));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    readfile($real_zip);
    exit;
}

// ─── Render the download page ──────────────────────────────────────────────────

$theme_name      = (string) ($conversion['theme_name'] ?? 'Your Theme');
$has_woocommerce = !empty($conversion['has_woocommerce']);
$completed_at    = (string) ($conversion['completed_at'] ?? $conversion['created_at'] ?? '');
$price_cents     = (int) ($conversion['price_cents'] ?? 0);
$download_url    = base_url('/download/' . $uuid . '?action=download');
$bundle_url      = base_url('/download/' . $uuid . '?action=download&file=bundle');

// Expiry warning levels
$expiry_warn  = null;
$expiry_class = '';
if ($days_left !== null) {
    if ($days_left < 3) {
        $expiry_class = 'alert--error';
        $expiry_warn  = 'Only ' . $days_left . ' day' . ($days_left !== 1 ? 's' : '') . ' left to download!';
    } elseif ($days_left < 7) {
        $expiry_class = 'alert--warning';
        $expiry_warn  = 'Download expires in ' . $days_left . ' days.';
    }
}

$page_title = 'Download — ' . $theme_name;
$body_class = 'page-download';

ob_start();
?>
<div class="page-content">
    <div class="container container--narrow">

        <!-- Back to dashboard -->
        <div class="page-back">
            <a href="<?= base_url('/') ?>" class="link link--muted">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Back to dashboard
            </a>
        </div>

        <?php if ($status !== 'complete'): ?>
        <!-- Not complete -->
        <div class="card download-error" style="text-align:center;padding:2.5rem 2rem;">
            <?php if ($status === 'failed'): ?>
            <div class="download-error__icon" aria-hidden="true">
                <svg width="56" height="56" viewBox="0 0 56 56" fill="none">
                    <circle cx="28" cy="28" r="27" stroke="#fecaca" stroke-width="2" fill="#fff1f2"/>
                    <path d="M20 20l16 16M36 20L20 36" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round"/>
                </svg>
            </div>
            <h1 class="download-error__title">Conversion Failed</h1>
            <p class="download-error__text">
                <?php if (!empty($conversion['error_message'])): ?>
                <?= htmlspecialchars(substr($conversion['error_message'], 0, 400), ENT_QUOTES, 'UTF-8') ?>
                <?php else: ?>
                An unexpected error occurred during your conversion.
                <?php endif; ?>
            </p>
            <a href="<?= htmlspecialchars(support_ticket_url('My conversion failed. Conversion reference: ' . $uuid), ENT_QUOTES, 'UTF-8') ?>"
               target="_blank" rel="noopener"
               class="btn btn--primary">
                Contact Support
            </a>
            <?php elseif ($status === 'expired'): ?>
            <h1 class="download-error__title">Download Expired</h1>
            <p class="download-error__text">
                This download expired on <?= htmlspecialchars(date('F j, Y', (int) $expires_ts), ENT_QUOTES, 'UTF-8') ?>.
            </p>
            <a href="<?= base_url('/upload') ?>" class="btn btn--primary">Start New Conversion</a>
            <?php else: ?>
            <h1 class="download-error__title">Theme Not Ready</h1>
            <p class="download-error__text">This conversion is not complete yet.</p>
            <a href="<?= base_url('/') ?>" class="btn btn--secondary">Back to Dashboard</a>
            <?php endif; ?>
        </div>

        <?php elseif ($is_expired_dl): ?>
        <!-- Expired download -->
        <div class="card download-error" style="text-align:center;padding:2.5rem 2rem;">
            <div class="download-error__icon" aria-hidden="true">
                <svg width="56" height="56" viewBox="0 0 56 56" fill="none">
                    <circle cx="28" cy="28" r="27" stroke="#e2e8f0" stroke-width="2"/>
                    <path d="M28 18v12l6 6" stroke="#94a3b8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <h1 class="download-error__title">Download Window Closed</h1>
            <p class="download-error__text">
                This download expired on <strong><?= $expires_ts ? htmlspecialchars(date('F j, Y', (int) $expires_ts), ENT_QUOTES, 'UTF-8') : 'an earlier date' ?></strong>.
                Downloads are available for <?= (int) config('download_expiry_days', 30) ?> days after conversion.
            </p>
            <a href="<?= base_url('/upload') ?>" class="btn btn--primary" style="margin-top:1rem;">
                Convert a New Project
            </a>
        </div>

        <?php else: ?>
        <!-- Happy path: theme is ready to download -->

        <!-- Expiry warnings -->
        <?php if ($expiry_warn !== null): ?>
        <div class="alert <?= $expiry_class ?>" role="alert">
            <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <?php if ($expiry_class === 'alert--error'): ?>
                <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/>
                <path d="M10 6v5M10 13v1" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                <?php else: ?>
                <path d="M10 3L18 17H2L10 3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" fill="none"/>
                <path d="M10 9v4M10 14.5v.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                <?php endif; ?>
            </svg>
            <strong>Download expires soon:</strong> <?= htmlspecialchars($expiry_warn, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <!-- Ready card -->
        <div class="card download-ready">
            <div class="download-ready__icon" aria-hidden="true">
                <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                    <circle cx="36" cy="36" r="35" stroke="#bbf7d0" stroke-width="2" fill="#f0fdf4"/>
                    <circle cx="36" cy="36" r="28" stroke="#86efac" stroke-width="1.5" fill="none"/>
                    <path d="M24 36l9 9 15-16" stroke="#16a34a" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>

            <h1 class="download-ready__title">Your WordPress Theme is Ready!</h1>

            <div class="download-ready__meta">
                <dl class="meta-list">
                    <div class="meta-list__item">
                        <dt>Theme name</dt>
                        <dd><code><?= htmlspecialchars($theme_name, ENT_QUOTES, 'UTF-8') ?></code></dd>
                    </div>
                    <div class="meta-list__item">
                        <dt>Type</dt>
                        <dd><?= $has_woocommerce ? 'WooCommerce Conversion' : 'Basic Conversion' ?></dd>
                    </div>
                    <?php if ($price_cents > 0): ?>
                    <div class="meta-list__item">
                        <dt>Price paid</dt>
                        <dd><?= format_price($price_cents) ?></dd>
                    </div>
                    <?php endif; ?>
                    <?php if ($completed_at): ?>
                    <div class="meta-list__item">
                        <dt>Converted</dt>
                        <dd>
                            <time datetime="<?= htmlspecialchars($completed_at, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars(date('F j, Y \a\t g:ia', strtotime($completed_at)), ENT_QUOTES, 'UTF-8') ?>
                            </time>
                        </dd>
                    </div>
                    <?php endif; ?>
                    <?php if ($expires_ts): ?>
                    <div class="meta-list__item">
                        <dt>Download expires</dt>
                        <dd class="<?= $days_left !== null && $days_left < 3 ? 'text-danger' : ($days_left !== null && $days_left < 7 ? 'text-warning' : '') ?>">
                            <time datetime="<?= htmlspecialchars($expires_at_str, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars(date('F j, Y', (int) $expires_ts), ENT_QUOTES, 'UTF-8') ?>
                            </time>
                            <?php if ($days_left !== null): ?>
                            <span class="meta-list__days-left">(<?= $days_left ?> day<?= $days_left !== 1 ? 's' : '' ?> left)</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($conversion['original_filename'])): ?>
                    <div class="meta-list__item">
                        <dt>Source file</dt>
                        <dd><?= htmlspecialchars($conversion['original_filename'], ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($conversion['live_url'])): ?>
                    <div class="meta-list__item">
                        <dt>Live URL</dt>
                        <dd>
                            <a href="<?= htmlspecialchars($conversion['live_url'], ENT_QUOTES, 'UTF-8') ?>"
                               target="_blank" rel="noopener noreferrer" class="link">
                                <?= htmlspecialchars($conversion['live_url'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </dd>
                    </div>
                    <?php endif; ?>
                </dl>
            </div>

            <!-- Big download button -->
            <a
                href="<?= htmlspecialchars($download_url, ENT_QUOTES, 'UTF-8') ?>"
                class="btn btn--primary btn--lg btn--download"
            >
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="M10 3v10M6 9l4 4 4-4" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M4 16h12" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                </svg>
                Download Theme Zip
            </a>

            <?php if ($expires_ts): ?>
            <p class="download-ready__expiry" data-expires-at="<?= htmlspecialchars($expires_at_str, ENT_QUOTES, 'UTF-8') ?>">
                Available until <?= htmlspecialchars(date('F j, Y', (int) $expires_ts), ENT_QUOTES, 'UTF-8') ?>
            </p>
            <?php endif; ?>

        </div>

        <!-- Satisfaction / refund notice -->
        <div class="card feedback-notice" style="text-align:center;padding:1.75rem 2rem;background:#f8fafc;border:1.5px solid #e2e8f0;">
            <p style="margin:0 0 0.5rem;font-size:1rem;color:#0f172a;font-weight:600;line-height:1.5;">
                If this thing isn't what you expected, I want to know.
            </p>
            <p style="margin:0 0 1rem;font-size:0.9375rem;color:#334155;line-height:1.7;">
                <a href="<?= htmlspecialchars(support_ticket_url('Refund request. Order or conversion reference: ' . substr($uuid, 0, 8)), ENT_QUOTES, 'UTF-8') ?>"
                   target="_blank" rel="noopener"
                   class="link" style="color:#2563eb;font-weight:500;">Send me a ticket</a>
                and I'll give you your money back. No questions, no forms, just done.
            </p>
            <p style="margin:0;font-size:0.875rem;color:#64748b;line-height:1.6;">
                Found a bug or something looks off?
                <a href="<?= htmlspecialchars(support_ticket_url('Bug report. What went wrong: ' . substr($uuid, 0, 8)), ENT_QUOTES, 'UTF-8') ?>"
                   target="_blank" rel="noopener"
                   class="link" style="color:#2563eb;">Tell me that too</a>, I actually want to fix it.
            </p>
        </div>

        <!-- Installation instructions -->
        <div class="card install-guide">
            <h2 class="install-guide__title">Installation Instructions</h2>
            <ol class="install-guide__steps">
                <li class="install-guide__step">
                    <span class="install-guide__num" aria-hidden="true">1</span>
                    <div>
                        <strong>Upload via WordPress admin</strong><br>
                        Go to <em>Appearance &rarr; Themes &rarr; Add New &rarr; Upload Theme</em>.
                        Select the downloaded <code>.zip</code> file and click <em>Install Now</em>.
                    </div>
                </li>
                <li class="install-guide__step">
                    <span class="install-guide__num" aria-hidden="true">2</span>
                    <div>
                        <strong>Activate the theme</strong><br>
                        After installation click <em>Activate</em>, or go to
                        <em>Appearance &rarr; Themes</em> and activate it from there.
                    </div>
                </li>
                <?php if ($has_woocommerce): ?>
                <li class="install-guide__step">
                    <span class="install-guide__num" aria-hidden="true">3</span>
                    <div>
                        <strong>Install WooCommerce</strong><br>
                        Go to <em>Plugins &rarr; Add New</em>, search for <em>WooCommerce</em>,
                        install and activate it before importing content.
                    </div>
                </li>
                <li class="install-guide__step">
                    <span class="install-guide__num" aria-hidden="true">4</span>
                    <div>
                        <strong>Import demo content</strong><br>
                        Go to <em>Tools &rarr; Import &rarr; WordPress</em> and upload
                        <code>inc/content-import.xml</code> from inside the theme folder
                        (also available in the
                        <a href="<?= htmlspecialchars($bundle_url, ENT_QUOTES, 'UTF-8') ?>" class="link">full export bundle</a>
                        together with a WooCommerce product CSV).
                    </div>
                </li>
                <li class="install-guide__step">
                    <span class="install-guide__num" aria-hidden="true">5</span>
                    <div>
                        <strong>Customise</strong><br>
                        Use <em>Appearance &rarr; Customize</em> to adjust colours, fonts,
                        logos, and navigation menus.
                    </div>
                </li>
                <?php else: ?>
                <li class="install-guide__step">
                    <span class="install-guide__num" aria-hidden="true">3</span>
                    <div>
                        <strong>Import demo content</strong><br>
                        Go to <em>Tools &rarr; Import &rarr; WordPress</em> and upload
                        <code>inc/content-import.xml</code> from inside the theme folder
                        (also available in the
                        <a href="<?= htmlspecialchars($bundle_url, ENT_QUOTES, 'UTF-8') ?>" class="link">full export bundle</a>).
                    </div>
                </li>
                <li class="install-guide__step">
                    <span class="install-guide__num" aria-hidden="true">4</span>
                    <div>
                        <strong>Customise</strong><br>
                        Use <em>Appearance &rarr; Customize</em> to adjust colours, fonts,
                        and menus.
                    </div>
                </li>
                <?php endif; ?>
            </ol>

            <p class="install-guide__support">
                Need help installing?
                <a href="<?= htmlspecialchars(support_ticket_url('Install help. Where I am stuck: ' . substr($uuid, 0, 8)), ENT_QUOTES, 'UTF-8') ?>"
                   target="_blank" rel="noopener"
                   class="link">Contact support</a>.
            </p>
        </div>

        <?php endif; // happy path ?>

    </div>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/templates/layout.php';
