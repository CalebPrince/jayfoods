-- Jayfoods :: Order collection schema (SQLite)
-- Idempotent: safe to run on every boot via database/migrate.php.
-- Money is stored as INTEGER pesewas (1 GHS = 100 pesewas) to avoid float drift.

PRAGMA foreign_keys = ON;

-- ---------------------------------------------------------------------------
-- Catalogue of juices offered for single and bulk purchase.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    sku                 TEXT    NOT NULL UNIQUE,
    name                TEXT    NOT NULL,
    flavour             TEXT    NOT NULL DEFAULT '',
    description         TEXT    NOT NULL DEFAULT '',
    unit_label          TEXT    NOT NULL DEFAULT 'bottle',     -- e.g. "500ml bottle"
    image_url           TEXT    NOT NULL DEFAULT '',           -- e.g. "/img/orange-juice.jpg"
    unit_price_pesewas  INTEGER NOT NULL CHECK (unit_price_pesewas >= 0),

    -- Bulk tier: applied per-line when an order is placed as "bulk" and the
    -- requested quantity meets bulk_min_quantity.
    bulk_available      INTEGER NOT NULL DEFAULT 0 CHECK (bulk_available IN (0, 1)),
    bulk_min_quantity   INTEGER NOT NULL DEFAULT 0 CHECK (bulk_min_quantity >= 0),
    bulk_price_pesewas  INTEGER          CHECK (bulk_price_pesewas IS NULL OR bulk_price_pesewas >= 0),

    stock_quantity      INTEGER NOT NULL DEFAULT 0,
    is_active           INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at          TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_products_active ON products (is_active);

CREATE TABLE IF NOT EXISTS product_sizes (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id          INTEGER NOT NULL,
    label               TEXT NOT NULL,
    unit_price_pesewas  INTEGER NOT NULL CHECK(unit_price_pesewas > 0),
    stock_quantity      INTEGER NOT NULL DEFAULT 0,
    bulk_min_quantity   INTEGER NOT NULL DEFAULT 0,
    bulk_price_pesewas  INTEGER,
    sort_order          INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_product_sizes_product ON product_sizes(product_id);

-- ---------------------------------------------------------------------------
-- Customer orders. `reference` is the human-facing tracking code.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    reference           TEXT    NOT NULL UNIQUE,
    order_type          TEXT    NOT NULL CHECK (order_type IN ('single', 'bulk')),

    customer_name       TEXT    NOT NULL,
    customer_phone      TEXT    NOT NULL,
    customer_email      TEXT    NOT NULL DEFAULT '',
    delivery_address    TEXT    NOT NULL,
    region              TEXT    NOT NULL DEFAULT 'Greater Accra',
    notes               TEXT    NOT NULL DEFAULT '',

    subtotal_pesewas    INTEGER NOT NULL DEFAULT 0,
    status              TEXT    NOT NULL DEFAULT 'pending'
                                CHECK (status IN ('pending', 'confirmed', 'processing', 'delivered', 'cancelled')),
    payment_status      TEXT    NOT NULL DEFAULT 'unpaid',
    payment_reference   TEXT    NOT NULL DEFAULT '',
    created_at          TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_orders_reference ON orders (reference);
CREATE INDEX IF NOT EXISTS idx_orders_created   ON orders (created_at);

-- ---------------------------------------------------------------------------
-- Line items. Prices are snapshotted so historical orders stay accurate even
-- if the catalogue price later changes.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_items (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id            INTEGER NOT NULL,
    product_id          INTEGER NOT NULL,
    product_name        TEXT    NOT NULL,
    unit_price_pesewas  INTEGER NOT NULL,
    quantity            INTEGER NOT NULL CHECK (quantity > 0),
    is_bulk             INTEGER NOT NULL DEFAULT 0 CHECK (is_bulk IN (0, 1)),
    line_total_pesewas  INTEGER NOT NULL,

    FOREIGN KEY (order_id)   REFERENCES orders (id)   ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products (id)
);

CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items (order_id);

-- ---------------------------------------------------------------------------
-- Admin users for the control center. Passwords are bcrypt hashes; the first
-- account is seeded by database/migrate.php (which needs PHP's password_hash).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    name          TEXT    NOT NULL DEFAULT 'Administrator',
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- Outgoing Gmail SMTP configuration. The app password is encrypted by PHP
-- before it is stored; the API never returns it to the browser.
CREATE TABLE IF NOT EXISTS smtp_settings (
    id                 INTEGER PRIMARY KEY CHECK (id = 1),
    host               TEXT    NOT NULL DEFAULT 'smtp.gmail.com',
    port               INTEGER NOT NULL DEFAULT 587,
    encryption         TEXT    NOT NULL DEFAULT 'tls',
    sender_name        TEXT    NOT NULL DEFAULT 'Jay fooDs',
    username           TEXT    NOT NULL DEFAULT '',
    notification_email TEXT    NOT NULL DEFAULT '',
    password_encrypted TEXT    NOT NULL DEFAULT '',
    updated_at         TEXT    NOT NULL DEFAULT (datetime('now'))
);

INSERT OR IGNORE INTO smtp_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS paystack_settings (
    id                   INTEGER PRIMARY KEY CHECK (id = 1),
    public_key           TEXT NOT NULL DEFAULT '',
    secret_key_encrypted TEXT NOT NULL DEFAULT '',
    webhook_url          TEXT NOT NULL DEFAULT '',
    updated_at           TEXT NOT NULL DEFAULT (datetime('now'))
);
INSERT OR IGNORE INTO paystack_settings (id) VALUES (1);

-- ---------------------------------------------------------------------------
-- Contact / catering enquiries captured from the public contact form.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    phone      TEXT    NOT NULL DEFAULT '',
    email      TEXT    NOT NULL DEFAULT '',
    message    TEXT    NOT NULL,
    is_read    INTEGER NOT NULL DEFAULT 0 CHECK (is_read IN (0, 1)),
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_messages_read ON messages (is_read);

-- ---------------------------------------------------------------------------
-- Seed the real Jay fooDs catalogue (idempotent via INSERT OR IGNORE on SKU).
-- Prices in pesewas: 2000 = GH₵20.00 — adjust to real pricing as needed.
-- ---------------------------------------------------------------------------
INSERT OR IGNORE INTO products
    (sku, name, flavour, description, unit_label, image_url, unit_price_pesewas, bulk_available, bulk_min_quantity, bulk_price_pesewas, stock_quantity)
VALUES
    ('JF-ORNG-500', 'Orange Juice',     'Orange',            '100% natural freshly squeezed orange juice. No added sugar or preservatives.',                 '500ml bottle', '/img/orange-juice.jpg',     2000, 1, 24, 1700, 400),
    ('JF-WMEL-500', 'Watermelon Juice', 'Watermelon',        'Freshly squeezed watermelon — light, hydrating and 100% natural.',                             '500ml bottle', '/img/watermelon-juice.jpg', 2000, 1, 24, 1700, 320),
    ('JF-ORPN-500', 'Orapine Juice',    'Orange & Pineapple','Our signature orange and pineapple blend — sweet, tangy and refreshing.',                      '500ml bottle', '/img/orapine-juice.jpg',    2200, 1, 24, 1900, 300),
    ('JF-DTOX-350', 'Detox Delight',    'Tigernut Blend',    'A wholesome detox blend of tigernut, dates, coconut and banana. Naturally creamy and filling.', '350ml bottle', '/img/detox-delight.jpg',    2800, 1, 24, 2400, 240);
