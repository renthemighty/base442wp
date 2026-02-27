<?php
/**
 * pages/webhook-paypal.php — PayPal webhook endpoint.
 *
 * POST /webhook/paypal
 *
 * No session or authentication. PayPal sends signed POST requests to this URL.
 *
 * Events handled:
 *   - CHECKOUT.ORDER.APPROVED        → capture the order and mark conversion paid
 *   - PAYMENT.CAPTURE.COMPLETED      → fallback; mark conversion paid if not already
 *   - PAYMENT.CAPTURE.DENIED         → log only
 *   - CHECKOUT.ORDER.COMPLETED       → log only (capture already fired separately)
 *
 * Verification strategy:
 *   paypal_webhook_verify() re-fetches the referenced order from PayPal's API
 *   to confirm the event is genuine, avoiding the complexity of PayPal's
 *   certificate-based signature verification while still making an authenticated
 *   server-to-server check.
 *
 * Idempotency:
 *   Updates are conditional (status NOT IN ...) so repeated delivery of the
 *   same event is harmless.
 *
 * PayPal expects HTTP 200 for all acknowledged events.
 * Return 4xx only for malformed / unverifiable payloads.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/paypal.php';

// ─── Read raw body ─────────────────────────────────────────────────────────────

$payload = (string) file_get_contents('php://input');

if ($payload === '') {
    http_response_code(400);
    echo 'Bad Request: empty payload.';
    exit;
}

// ─── Decode JSON ──────────────────────────────────────────────────────────────

$event = json_decode($payload, true);

if (!is_array($event)) {
    http_response_code(400);
    echo 'Bad Request: invalid JSON.';
    exit;
}

// ─── Collect headers for verification ─────────────────────────────────────────

// getallheaders() may not be available in all environments (e.g. nginx without
// the PHP-FPM fastcgi_param block). Build from $_SERVER as a fallback.
if (function_exists('getallheaders')) {
    $headers = getallheaders() ?: [];
} else {
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $header_name = str_replace('_', '-', substr($key, 5));
            $headers[$header_name] = $value;
        }
    }
}

// ─── Verify webhook authenticity ──────────────────────────────────────────────

if (!paypal_webhook_verify($payload, $headers)) {
    http_response_code(400);
    echo 'Verification failed.';
    exit;
}

// ─── Route by event type ──────────────────────────────────────────────────────

$event_type = (string) ($event['event_type'] ?? '');
$resource   = $event['resource'] ?? [];

switch ($event_type) {

    // ── CHECKOUT.ORDER.APPROVED ───────────────────────────────────────────────
    //
    // The customer approved the payment on PayPal's hosted page.
    // We must still capture the order to move the money.
    // (PayPal may also fire this on the return_url redirect; this webhook
    //  acts as a reliable backup in case the redirect fails.)

    case 'CHECKOUT.ORDER.APPROVED':
        $order_id = (string) ($resource['id'] ?? '');

        if ($order_id === '') {
            error_log('[B442WP] PayPal webhook: CHECKOUT.ORDER.APPROVED missing order ID.');
            http_response_code(200);
            echo 'OK';
            exit;
        }

        try {
            // Look up the conversion by its payment_id (the PayPal order token
            // saved when create_paypal_order() returned the approval URL).
            $stmt = db()->prepare(
                'SELECT id, status, payment_status
                   FROM conversions
                  WHERE payment_id = :order_id
                     OR payment_id = :order_id2
                  LIMIT 1'
            );
            $stmt->execute([':order_id' => $order_id, ':order_id2' => $order_id]);
            $conversion = $stmt->fetch();

            if (!$conversion) {
                // Order ID not found — may have been stored differently; log and skip
                error_log('[B442WP] PayPal webhook: no conversion found for order ID ' . $order_id);
                http_response_code(200);
                echo 'OK';
                exit;
            }

            $current_status   = (string) ($conversion['status'] ?? '');
            $current_pay_stat = (string) ($conversion['payment_status'] ?? '');

            $already_paid = in_array($current_status, ['paid', 'converting', 'complete'], true)
                || $current_pay_stat === 'paid';

            if (!$already_paid) {
                // Capture the order (completes the payment)
                $captured = capture_paypal_order($order_id);

                if ($captured) {
                    $upd = db()->prepare(
                        'UPDATE conversions
                            SET payment_status = :pay_status,
                                status         = :status
                          WHERE id = :id
                            AND status NOT IN (\'paid\', \'converting\', \'complete\')'
                    );
                    $upd->execute([
                        ':pay_status' => 'paid',
                        ':status'     => 'paid',
                        ':id'         => (int) $conversion['id'],
                    ]);
                } else {
                    error_log('[B442WP] PayPal webhook: capture failed for order ID ' . $order_id);
                    // Return 500 to encourage PayPal retry
                    http_response_code(500);
                    echo 'Capture failed.';
                    exit;
                }
            }

        } catch (Throwable $e) {
            error_log('[B442WP] PayPal webhook error (CHECKOUT.ORDER.APPROVED): ' . $e->getMessage());
            http_response_code(500);
            echo 'Internal error.';
            exit;
        }

        break;

    // ── PAYMENT.CAPTURE.COMPLETED ─────────────────────────────────────────────
    //
    // Fires when a payment capture completes — either initiated by us or by
    // PayPal after an approved order. Use as a fallback to ensure the
    // conversion is marked paid even if CHECKOUT.ORDER.APPROVED was missed.

    case 'PAYMENT.CAPTURE.COMPLETED':
        // The order ID lives in supplementary_data for capture events
        $order_id = (string) (
            $resource['supplementary_data']['related_ids']['order_id']
            ?? $resource['id']
            ?? ''
        );

        if ($order_id === '') {
            break;
        }

        try {
            $stmt = db()->prepare(
                'SELECT id, status, payment_status
                   FROM conversions
                  WHERE payment_id = :order_id
                  LIMIT 1'
            );
            $stmt->execute([':order_id' => $order_id]);
            $conversion = $stmt->fetch();

            if ($conversion) {
                $current_pay_stat = (string) ($conversion['payment_status'] ?? '');
                $current_status   = (string) ($conversion['status'] ?? '');

                if (
                    $current_pay_stat !== 'paid'
                    && !in_array($current_status, ['paid', 'converting', 'complete'], true)
                ) {
                    $upd = db()->prepare(
                        'UPDATE conversions
                            SET payment_status = :pay_status,
                                status         = :status
                          WHERE id = :id
                            AND status NOT IN (\'paid\', \'converting\', \'complete\')'
                    );
                    $upd->execute([
                        ':pay_status' => 'paid',
                        ':status'     => 'paid',
                        ':id'         => (int) $conversion['id'],
                    ]);
                }
            }
        } catch (Throwable $e) {
            error_log('[B442WP] PayPal webhook error (PAYMENT.CAPTURE.COMPLETED): ' . $e->getMessage());
            http_response_code(500);
            echo 'Internal error.';
            exit;
        }

        break;

    // ── PAYMENT.CAPTURE.DENIED ────────────────────────────────────────────────
    //
    // The capture was declined. Log for merchant awareness; the user will
    // need to retry payment from the preview page.

    case 'PAYMENT.CAPTURE.DENIED':
        $order_id = (string) (
            $resource['supplementary_data']['related_ids']['order_id']
            ?? $resource['id']
            ?? ''
        );
        error_log('[B442WP] PayPal payment capture denied. Order ID: ' . $order_id);
        break;

    // ── CHECKOUT.ORDER.COMPLETED ──────────────────────────────────────────────
    //
    // Fires after all purchase units have been captured. By this point
    // PAYMENT.CAPTURE.COMPLETED should already have fired — this is a no-op.

    case 'CHECKOUT.ORDER.COMPLETED':
        // No action needed; capture event already handled above
        break;

    // ── All other events: acknowledge and ignore ──────────────────────────────
    default:
        // Return 200 so PayPal does not keep retrying unknown event types
        break;
}

http_response_code(200);
header('Content-Type: text/plain; charset=UTF-8');
echo 'OK';
exit;
