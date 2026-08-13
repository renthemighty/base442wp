-- B442WP Application Schema
-- SQLite 3

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    name TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS conversions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    uuid TEXT UNIQUE NOT NULL,
    original_filename TEXT NOT NULL,
    live_url TEXT,
    status TEXT DEFAULT 'pending',
    -- Status flow: pending → parsed → paid → converting → complete → failed → expired
    parsed_data TEXT,            -- JSON: detected pages, fonts, colors, entities
    theme_name TEXT,
    has_woocommerce INTEGER DEFAULT 0,
    price_cents INTEGER,         -- 900 or 1900
    payment_provider TEXT,       -- 'stripe' or 'paypal'
    payment_id TEXT,             -- Stripe session ID or PayPal order ID
    payment_status TEXT DEFAULT 'unpaid', -- unpaid, paid, refunded
    error_message TEXT,
    source_zip_path TEXT,
    output_zip_path TEXT,
    ai_calls_used INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME,
    expires_at DATETIME,         -- completed_at + 30 days
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS password_resets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token TEXT UNIQUE NOT NULL,
    expires_at DATETIME NOT NULL,
    used INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS coupons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT UNIQUE NOT NULL,
    max_uses INTEGER NOT NULL DEFAULT 1,
    times_used INTEGER NOT NULL DEFAULT 0,
    discount_type TEXT NOT NULL DEFAULT 'free',  -- 'free' = skip payment entirely
    discount_value INTEGER DEFAULT 0,            -- cents off (unused for 'free' type)
    expires_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS coupon_redemptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coupon_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    conversion_id INTEGER NOT NULL,
    redeemed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (coupon_id) REFERENCES coupons(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (conversion_id) REFERENCES conversions(id)
);

CREATE INDEX IF NOT EXISTS idx_conversions_user_id ON conversions(user_id);
CREATE INDEX IF NOT EXISTS idx_conversions_uuid ON conversions(uuid);
CREATE INDEX IF NOT EXISTS idx_conversions_status ON conversions(status);
CREATE INDEX IF NOT EXISTS idx_password_resets_token ON password_resets(token);
CREATE INDEX IF NOT EXISTS idx_coupons_code ON coupons(code);
CREATE INDEX IF NOT EXISTS idx_coupon_redemptions_user ON coupon_redemptions(user_id);
