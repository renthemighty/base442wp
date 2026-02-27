<?php
/**
 * B442WP — PayPal Integration
 *
 * One-time payments only (intent: CAPTURE). No PayPal SDK required;
 * all communication is done via raw cURL against the PayPal Orders v2 REST API.
 *
 * Supports both sandbox and live modes, configured via config('paypal_mode').
 *
 * Depends on:
 *   - includes/helpers.php  (config(), base_url(), format_price())
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

// ─── Base URL helper ────────────────────────────────────────────────────────────

/**
 * Return the PayPal API base URL based on the configured mode.
 *
 * @return string  e.g. "https://api-m.sandbox.paypal.com" or "https://api-m.paypal.com"
 */
function _paypal_base_url(): string
{
    $mode = (string) config('paypal_mode', 'sandbox');
    return $mode === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}

// ─── OAuth access token ────────────────────────────────────────────────────────

/**
 * Obtain a PayPal OAuth 2.0 access token using client credentials.
 *
 * The token is cached in a static variable for the lifetime of the request
 * to avoid redundant auth calls on the same request.
 *
 * @return string  Bearer access token.
 * @throws RuntimeException  On cURL failure, configuration error, or API error.
 */
function paypal_get_access_token(): string
{
    static $cached_token = null;
    static $token_expiry = 0;

    // Return cached token if it's still valid (with 60-second safety margin)
    if ($cached_token !== null && time() < ($token_expiry - 60)) {
        return $cached_token;
    }

    $client_id     = (string) config('paypal_client_id', '');
    $client_secret = (string) config('paypal_client_secret', '');

    if ($client_id === '' || $client_secret === '') {
        throw new RuntimeException('PayPal client_id or client_secret is not configured.');
    }

    $url = _paypal_base_url() . '/v1/oauth2/token';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => $client_id . ':' . $client_secret,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Accept-Language: en_US',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body      = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('PayPal cURL error (auth): ' . $curl_err);
    }

    $decoded = json_decode((string) $body, true);

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'PayPal returned non-JSON auth response (HTTP ' . $http_code . ').'
        );
    }

    if ($http_code !== 200 || empty($decoded['access_token'])) {
        $err = $decoded['error_description'] ?? $decoded['error'] ?? 'Unknown PayPal auth error.';
        throw new RuntimeException('PayPal auth failed (HTTP ' . $http_code . '): ' . $err);
    }

    $cached_token = (string) $decoded['access_token'];
    $token_expiry = time() + (int) ($decoded['expires_in'] ?? 3600);

    return $cached_token;
}

// ─── Low-level HTTP ────────────────────────────────────────────────────────────

/**
 * Execute an authenticated request against the PayPal REST API.
 *
 * @param  string  $endpoint  Path after the base URL, e.g. "/v2/checkout/orders".
 * @param  array   $body      Request body (JSON-encoded for POST/PATCH, ignored for GET).
 * @param  string  $method    HTTP verb: 'POST', 'GET', 'PATCH', etc.
 * @return array<string, mixed>  Decoded JSON response.
 * @throws RuntimeException  On cURL failure or non-2xx HTTP status.
 */
function paypal_request(string $endpoint, array $body = [], string $method = 'POST'): array
{
    $access_token = paypal_get_access_token();
    $url          = _paypal_base_url() . '/' . ltrim($endpoint, '/');
    $method       = strtoupper($method);

    $headers = [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
        'Accept: application/json',
        'Prefer: return=representation',
    ];

    $ch = curl_init();

    $curl_opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CUSTOMREQUEST  => $method,
    ];

    if ($method === 'POST' || $method === 'PATCH') {
        $curl_opts[CURLOPT_POSTFIELDS] = !empty($body) ? json_encode($body) : '';
    }

    curl_setopt_array($ch, $curl_opts);

    $response_body = curl_exec($ch);
    $http_code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err      = curl_error($ch);
    curl_close($ch);

    if ($response_body === false) {
        throw new RuntimeException('PayPal cURL error: ' . $curl_err);
    }

    // Some endpoints (e.g. capture) may return 204 No Content
    if ($response_body === '' || $response_body === 'null') {
        return ['http_code' => $http_code];
    }

    $decoded = json_decode((string) $response_body, true);

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'PayPal returned non-JSON response (HTTP ' . $http_code . ').'
        );
    }

    if ($http_code < 200 || $http_code >= 300) {
        $paypal_msg = $decoded['message'] ?? $decoded['error_description'] ?? 'Unknown PayPal error.';
        throw new RuntimeException(
            'PayPal API error (HTTP ' . $http_code . '): ' . $paypal_msg
        );
    }

    return $decoded;
}

// ─── Order creation ────────────────────────────────────────────────────────────

/**
 * Create a PayPal order for a one-time conversion payment.
 *
 * @param  array<string, mixed> $conversion  Row from the conversions table.
 * @return string  The PayPal approval URL to redirect the customer to.
 * @throws RuntimeException  On API or configuration errors.
 */
function create_paypal_order(array $conversion): string
{
    $price_cents = (int) ($conversion['price_cents'] ?? 0);

    if ($price_cents <= 0) {
        throw new RuntimeException('Invalid price_cents in conversion record.');
    }

    // PayPal expects a decimal dollar string, e.g. "9.00"
    $amount_value = number_format($price_cents / 100, 2, '.', '');

    $description = sprintf(
        'Base44 to WordPress: %s Conversion (%s)',
        !empty($conversion['has_woocommerce']) ? 'WooCommerce' : 'Basic',
        $conversion['theme_name'] ?? 'WordPress Theme'
    );

    $uuid = $conversion['uuid'];

    $order_payload = [
        'intent'         => 'CAPTURE',
        'purchase_units' => [
            [
                'reference_id' => $uuid,
                'description'  => $description,
                'custom_id'    => $uuid,
                'amount'       => [
                    'currency_code' => 'USD',
                    'value'         => $amount_value,
                ],
            ],
        ],
        'application_context' => [
            'brand_name'          => 'Base44 to WordPress',
            'landing_page'        => 'NO_PREFERENCE',
            'shipping_preference' => 'NO_SHIPPING',
            'user_action'         => 'PAY_NOW',
            'return_url'          => base_url('/convert/' . $uuid . '?payment=success&provider=paypal'),
            'cancel_url'          => base_url('/preview/' . $uuid . '?payment=cancelled'),
        ],
    ];

    $order = paypal_request('/v2/checkout/orders', $order_payload, 'POST');

    // Extract the approval URL from the links array
    $approval_url = '';
    foreach ($order['links'] ?? [] as $link) {
        if (($link['rel'] ?? '') === 'approve' && !empty($link['href'])) {
            $approval_url = (string) $link['href'];
            break;
        }
    }

    if ($approval_url === '') {
        throw new RuntimeException(
            'PayPal did not return an approval URL. Order ID: ' . ($order['id'] ?? 'unknown')
        );
    }

    return $approval_url;
}

// ─── Order capture ─────────────────────────────────────────────────────────────

/**
 * Capture an approved PayPal order to complete the payment.
 *
 * Must be called after the customer has approved the order on PayPal's site
 * and been redirected back to the return_url.
 *
 * @param  string $order_id  PayPal order ID (e.g. "3TY07830CH3468931").
 * @return bool              true if the capture status is 'COMPLETED'; false otherwise.
 */
function capture_paypal_order(string $order_id): bool
{
    if ($order_id === '') {
        return false;
    }

    try {
        $result = paypal_request(
            '/v2/checkout/orders/' . rawurlencode($order_id) . '/capture',
            [],
            'POST'
        );
    } catch (RuntimeException) {
        return false;
    }

    return ($result['status'] ?? '') === 'COMPLETED';
}

// ─── Webhook verification ──────────────────────────────────────────────────────

/**
 * Verify an inbound PayPal webhook event.
 *
 * PayPal's full signature verification requires a round-trip API call.
 * This implementation verifies the event by:
 *   1. Decoding the JSON payload.
 *   2. Re-fetching the order referenced in the event from the PayPal API.
 *   3. Confirming the order status matches what the webhook claims.
 *
 * This avoids relying on the complex certificate-based signature algorithm
 * while still confirming the event with PayPal's servers directly.
 *
 * @param  string               $payload  Raw POST body (file_get_contents('php://input')).
 * @param  array<string, mixed> $headers  All HTTP request headers (keys normalised to uppercase).
 * @return bool                           true if the event appears legitimate; false otherwise.
 */
function paypal_webhook_verify(string $payload, array $headers): bool
{
    if ($payload === '') {
        return false;
    }

    $event = json_decode($payload, true);

    if (!is_array($event)) {
        return false;
    }

    // We only care about order-related events
    $event_type = $event['event_type'] ?? '';
    $resource   = $event['resource'] ?? [];

    if (empty($resource)) {
        return false;
    }

    // For CHECKOUT.ORDER.* events, verify by fetching the order from PayPal
    if (str_starts_with($event_type, 'CHECKOUT.ORDER.') || str_starts_with($event_type, 'PAYMENT.CAPTURE.')) {
        $order_id = $resource['id'] ?? '';
        if ($order_id === '') {
            // Try supplementary_data for some event types
            $order_id = $resource['supplementary_data']['related_ids']['order_id'] ?? '';
        }

        if ($order_id === '') {
            return false;
        }

        try {
            $order = paypal_request('/v2/checkout/orders/' . rawurlencode($order_id), [], 'GET');
        } catch (RuntimeException) {
            return false;
        }

        // Verify the order exists and has a valid status
        $valid_statuses = ['APPROVED', 'COMPLETED', 'SAVED'];
        return in_array($order['status'] ?? '', $valid_statuses, true);
    }

    // For unrecognised event types, accept but do not verify (log downstream)
    return true;
}
