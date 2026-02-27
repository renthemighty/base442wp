<?php
/**
 * B442WP — Stripe Integration
 *
 * One-time payments only (mode: "payment"). No Stripe PHP SDK required;
 * all communication is done via raw cURL against the Stripe v1 REST API.
 *
 * Depends on:
 *   - includes/helpers.php  (config(), base_url(), format_price())
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

// ─── Low-level HTTP ────────────────────────────────────────────────────────────

/**
 * Execute a signed request against the Stripe API.
 *
 * @param  string  $endpoint  Path after /v1/, e.g. "checkout/sessions".
 * @param  array   $params    Key-value pairs to send as the request body or
 *                            query string (for GET requests).
 * @param  string  $method    HTTP verb: 'POST', 'GET', etc.
 * @return array<string, mixed>  Decoded JSON response.
 * @throws RuntimeException  On cURL failure or non-2xx HTTP status.
 */
function stripe_request(string $endpoint, array $params = [], string $method = 'POST'): array
{
    $secret_key = (string) config('stripe_secret_key', '');

    if ($secret_key === '') {
        throw new RuntimeException('Stripe secret key is not configured.');
    }

    $url = 'https://api.stripe.com/v1/' . ltrim($endpoint, '/');

    $ch = curl_init();

    $curl_opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $secret_key,
            'Content-Type: application/x-www-form-urlencoded',
            'Stripe-Version: 2024-04-10',
        ],
        // Verify Stripe's SSL certificate
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    $method = strtoupper($method);

    if ($method === 'POST') {
        $curl_opts[CURLOPT_POST]       = true;
        $curl_opts[CURLOPT_POSTFIELDS] = _stripe_build_query($params);
    } elseif ($method === 'GET' && !empty($params)) {
        $url .= '?' . _stripe_build_query($params);
    }

    $curl_opts[CURLOPT_URL] = $url;

    curl_setopt_array($ch, $curl_opts);

    $body      = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Stripe cURL error: ' . $curl_err);
    }

    $decoded = json_decode((string) $body, true);

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'Stripe returned non-JSON response (HTTP ' . $http_code . ').'
        );
    }

    if ($http_code < 200 || $http_code >= 300) {
        $stripe_msg = $decoded['error']['message'] ?? 'Unknown Stripe error.';
        throw new RuntimeException(
            'Stripe API error (HTTP ' . $http_code . '): ' . $stripe_msg
        );
    }

    return $decoded;
}

/**
 * Build a percent-encoded query string that supports nested arrays.
 *
 * Stripe uses PHP-style bracket notation for nested params, e.g.:
 *   line_items[0][price_data][currency]=usd
 *
 * @param  array  $params
 * @param  string $prefix  Used internally for recursion.
 * @return string
 */
function _stripe_build_query(array $params, string $prefix = ''): string
{
    $parts = [];

    foreach ($params as $key => $value) {
        $full_key = $prefix !== '' ? $prefix . '[' . $key . ']' : (string) $key;

        if (is_array($value)) {
            $parts[] = _stripe_build_query($value, $full_key);
        } else {
            $parts[] = rawurlencode($full_key) . '=' . rawurlencode((string) $value);
        }
    }

    return implode('&', $parts);
}

// ─── Checkout session ──────────────────────────────────────────────────────────

/**
 * Create a Stripe Checkout Session for a one-time payment.
 *
 * @param  array<string, mixed> $conversion  Row from the conversions table.
 * @return string  The Checkout Session URL to redirect the customer to.
 * @throws RuntimeException  On API or configuration errors.
 */
function create_stripe_checkout(array $conversion): string
{
    $price_cents = (int) ($conversion['price_cents'] ?? 0);

    if ($price_cents <= 0) {
        throw new RuntimeException('Invalid price_cents in conversion record.');
    }

    $product_name = sprintf(
        'Base44→WP: %s Conversion (%s)',
        !empty($conversion['has_woocommerce']) ? 'WooCommerce' : 'Basic',
        $conversion['theme_name'] ?? 'WordPress Theme'
    );

    $uuid    = $conversion['uuid'];
    $user_id = (string) ($conversion['user_id'] ?? '');

    $session = stripe_request('checkout/sessions', [
        'mode'             => 'payment',
        'payment_method_types' => ['card'],
        'line_items'       => [
            [
                'price_data' => [
                    'currency'     => 'usd',
                    'unit_amount'  => $price_cents,
                    'product_data' => [
                        'name'        => $product_name,
                        'description' => 'One-time WordPress theme conversion. Download available for 30 days.',
                    ],
                ],
                'quantity' => 1,
            ],
        ],
        'success_url' => base_url('/convert/' . $uuid . '?payment=success&session_id={CHECKOUT_SESSION_ID}'),
        'cancel_url'  => base_url('/preview/' . $uuid . '?payment=cancelled'),
        'metadata'    => [
            'conversion_uuid' => $uuid,
            'user_id'         => $user_id,
        ],
        // Allow the customer to see their email pre-filled if we have it
        'customer_creation' => 'if_required',
    ]);

    $checkout_url = $session['url'] ?? '';

    if ($checkout_url === '') {
        throw new RuntimeException('Stripe did not return a checkout URL.');
    }

    return $checkout_url;
}

// ─── Session verification ──────────────────────────────────────────────────────

/**
 * Retrieve and verify a Stripe Checkout Session by ID.
 *
 * @param  string  $session_id  The Checkout Session ID (cs_…).
 * @return array<string, mixed>|null  Full session data if payment_status == 'paid';
 *                                    null otherwise.
 */
function verify_stripe_session(string $session_id): ?array
{
    if ($session_id === '' || !str_starts_with($session_id, 'cs_')) {
        return null;
    }

    try {
        $session = stripe_request(
            'checkout/sessions/' . rawurlencode($session_id),
            [],
            'GET'
        );
    } catch (RuntimeException) {
        return null;
    }

    if (($session['payment_status'] ?? '') !== 'paid') {
        return null;
    }

    return $session;
}

// ─── Webhook verification ──────────────────────────────────────────────────────

/**
 * Verify an inbound Stripe webhook request using the HMAC-SHA256 signature.
 *
 * Implements Stripe's signature verification algorithm:
 *   1. Parse the Stripe-Signature header to extract timestamp (t) and signatures (v1).
 *   2. Build the signed payload string: "{t}.{raw_payload}".
 *   3. Compute HMAC-SHA256 of that string using the webhook secret.
 *   4. Compare with each v1 signature using a timing-safe comparison.
 *   5. Reject if the timestamp is more than 5 minutes old (replay protection).
 *
 * @param  string  $payload     Raw POST body (file_get_contents('php://input')).
 * @param  string  $sig_header  Value of the HTTP_STRIPE_SIGNATURE server variable.
 * @return array<string, mixed>|null  Decoded event on success; null on failure.
 */
function stripe_webhook_verify(string $payload, string $sig_header): ?array
{
    $webhook_secret = (string) config('stripe_webhook_secret', '');

    if ($webhook_secret === '' || $payload === '' || $sig_header === '') {
        return null;
    }

    // Parse the Stripe-Signature header
    // Format: "t=1614556800,v1=abc123,v1=def456"
    $parts     = explode(',', $sig_header);
    $timestamp = null;
    $signatures = [];

    foreach ($parts as $part) {
        $part = trim($part);
        if (str_starts_with($part, 't=')) {
            $timestamp = substr($part, 2);
        } elseif (str_starts_with($part, 'v1=')) {
            $signatures[] = substr($part, 3);
        }
    }

    if ($timestamp === null || empty($signatures)) {
        return null;
    }

    // Replay attack protection: reject events older than 5 minutes
    $ts = (int) $timestamp;
    if (abs(time() - $ts) > 300) {
        return null;
    }

    // Compute expected signature
    $signed_payload  = $timestamp . '.' . $payload;
    $expected_sig    = hash_hmac('sha256', $signed_payload, $webhook_secret);

    // Compare with each v1 signature (timing-safe)
    $verified = false;
    foreach ($signatures as $sig) {
        if (hash_equals($expected_sig, $sig)) {
            $verified = true;
            break;
        }
    }

    if (!$verified) {
        return null;
    }

    $event = json_decode($payload, true);

    if (!is_array($event)) {
        return null;
    }

    return $event;
}
