<?php
/**
 * pages/convert.php — Handle payment return and drive the conversion pipeline.
 *
 * GET /convert/{uuid}
 *
 * Authenticated. Handles two cases:
 *
 *   Case A — Payment return (?payment=success in query string):
 *     - Verify the payment with the provider (Stripe session check or PayPal capture).
 *     - Mark conversion as paid in DB.
 *     - Run the conversion pipeline synchronously (set_time_limit(0)).
 *     - On completion, redirect to /download/{uuid}.
 *
 *   Case B — Status poll (no payment param, or conversion already converting):
 *     - Show a progress page with a JS poller that hits /api/convert-status/{uuid}.
 *     - On complete, JS redirects to /download/{uuid}.
 *     - On failed, shows error message.
 *
 * The convert-status API endpoint is served by pages/api-convert-status.php
 * (registered separately in the router).
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/stripe.php';
require_once __DIR__ . '/../includes/paypal.php';

// Must be authenticated
require_auth();

// Load and ownership-check the conversion
$conversion = verify_conversion_owner($_REQUEST['uuid'] ?? '');
$status     = $conversion['status'] ?? '';

// ─── Convenience: already done? ────────────────────────────────────────────────

if ($status === 'complete') {
    redirect('/download/' . $conversion['uuid']);
}

if ($status === 'failed') {
    // Fall through to render the error page below
}

// ─── Case A: Payment return ────────────────────────────────────────────────────

$payment_error = null;

if (isset($_GET['payment']) && $_GET['payment'] === 'success') {

    // Only try to verify if we're not already past 'parsed'
    if (in_array($status, ['parsed', 'unpaid', 'paid'], true)) {

        $provider = (string) ($conversion['payment_provider'] ?? '');

        $payment_verified = false;
        $session_id       = '';

        if ($provider === 'stripe') {
            $session_id = trim((string) ($_GET['session_id'] ?? ''));

            if ($session_id === '') {
                $payment_error = 'Missing Stripe session ID. Please contact support.';
            } else {
                $session = verify_stripe_session($session_id);
                if ($session !== null) {
                    $payment_verified = true;
                } else {
                    $payment_error = 'We could not confirm your Stripe payment. '
                        . 'If you were charged, please contact support with reference: '
                        . htmlspecialchars($session_id, ENT_QUOTES, 'UTF-8');
                }
            }

        } elseif ($provider === 'paypal') {
            // PayPal return URL includes the order token as the payment_id
            $order_id = trim((string) ($conversion['payment_id'] ?? ''));

            // Also check query string token as a fallback
            if ($order_id === '') {
                $order_id = trim((string) ($_GET['token'] ?? ''));
            }

            if ($order_id === '') {
                $payment_error = 'Missing PayPal order ID. Please contact support.';
            } else {
                $captured = capture_paypal_order($order_id);
                if ($captured) {
                    $payment_verified = true;
                    $session_id       = $order_id;
                } else {
                    $payment_error = 'We could not capture your PayPal payment. '
                        . 'If you were charged, please contact support with reference: '
                        . htmlspecialchars($order_id, ENT_QUOTES, 'UTF-8');
                }
            }

        } else {
            // Fallback: maybe the webhook already set status to 'paid'
            if ($status === 'paid') {
                $payment_verified = true;
            } else {
                $payment_error = 'Unknown payment provider. Please contact support.';
            }
        }

        // ── Mark as paid and run conversion ───────────────────────────────────

        if ($payment_verified) {
            // Persist payment metadata (idempotent — safe to run even if already paid)
            try {
                $upd = db()->prepare(
                    'UPDATE conversions
                        SET payment_status  = :pay_status,
                            payment_id      = COALESCE(NULLIF(:payment_id, \'\'), payment_id)
                      WHERE id = :id'
                );
                $upd->execute([
                    ':pay_status' => 'paid',
                    ':payment_id' => $session_id,
                    ':id'         => (int) $conversion['id'],
                ]);
            } catch (PDOException $e) {
                error_log('[B442WP] Failed to update payment status for ' . $conversion['uuid'] . ': ' . $e->getMessage());
            }

            // Atomically claim the converting slot — only one concurrent request wins.
            // The WHERE guard prevents double-conversion if webhook and browser return race.
            try {
                $claim = db()->prepare(
                    'UPDATE conversions
                        SET status = \'converting\'
                      WHERE id     = :id
                        AND status NOT IN (\'converting\', \'complete\')'
                );
                $claim->execute([':id' => (int) $conversion['id']]);
            } catch (PDOException $e) {
                error_log('[B442WP] Failed to claim converting slot for ' . $conversion['uuid'] . ': ' . $e->getMessage());
                $claim = null;
            }

            if (!$claim || $claim->rowCount() === 0) {
                // Another request already claimed converting (or conversion is complete) — nothing to do.
                $status = $conversion['status'];
            } else {
                $conversion['status'] = 'converting';
                $status = 'converting';
            }

            // ── Fire the async worker and fall through to the progress page ──────
            if ($status === 'converting') {
                fire_conversion_worker($conversion['uuid']);
                // Worker runs detached; JS poller on the progress page will redirect
                // to /download/{uuid} once status becomes 'complete'.
            }

        // If already 'paid' (webhook beat us here), claim and fire worker now.
        if ($payment_verified && ($conversion['status'] ?? '') === 'paid') {
            try {
                $conv_upd = db()->prepare(
                    "UPDATE conversions SET status = 'converting' WHERE id = :id AND status = 'paid'"
                );
                $conv_upd->execute([':id' => (int) $conversion['id']]);

                if ($conv_upd->rowCount() > 0) {
                    $conversion['status'] = 'converting';
                    fire_conversion_worker($conversion['uuid']);
                }
            } catch (PDOException $e) {
                error_log('[B442WP] Could not claim converting slot (webhook path) for ' . $conversion['uuid'] . ': ' . $e->getMessage());
            }
        }

        } // end if ($payment_verified)

    } elseif ($status === 'converting') {
        // Already running — fall through to the progress page
    } elseif ($status === 'complete') {
        redirect('/download/' . $conversion['uuid']);
    }
}

// ─── Async conversion launcher ────────────────────────────────────────────────

/**
 * Fire a non-blocking POST to the conversion worker and return immediately.
 *
 * The worker closes its own HTTP connection straight away (Connection: close +
 * Content-Length: 0 + flush) so it runs detached from LiteSpeed's per-request
 * timeout even after this caller's response is sent.
 *
 * CURLOPT_TIMEOUT=5 lets us verify the worker accepted the request (HTTP 200)
 * without waiting for the pipeline to complete.
 *
 * @param  string $uuid  Conversion UUID.
 * @return bool          true if worker responded 200, false on network/auth error.
 */
function fire_conversion_worker(string $uuid): bool
{
    $token    = hash_hmac('sha256', $uuid, (string) config('claude_api_key'));
    $worker_url = rtrim((string) config('site_url', ''), '/') . '/api/convert-worker';

    $ch = curl_init($worker_url);
    if ($ch === false) {
        error_log('[B442WP] fire_conversion_worker: curl_init failed for ' . $uuid);
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['uuid' => $uuid, 'token' => $token]),
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err !== '') {
        error_log('[B442WP] fire_conversion_worker curl error for ' . $uuid . ': ' . $curl_err);
    }

    return $http_code === 200;
}

// ─── Refresh status for the progress page ─────────────────────────────────────

// Reload the record in case status changed during the payment flow
try {
    $stmt = db()->prepare('SELECT * FROM conversions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $conversion['id']]);
    $fresh = $stmt->fetch();
    if ($fresh) {
        $conversion = $fresh;
        $status     = $conversion['status'] ?? '';
    }
} catch (PDOException) {
    // Use the existing $conversion
}

// ─── Render the progress page ──────────────────────────────────────────────────

$page_title = 'Converting — ' . ($conversion['theme_name'] ?? '');
$body_class = 'page-convert';
ob_start();
?>
<div class="page-content">
    <div class="container container--narrow">

        <?php if ($status === 'failed'): ?>
        <!-- Conversion failed -->
        <div class="convert-error card">
            <div class="convert-error__icon" aria-hidden="true">
                <svg width="56" height="56" viewBox="0 0 56 56" fill="none">
                    <circle cx="28" cy="28" r="27" stroke="#fecaca" stroke-width="2" fill="#fff1f2"/>
                    <path d="M20 20l16 16M36 20L20 36" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round"/>
                </svg>
            </div>
            <h1 class="convert-error__title">Conversion Failed</h1>
            <p class="convert-error__message">
                <?php if (!empty($conversion['error_message'])): ?>
                    <?= htmlspecialchars(substr($conversion['error_message'], 0, 400), ENT_QUOTES, 'UTF-8') ?>
                <?php else: ?>
                    An unexpected error occurred during your conversion.
                <?php endif; ?>
            </p>
            <?php if ($payment_error !== null): ?>
            <p class="convert-error__payment-note">
                <?= htmlspecialchars($payment_error, ENT_QUOTES, 'UTF-8') ?>
            </p>
            <?php endif; ?>
            <div class="convert-error__actions">
                <a
                    href="mailto:<?= htmlspecialchars((string) config('contact_email', 'support@base44towordpress.com'), ENT_QUOTES, 'UTF-8') ?>?subject=Conversion+Failed+<?= urlencode($conversion['uuid']) ?>"
                    class="btn btn--primary"
                >
                    Contact Support
                </a>
                <a href="<?= base_url('/') ?>" class="btn btn--secondary">
                    Back to Dashboard
                </a>
            </div>
        </div>

        <?php elseif ($payment_error !== null): ?>
        <!-- Payment verification failed -->
        <div class="convert-error card">
            <div class="convert-error__icon" aria-hidden="true">
                <svg width="56" height="56" viewBox="0 0 56 56" fill="none">
                    <circle cx="28" cy="28" r="27" stroke="#fde68a" stroke-width="2" fill="#fffbeb"/>
                    <path d="M28 18v12M28 33v3" stroke="#d97706" stroke-width="2.5" stroke-linecap="round"/>
                </svg>
            </div>
            <h1 class="convert-error__title">Payment Not Confirmed</h1>
            <p class="convert-error__message">
                <?= htmlspecialchars($payment_error, ENT_QUOTES, 'UTF-8') ?>
            </p>
            <div class="convert-error__actions">
                <a href="<?= base_url('/preview/' . $conversion['uuid']) ?>" class="btn btn--primary">
                    Try Again
                </a>
                <a
                    href="mailto:<?= htmlspecialchars((string) config('contact_email', 'support@base44towordpress.com'), ENT_QUOTES, 'UTF-8') ?>?subject=Payment+Issue+<?= urlencode($conversion['uuid']) ?>"
                    class="btn btn--secondary"
                >
                    Contact Support
                </a>
            </div>
        </div>

        <?php else: ?>
        <!-- Conversion in progress (or paid/converting) -->
        <div
            class="convert-progress card"
            data-conversion-uuid="<?= htmlspecialchars($conversion['uuid'], ENT_QUOTES, 'UTF-8') ?>"
            data-conversion-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
            id="convert-progress"
        >
            <div class="convert-progress__anim" aria-hidden="true">
                <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                    <circle cx="36" cy="36" r="32" stroke="#e2e8f0" stroke-width="3"/>
                    <circle cx="36" cy="36" r="32" stroke="#6366f1" stroke-width="3"
                        stroke-dasharray="201" stroke-dashoffset="150"
                        stroke-linecap="round"
                        class="convert-progress__arc"
                    />
                    <path d="M26 34l7 7 13-13" stroke="#6366f1" stroke-width="2.5"
                        stroke-linecap="round" stroke-linejoin="round"
                        class="convert-progress__check" style="opacity:0"/>
                </svg>
            </div>

            <h1 class="convert-progress__title" id="convert-title">
                Converting Your Theme&hellip;
            </h1>

            <p class="convert-progress__message" id="convert-message" aria-live="polite">
                Analysing your source code&hellip;
            </p>

            <div class="progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="Conversion progress">
                <div class="progress-bar__fill" id="progress-bar-fill" style="width: 0%"></div>
            </div>

            <ul class="convert-steps" id="convert-steps" aria-live="polite">
                <li class="convert-step convert-step--active" id="step-parse">
                    <span class="convert-step__indicator" aria-hidden="true"></span>
                    Parsing source files
                </li>
                <li class="convert-step" id="step-generate">
                    <span class="convert-step__indicator" aria-hidden="true"></span>
                    Generating theme templates
                </li>
                <li class="convert-step" id="step-css">
                    <span class="convert-step__indicator" aria-hidden="true"></span>
                    Converting styles to WordPress CSS
                </li>
                <li class="convert-step" id="step-assemble">
                    <span class="convert-step__indicator" aria-hidden="true"></span>
                    Assembling theme package
                </li>
                <li class="convert-step" id="step-done">
                    <span class="convert-step__indicator" aria-hidden="true"></span>
                    Finalising download
                </li>
            </ul>

            <p class="convert-progress__note">
                This usually takes 1&ndash;3 minutes. Please keep this page open.
            </p>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php if ($status !== 'failed' && $payment_error === null): ?>
<script>
(function () {
    'use strict';

    var uuid         = <?= json_encode($conversion['uuid']) ?>;
    var statusApiUrl = <?= json_encode(base_url('/api/convert-status/' . $conversion['uuid'])) ?>;
    var downloadUrl  = <?= json_encode(base_url('/download/' . $conversion['uuid'])) ?>;
    var pollInterval = 3000; // ms
    var maxPolls     = 120;  // 6 minutes maximum
    var pollCount    = 0;

    var titleEl    = document.getElementById('convert-title');
    var messageEl  = document.getElementById('convert-message');
    var barEl      = document.getElementById('progress-bar-fill');
    var progressEl = document.querySelector('[role="progressbar"]');
    var checkEl    = document.querySelector('.convert-progress__check');
    var arcEl      = document.querySelector('.convert-progress__arc');

    // Step elements
    var steps = {
        parse:    document.getElementById('step-parse'),
        generate: document.getElementById('step-generate'),
        css:      document.getElementById('step-css'),
        assemble: document.getElementById('step-assemble'),
        done:     document.getElementById('step-done'),
    };

    // Progress simulation based on reported ai_calls_used
    var phaseProgress = {
        'paid':       5,
        'converting': 10,
    };

    function setProgress(pct) {
        pct = Math.min(100, Math.max(0, pct));
        if (barEl)      barEl.style.width = pct + '%';
        if (progressEl) progressEl.setAttribute('aria-valuenow', String(Math.round(pct)));
    }

    function activateStep(stepKey) {
        Object.keys(steps).forEach(function (k) {
            if (!steps[k]) return;
            steps[k].classList.remove('convert-step--active', 'convert-step--done');
        });

        var keys = Object.keys(steps);
        var idx  = keys.indexOf(stepKey);

        keys.forEach(function (k, i) {
            if (!steps[k]) return;
            if (i < idx) {
                steps[k].classList.add('convert-step--done');
            } else if (i === idx) {
                steps[k].classList.add('convert-step--active');
            }
        });
    }

    function guessStep(aiCalls) {
        if (aiCalls === 0)        return 'parse';
        if (aiCalls < 3)         return 'generate';
        if (aiCalls < 6)         return 'css';
        if (aiCalls < 8)         return 'assemble';
        return 'done';
    }

    function poll() {
        if (pollCount >= maxPolls) {
            if (messageEl) messageEl.textContent = 'Conversion is taking longer than expected. Please refresh in a few minutes or contact support.';
            return;
        }
        pollCount++;

        var xhr = new XMLHttpRequest();
        xhr.open('GET', statusApiUrl, true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.timeout = 10000;

        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;

            if (xhr.status !== 200) {
                // Transient error — keep polling
                setTimeout(poll, pollInterval);
                return;
            }

            var data;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (_) {
                setTimeout(poll, pollInterval);
                return;
            }

            var status   = data.status    || '';
            var aiCalls  = data.ai_calls_used || 0;
            var message  = data.message   || '';

            if (status === 'complete') {
                // Mark all steps done
                Object.keys(steps).forEach(function (k) {
                    if (steps[k]) steps[k].classList.add('convert-step--done');
                });
                setProgress(100);
                if (progressEl) progressEl.setAttribute('aria-valuenow', '100');
                if (titleEl)   titleEl.textContent  = 'Conversion Complete!';
                if (messageEl) messageEl.textContent = 'Your WordPress theme is ready. Redirecting\u2026';
                if (checkEl)   checkEl.style.opacity = '1';
                if (arcEl)     arcEl.style.strokeDashoffset = '0';

                setTimeout(function () {
                    window.location.href = downloadUrl;
                }, 1200);
                return;
            }

            if (status === 'failed') {
                if (titleEl)   titleEl.textContent  = 'Conversion Failed';
                if (messageEl) messageEl.textContent = data.error || 'An error occurred. Please contact support.';
                setProgress(0);
                return;
            }

            // Still going — update UI
            var pct = phaseProgress[status] || 10;
            pct = Math.min(90, pct + (aiCalls * 8));
            setProgress(pct);
            activateStep(guessStep(aiCalls));

            if (message && messageEl) {
                messageEl.textContent = message;
            }

            setTimeout(poll, pollInterval);
        };

        xhr.ontimeout = function () {
            setTimeout(poll, pollInterval);
        };

        xhr.onerror = function () {
            setTimeout(poll, pollInterval);
        };

        xhr.send();
    }

    // Start polling after a short delay
    setTimeout(poll, 1500);
}());
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
