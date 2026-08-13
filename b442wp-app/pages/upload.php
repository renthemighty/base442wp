<?php
/**
 * pages/upload.php — Upload a Base44 project zip for conversion.
 *
 * GET:  Show the upload form.
 * POST: Validate, store the file, run Parser + Analyzer, redirect to /preview/{uuid}.
 *
 * Status flow entry point: pending → parsed (on success).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/db.php';

// Must be authenticated
require_auth();

$user = current_user();

// ─── POST: Handle file upload ──────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. CSRF check
    csrf_verify();

    $errors = [];

    // 2. Validate uploaded file
    $upload = $_FILES['source_zip'] ?? null;

    if (
        $upload === null
        || !isset($upload['error'])
        || $upload['error'] === UPLOAD_ERR_NO_FILE
    ) {
        $errors[] = 'Please select a zip file to upload.';
    } elseif ($upload['error'] !== UPLOAD_ERR_OK) {
        $errors[] = match ($upload['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file is too large. Maximum size is 50 MB.',
            UPLOAD_ERR_PARTIAL   => 'The file was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error: no temporary upload directory.',
            UPLOAD_ERR_CANT_WRITE => 'Server error: could not write uploaded file to disk.',
            UPLOAD_ERR_EXTENSION  => 'File upload blocked by a server extension.',
            default               => 'File upload failed (error code ' . $upload['error'] . ').',
        };
    } else {
        // Check MIME and extension — must be a zip
        $original_filename = basename((string) ($upload['name'] ?? ''));
        $extension         = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));

        if ($extension !== 'zip') {
            $errors[] = 'Only .zip files are accepted. Please zip your Base44 project folder and try again.';
        }

        // Check file size against config (defence-in-depth; PHP INI may differ)
        $max_size = (int) config('max_upload_size', 50 * 1024 * 1024);
        if (($upload['size'] ?? 0) > $max_size) {
            $errors[] = 'The uploaded file exceeds the maximum allowed size of ' . round($max_size / 1024 / 1024) . ' MB.';
        }

        // Verify the tmp_name is actually an uploaded file (prevents local file inclusion)
        if (!is_uploaded_file($upload['tmp_name'] ?? '')) {
            $errors[] = 'Invalid upload. Please try again.';
        }
    }

    // 3. Validate live_url
    $live_url = trim((string) ($_POST['live_url'] ?? ''));

    if ($live_url === '') {
        $errors[] = 'Please enter the live URL of your Base44 app.';
    } elseif (!filter_var($live_url, FILTER_VALIDATE_URL)) {
        $errors[] = 'The live URL does not appear to be valid. Please include https://.';
    } elseif (!preg_match('#^https?://#i', $live_url)) {
        $errors[] = 'The live URL must begin with http:// or https://.';
    }

    // If validation failed, re-display the form with errors
    if (!empty($errors)) {
        goto render_form;
    }

    // 4. Generate UUID and move file to permanent storage
    $conversion_uuid = uuid();
    $upload_dir      = rtrim((string) config('upload_dir', __DIR__ . '/../storage/uploads'), '/');

    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0o750, true)) {
        $errors[] = 'Server storage directory could not be created. Please contact support.';
        goto render_form;
    }

    $stored_filename = $conversion_uuid . '.zip';
    $stored_path     = $upload_dir . '/' . $stored_filename;

    if (!move_uploaded_file($upload['tmp_name'], $stored_path)) {
        $errors[] = 'Failed to save the uploaded file. Please try again.';
        goto render_form;
    }

    // 5. Insert 'pending' conversion record
    try {
        $stmt = db()->prepare(
            'INSERT INTO conversions
                (uuid, user_id, original_filename, live_url, status, source_zip_path, created_at)
             VALUES
                (:uuid, :user_id, :original_filename, :live_url, :status, :source_zip_path, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            ':uuid'              => $conversion_uuid,
            ':user_id'           => (int) $user['id'],
            ':original_filename' => $original_filename,
            ':live_url'          => $live_url,
            ':status'            => 'pending',
            ':source_zip_path'   => $stored_path,
        ]);
        $conversion_id = (int) db()->lastInsertId();
    } catch (PDOException $e) {
        @unlink($stored_path);
        $errors[] = 'A database error occurred while creating your conversion. Please try again.';
        goto render_form;
    }

    // 6. Run LiveScanner (Stage 0), Parser, then Analyzer
    try {
        // Stage 0: Playwright live scan — ground-truth design data from the live URL.
        // Runs before Parser so the Analyzer can override zip-based extraction.
        // Returns null silently on any error (Base44 editor URLs, network issues, no Node).
        require_once __DIR__ . '/../converter/LiveScanner.php';

        $scan_dir  = APP_ROOT . '/storage/scans/' . $conversion_uuid;
        $scanner   = new LiveScanner();
        $live_scan = $scanner->scan($live_url, $scan_dir);

        require_once __DIR__ . '/../converter/Parser.php';

        $parser      = new Parser($stored_path);
        $source_data = $parser->parse();

        require_once __DIR__ . '/../converter/Analyzer.php';

        $analyzer     = new Analyzer();
        $analysis     = $analyzer->analyze($source_data, $live_scan);

        // Derive theme name and pricing from analysis
        $has_woocommerce = !empty($analysis['has_woocommerce']);
        $price_cents     = $has_woocommerce
            ? (int) config('price_woocommerce', 1900)
            : (int) config('price_basic', 900);

        $theme_name = $analysis['theme_name']
            ?? generate_theme_name($original_filename);

        // 7. Store analysis and update record to 'parsed'
        $parsed_json = json_encode([
            'analysis'    => $analysis,
            'source_meta' => [
                'has_typescript' => $source_data['has_typescript'],
                'has_tailwind'   => $source_data['has_tailwind'],
                'file_tree'      => $source_data['file_tree'],
                'raw_zip_size'   => $source_data['raw_zip_size'],
                'page_count'     => count($source_data['pages']),
                'component_count'=> count($source_data['components']),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $upd = db()->prepare(
            'UPDATE conversions
                SET status          = :status,
                    parsed_data     = :parsed_data,
                    theme_name      = :theme_name,
                    has_woocommerce = :has_woocommerce,
                    price_cents     = :price_cents
              WHERE id = :id'
        );
        $upd->execute([
            ':status'          => 'parsed',
            ':parsed_data'     => $parsed_json,
            ':theme_name'      => $theme_name,
            ':has_woocommerce' => (int) $has_woocommerce,
            ':price_cents'     => $price_cents,
            ':id'              => $conversion_id,
        ]);

    } catch (Throwable $e) {
        // Mark as failed so the user knows something went wrong
        try {
            $fail = db()->prepare(
                'UPDATE conversions SET status = :status, error_message = :msg WHERE id = :id'
            );
            $fail->execute([
                ':status' => 'failed',
                ':msg'    => substr($e->getMessage(), 0, 1000),
                ':id'     => $conversion_id,
            ]);
        } catch (PDOException) {
            // Best effort
        }

        $errors[] = 'We could not analyse your project zip. Please ensure it is a valid Base44 export. ('
            . htmlspecialchars(substr($e->getMessage(), 0, 200), ENT_QUOTES, 'UTF-8') . ')';
        goto render_form;
    }

    // 8. Redirect to preview
    redirect('/preview/' . $conversion_uuid);
}

// ─── GET: render form ──────────────────────────────────────────────────────────
// PHP's "goto" lands here on validation errors as well.
render_form:

$page_title = 'Upload Project';
$body_class = 'page-upload';

// Preserve live_url across validation errors
$prev_live_url = htmlspecialchars(trim((string) ($_POST['live_url'] ?? '')), ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="page-content">
    <div class="container container--narrow">

        <!-- Back link — top left -->
        <div class="page-back">
            <a href="<?= base_url('/') ?>" class="link--muted">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Back to dashboard
            </a>
        </div>

        <!-- Page Header -->
        <div class="page-header page-header--simple">
            <div class="page-header__text">
                <h1 class="page-header__title">Upload Your Base44 Project</h1>
                <p class="page-header__subtitle">
                    Upload your project zip and live URL. We'll analyse it for free — no payment yet.
                </p>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert--error" role="alert" aria-live="assertive">
            <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5"/>
                <path d="M10 6v5M10 13v1" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            </svg>
            <ul class="alert__list">
                <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="card">
            <form
                method="POST"
                action="<?= base_url('/upload') ?>"
                enctype="multipart/form-data"
                class="upload-form"
                id="upload-form"
                novalidate
            >
                <?php csrf_field(); ?>

                <!-- Drag-and-drop upload area -->
                <div class="form-group">
                    <div
                        class="upload-area"
                        data-upload-area
                        id="upload-area"
                        role="button"
                        tabindex="0"
                        aria-label="Click or drag a zip file here to upload"
                        aria-describedby="upload-hint"
                    >
                        <div class="upload-area__icon" aria-hidden="true">
                            <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
                                <circle cx="24" cy="24" r="23" fill="#eff6ff" stroke="#dbeafe" stroke-width="1.5"/>
                                <path d="M24 30V18M20 22l4-4 4 4" stroke="#3b82f6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M16 34h16" stroke="#93c5fd" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <p class="upload-area__primary">
                            <span class="upload-area__cta">Click to choose a file</span>
                            &nbsp;or drag and drop here
                        </p>
                        <p class="upload-area__secondary" id="upload-hint">
                            .zip files only &mdash; Max 50 MB
                        </p>
                        <p class="upload-area__filename" id="upload-filename" hidden aria-live="polite"></p>

                        <input
                            type="file"
                            name="source_zip"
                            id="source_zip"
                            accept=".zip,application/zip,application/x-zip-compressed"
                            class="upload-area__input"
                            aria-label="Choose zip file"
                        >
                    </div>
                </div>

                <!-- Live URL -->
                <div class="form-group">
                    <label class="form-label" for="live_url">
                        Live App URL
                        <span class="form-label__required" aria-hidden="true">*</span>
                    </label>
                    <input
                        type="url"
                        name="live_url"
                        id="live_url"
                        class="form-control"
                        placeholder="https://myapp.base44.app/"
                        value="<?= $prev_live_url ?>"
                        autocomplete="url"
                        spellcheck="false"
                        required
                        aria-describedby="live_url_hint"
                    >
                    <p class="form-hint" id="live_url_hint">
                        The public URL where your Base44 app is currently running.
                    </p>
                </div>

                <!-- How it works -->
                <div class="upload-explainer">
                    <h2 class="upload-explainer__title">How it works</h2>
                    <ol class="upload-explainer__steps">
                        <li>
                            <span class="upload-explainer__step-num" aria-hidden="true">1</span>
                            <span><strong>Upload</strong> your zip &amp; URL — free</span>
                        </li>
                        <li>
                            <span class="upload-explainer__step-num" aria-hidden="true">2</span>
                            <span><strong>Preview</strong> pages &amp; design tokens</span>
                        </li>
                        <li>
                            <span class="upload-explainer__step-num" aria-hidden="true">3</span>
                            <span><strong>Pay once</strong> — Basic <?= format_price((int) config('price_basic', 900)) ?> or WooCommerce <?= format_price((int) config('price_woocommerce', 1900)) ?></span>
                        </li>
                        <li>
                            <span class="upload-explainer__step-num" aria-hidden="true">4</span>
                            <span><strong>Download</strong> your WordPress theme zip</span>
                        </li>
                    </ol>
                </div>

                <!-- Submit -->
                <div class="form-actions">
                    <button
                        type="submit"
                        class="btn btn--primary btn--full upload-submit-btn"
                        id="upload-submit-btn"
                    >
                        Analyse for Free &rarr;
                    </button>
                    <p class="form-actions__note">
                        No payment required at this step.
                    </p>
                </div>

            </form>
        </div>

    </div>
</div>

<script>
(function () {
    'use strict';

    const area     = document.getElementById('upload-area');
    const input    = area ? area.querySelector('input[type="file"]') : null;
    const label    = document.getElementById('upload-filename');
    const hint     = document.getElementById('upload-hint');
    const form     = document.getElementById('upload-form');
    const submitBtn = document.getElementById('upload-submit-btn');

    if (!area || !input) return;

    function setFileLabel(file) {
        if (!label) return;
        if (file) {
            label.textContent = file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
            label.hidden = false;
            if (hint) hint.hidden = true;
            area.classList.add('upload-area--has-file');
        } else {
            label.hidden = true;
            if (hint) hint.hidden = false;
            area.classList.remove('upload-area--has-file');
        }
    }

    // Click anywhere in the area to trigger the file picker
    area.addEventListener('click', function (e) {
        if (e.target !== input) input.click();
    });

    // Keyboard accessibility
    area.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            input.click();
        }
    });

    // File selected via picker
    input.addEventListener('change', function () {
        setFileLabel(input.files[0] || null);
    });

    // Drag-and-drop
    ['dragenter', 'dragover'].forEach(function (ev) {
        area.addEventListener(ev, function (e) {
            e.preventDefault();
            area.classList.add('upload-area--drag-over');
        });
    });

    ['dragleave', 'drop'].forEach(function (ev) {
        area.addEventListener(ev, function (e) {
            e.preventDefault();
            area.classList.remove('upload-area--drag-over');
        });
    });

    area.addEventListener('drop', function (e) {
        e.preventDefault();
        const file = e.dataTransfer && e.dataTransfer.files[0];
        if (!file) return;

        // Validate extension client-side before handing to input
        if (!file.name.toLowerCase().endsWith('.zip')) {
            alert('Only .zip files are accepted.');
            return;
        }

        try {
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            setFileLabel(file);
        } catch (_) {
            // DataTransfer assignment not supported in all browsers — fallback silently
        }
    });

    // Disable submit button while uploading to prevent double-submit
    if (form && submitBtn) {
        form.addEventListener('submit', function () {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Uploading\u2026';
        });
    }
}());
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
