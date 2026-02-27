<?php
/**
 * pages/webhook-stripe.php — Stripe webhook endpoint.
 *
 * POST /webhook/stripe
 *
 * No session or authentication. Stripe sends signed POST requests to this URL.
 * Signature verification is mandatory before any processing.
 *
 * Events handled:
 *   - checkout.session.completed  → mark conversion as paid
 *   - payment_intent.payment_failed (optional awareness)  → log only
 *
 * Security notes:
 *   - Raw payload is read before any framework/output buffering touches it.
 *   - Signature is verified via HMAC-SHA256 (see stripe_webhook_verify()).
 *   - Replay protection: events older than 5 minutes are rejected.
 *   - Stripe retries on non-2xx; we always return 200 for known events to
 *     avoid infinite retries on non-critical errors.
 *   - Idempotency: if the conversion is already 'paid' or beyond, skip the update.
 *
 * Stripe webhook retries: if we return 4xx/5xx, Stripe will retry the event.
 * Return 400 only for invalid signatures. Return 200 for everything else,
 * including events we don't handle, so Stripe stops retrying those.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/stripe.php';

// ─── Read raw body FIRST (before any output buffering) ────────────────────────

$payload    = (string) file_get_contents('php://input');
$sig_header = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

// ─── Verify signature ─────────────────────────────────────────────────────────

$event = stripe_webhook_verify($payload, $sig_header);

if ($event === null) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Invalid signature or expired event.']);
    exit;
}

// ─── Route by event type ──────────────────────────────────────────────────────

$event_type = (string) ($event['type'] ?? '');

switch ($event_type) {

    // ── checkout.session.completed ────────────────────────────────────────────
    //
    // Fires when the customer completes payment on the Stripe-hosted checkout
    // page. This is the canonical event we use to mark a conversion as paid.
    //
    // Note: the success_url redirect also verifies payment via verify_stripe_session(),
    // so this webhook acts as a reliable backup (e.g. if the customer closes their
    // browser before being redirected back).

    case 'checkout.session.completed':
        $session        = $event['data']['object'] ?? [];
        $payment_status = (string) ($session['payment_status'] ?? '');
        $session_id     = (string) ($session['id'] ?? '');
        $metadata       = $session['metadata'] ?? [];
        $conversion_uuid = (string) ($metadata['conversion_uuid'] ?? '');

        // Only process fully-paid sessions
        if ($payment_status !== 'paid' || $conversion_uuid === '' || $session_id === '') {
            // Not paid yet (e.g. async payment method still pending) — acknowledge and wait
            http_response_code(200);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['received' => true, 'action' => 'skipped_not_paid']);
            exit;
        }

        try {
            // Load the conversion by UUID
            $stmt = db()->prepare(
                'SELECT id, status, payment_status
                   FROM conversions
                  WHERE uuid = :uuid
                  LIMIT 1'
            );
            $stmt->execute([':uuid' => $conversion_uuid]);
            $conversion = $stmt->fetch();

            if (!$conversion) {
                // No matching conversion — log and acknowledge (don't retry)
                error_log('[B442WP] Stripe webhook: conversion not found for UUID ' . $conversion_uuid);
                http_response_code(200);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['received' => true, 'action' => 'conversion_not_found']);
                exit;
            }

            // Idempotency: only update if not already marked paid or beyond
            $current_status   = (string) ($conversion['status'] ?? '');
            $current_pay_stat = (string) ($conversion['payment_status'] ?? '');

            $already_processed = in_array($current_status, ['paid', 'converting', 'complete'], true)
                || $current_pay_stat === 'paid';

            if (!$already_processed) {
                $upd = db()->prepare(
                    'UPDATE conversions
                        SET payment_status = :pay_status,
                            payment_id     = COALESCE(NULLIF(:session_id, \'\'), payment_id),
                            status         = :status
                      WHERE id = :id
                        AND status NOT IN (\'paid\', \'converting\', \'complete\')'
                );
                $upd->execute([
                    ':pay_status' => 'paid',
                    ':session_id' => $session_id,
                    ':status'     => 'paid',
                    ':id'         => (int) $conversion['id'],
                ]);
            }

        } catch (Throwable $e) {
            error_log('[B442WP] Stripe webhook error (checkout.session.completed): ' . $e->getMessage());
            // Return 500 so Stripe retries this event
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'Internal server error.']);
            exit;
        }

        break;

    // ── checkout.session.async_payment_succeeded ──────────────────────────────
    //
    // Fires for delayed payment methods (bank transfers, etc.) when payment
    // eventually succeeds. Treat identically to checkout.session.completed.

    case 'checkout.session.async_payment_succeeded':
        $session         = $event['data']['object'] ?? [];
        $session_id      = (string) ($session['id'] ?? '');
        $metadata        = $session['metadata'] ?? [];
        $conversion_uuid = (string) ($metadata['conversion_uuid'] ?? '');

        if ($conversion_uuid !== '' && $session_id !== '') {
            try {
                $upd = db()->prepare(
                    'UPDATE conversions
                        SET payment_status = :pay_status,
                            payment_id     = COALESCE(NULLIF(:session_id, \'\'), payment_id),
                            status         = :status
                      WHERE uuid = :uuid
                        AND status NOT IN (\'paid\', \'converting\', \'complete\')'
                );
                $upd->execute([
                    ':pay_status' => 'paid',
                    ':session_id' => $session_id,
                    ':status'     => 'paid',
                    ':uuid'       => $conversion_uuid,
                ]);
            } catch (Throwable $e) {
                error_log('[B442WP] Stripe webhook error (async_payment_succeeded): ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'Internal server error.']);
                exit;
            }
        }
        break;

    // ── checkout.session.async_payment_failed ─────────────────────────────────

    case 'checkout.session.async_payment_failed':
        // Log only — the user will see the payment as unpaid on return
        $session         = $event['data']['object'] ?? [];
        $metadata        = $session['metadata'] ?? [];
        $conversion_uuid = (string) ($metadata['conversion_uuid'] ?? '');
        error_log('[B442WP] Stripe async payment failed for conversion UUID: ' . $conversion_uuid);
        break;

    // ── All other events: acknowledge and ignore ──────────────────────────────
    default:
        // Do nothing — return 200 so Stripe doesn't retry
        break;
}

http_response_code(200);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['received' => true]);
exit;
