<?php
/**
 * layout.php — Main HTML shell for all B442WP pages.
 *
 * Usage in page files:
 *   $page_title = 'My Page';
 *   $body_class = 'page-dashboard'; // optional
 *   ob_start();
 *   // ... page HTML ...
 *   $content = ob_get_clean();
 *   require __DIR__ . '/../templates/layout.php';
 *
 * Expected variables:
 *   string $content     — the buffered page HTML
 *   string $page_title  — used in <title>
 *   string $body_class  — optional extra class on <body>
 */

if (!isset($content))    { $content    = ''; }
if (!isset($page_title)) { $page_title = 'Base44→WP'; }
if (!isset($body_class)) { $body_class = ''; }

$app_name    = 'Base44→WP';
$canon_url   = base_url(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$full_title  = $page_title !== $app_name ? htmlspecialchars($page_title) . ' — ' . $app_name : $app_name;

// Collect flash messages
$flash_success = get_flash('success');
$flash_error   = get_flash('error');
$flash_info    = get_flash('info');
$flash_warning = get_flash('warning');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= $full_title ?></title>
    <meta name="description" content="Convert Base44 React apps into fully functional WordPress themes — fast, automated, pay per conversion.">
    <link rel="canonical" href="<?= htmlspecialchars($canon_url) ?>">
    <!-- Prevent indexing of account/app pages -->
    <?php if (is_logged_in()): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= base_url('/assets/css/style.css') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= base_url('/assets/images/favicon.svg') ?>">
</head>
<body class="<?= htmlspecialchars(trim('app-body ' . $body_class)) ?>">

    <?php require __DIR__ . '/header.php'; ?>

    <!-- Flash Messages -->
    <?php if ($flash_success || $flash_error || $flash_info || $flash_warning): ?>
    <div class="flash-container" role="alert" aria-live="polite">
        <?php if ($flash_success): ?>
        <div class="flash flash--success" role="status">
            <span class="flash__icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/>
                    <path d="M6.5 10.5l2.5 2.5 4.5-5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
            <span class="flash__message"><?= htmlspecialchars($flash_success['message']) ?></span>
            <button type="button" class="flash-dismiss" aria-label="Dismiss">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
        <?php endif; ?>
        <?php if ($flash_error): ?>
        <div class="flash flash--error" role="alert">
            <span class="flash__icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/>
                    <path d="M10 6v5M10 13v1" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                </svg>
            </span>
            <span class="flash__message"><?= htmlspecialchars($flash_error['message']) ?></span>
            <button type="button" class="flash-dismiss" aria-label="Dismiss">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
        <?php endif; ?>
        <?php if ($flash_info): ?>
        <div class="flash flash--info" role="status">
            <span class="flash__icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/>
                    <path d="M10 9v5M10 6v1" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                </svg>
            </span>
            <span class="flash__message"><?= htmlspecialchars($flash_info['message']) ?></span>
            <button type="button" class="flash-dismiss" aria-label="Dismiss">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
        <?php endif; ?>
        <?php if ($flash_warning): ?>
        <div class="flash flash--warning" role="status">
            <span class="flash__icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="M10 3L18 17H2L10 3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                    <path d="M10 9v4M10 14.5v.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                </svg>
            </span>
            <span class="flash__message"><?= htmlspecialchars($flash_warning['message']) ?></span>
            <button type="button" class="flash-dismiss" aria-label="Dismiss">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Page Content -->
    <main class="page-main" id="main-content" tabindex="-1">
        <?= $content ?>
    </main>

    <?php require __DIR__ . '/footer.php'; ?>

    <script src="<?= base_url('/assets/js/app.js') ?>"></script>
</body>
</html>
