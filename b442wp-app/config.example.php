<?php
/**
 * B442WP Configuration Template
 * ─────────────────────────────
 * Copy this file to config.php and fill in your real values.
 * config.php is gitignored — never commit it.
 */

return [

    // ── Site ──────────────────────────────────────────────────────────────
    'site_name'     => 'Base44 to WordPress',
    'site_url'      => 'https://app.base44towordpress.com',
    'marketing_url' => 'https://base44towordpress.com',
    'contact_email' => 'admin@base44towordpress.com',

    // ── Database ──────────────────────────────────────────────────────────
    'db_path' => __DIR__ . '/storage/database.sqlite',

    // ── Claude API ────────────────────────────────────────────────────────
    // Get your key at https://console.anthropic.com
    'claude_api_key'    => 'sk-ant-XXXXXXXXXXXX',
    'claude_model'      => 'claude-sonnet-4-20250514',
    'claude_max_tokens' => 8192,

    // ── Stripe (one-time payments ONLY — do NOT use subscription mode) ────
    // Get keys at https://dashboard.stripe.com/apikeys
    // Webhook secret from https://dashboard.stripe.com/webhooks
    'stripe_publishable_key' => 'pk_test_XXXXXXXXXXXX',
    'stripe_secret_key'      => 'sk_test_XXXXXXXXXXXX',
    'stripe_webhook_secret'  => 'whsec_XXXXXXXXXXXX',

    // ── PayPal (one-time payments ONLY) ───────────────────────────────────
    // Get credentials at https://developer.paypal.com/dashboard/applications
    'paypal_client_id'     => 'XXXXXXXXXXXX',
    'paypal_client_secret' => 'XXXXXXXXXXXX',
    'paypal_mode'          => 'sandbox', // Change to 'live' for production

    // ── Pricing (in cents) ────────────────────────────────────────────────
    'price_basic'       => 900,   // $9.00 — no WooCommerce
    'price_woocommerce' => 1900,  // $19.00 — with WooCommerce

    // ── File Storage ──────────────────────────────────────────────────────
    'max_upload_size' => 50 * 1024 * 1024,               // 50 MB
    'upload_dir'      => __DIR__ . '/storage/uploads',
    'output_dir'      => __DIR__ . '/storage/conversions',

    // ── Download Expiry ───────────────────────────────────────────────────
    'download_expiry_days' => 30,

    // ── Session ───────────────────────────────────────────────────────────
    'session_lifetime' => 86400 * 7, // 7 days
    'bcrypt_cost'      => 12,

];
