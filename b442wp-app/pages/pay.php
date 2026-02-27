<?php
/**
 * pages/pay.php — Initiate payment for a conversion.
 *
 * POST /pay/{uuid}
 *
 * Authenticated. Validates the request, creates a payment session with
 * the chosen provider (Stripe or PayPal), persists the payment ID, then
 * redirects the user to the provider's hosted checkout page.
 *
 * Accepts GET requests only to redirect appropriately (e.g. if the user
 * bookmarks the URL or navigates directly).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/stripe.php';
require_once __DIR__ . '/../includes/paypal.php';

// Must be authenticated
require_auth();

// GET requests shouldn't hit this endpoint directly; redirect to preview
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // If we have a UUID from the route, bounce to preview
    $uuid = $_REQUEST['uuid'] ?? '';
    if ($uuid !== '') {
        redirect('/preview/' . $uuid);
    }
    redirect('/');
}

// ─── POST handler ──────────────────────────────────────────────────────────────

// 1. CSRF check
csrf_verify();

// 2. Load conversion and verify ownership
$conversion = verify_conversion_owner($_REQUEST['uuid'] ?? '');
$status     = $conversion['status'] ?? '';

// Only allow payment initiation from 'parsed' or a previously-attempted 'unpaid' state.
// ('paid' should not re-attempt; 'converting'/'complete' should redirect away)
if (!in_array($status, ['parsed', 'unpaid'], true)) {
    $redirect_map = [
        'paid'       => '/convert/' . $conversion['uuid'],
        'converting' => '/convert/' . $conversion['uuid'],
        'complete'   => '/download/' . $conversion['uuid'],
        'failed'     => '/preview/' . $conversion['uuid'],
        'pending'    => '/preview/' . $conversion['uuid'],
    ];

    $target = $redirect_map[$status] ?? '/';
    flash('info', 'This conversion is currently in status: ' . conversion_status_label($status) . '.', 'info');
    redirect($target);
}

// 3. Validate provider
$provider = trim(strtolower((string) ($_POST['provider'] ?? '')));

if (!in_array($provider, ['stripe', 'paypal'], true)) {
    flash('error', 'Invalid payment provider selected.', 'error');
    redirect('/preview/' . $conversion['uuid']);
}

// 4. Create checkout session / order
try {
    if ($provider === 'stripe') {
        // Returns the Stripe Checkout Session URL
        $checkout_url = create_stripe_checkout($conversion);

        // The payment_id for Stripe is the session ID, extracted from the URL
        // Stripe embeds it as ?session_id=cs_… but we store it after redirect confirms.
        // For now store a placeholder; the webhook/success handler will persist the real ID.
        $payment_id = '';

    } else {
        // PayPal: returns approval URL
        $checkout_url = create_paypal_order($conversion);

        // Extract the PayPal order ID from the return URL's query string
        // The approval URL looks like: https://www.sandbox.paypal.com/checkoutnow?token=ORDER_ID
        $payment_id = '';
        $parsed_url = parse_url($checkout_url);
        if (!empty($parsed_url['query'])) {
            parse_str($parsed_url['query'], $qs);
            $payment_id = $qs['token'] ?? '';
        }
    }
} catch (RuntimeException $e) {
    flash('error', 'Could not connect to the payment provider. Please try again in a moment.', 'error');
    // Log for server-side visibility (error_log goes to the web server error log)
    error_log('[B442WP] Payment provider error (' . $provider . '): ' . $e->getMessage());
    redirect('/preview/' . $conversion['uuid']);
}

// 5. Persist payment provider and initial payment ID to the database
try {
    $stmt = db()->prepare(
        'UPDATE conversions
            SET payment_provider = :provider,
                payment_id       = :payment_id,
                payment_status   = :payment_status
          WHERE id = :id'
    );
    $stmt->execute([
        ':provider'       => $provider,
        ':payment_id'     => $payment_id,
        ':payment_status' => 'unpaid',
        ':id'             => (int) $conversion['id'],
    ]);
} catch (PDOException $e) {
    // Non-fatal: the payment session is already created — just log and continue
    error_log('[B442WP] Failed to persist payment_id for conversion ' . $conversion['uuid'] . ': ' . $e->getMessage());
}

// 6. Redirect to the provider's hosted checkout page
redirect($checkout_url);
