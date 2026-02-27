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

CREATE INDEX IF NOT EXISTS idx_conversions_user_id ON conversions(user_id);
CREATE INDEX IF NOT EXISTS idx_conversions_uuid ON conversions(uuid);
CREATE INDEX IF NOT EXISTS idx_conversions_status ON conversions(status);
CREATE INDEX IF NOT EXISTS idx_password_resets_token ON password_resets(token);
