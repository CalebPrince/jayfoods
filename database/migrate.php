<?php

declare(strict_types=1);

/**
 * Idempotent database migration. Applies database/schema.sql to the SQLite file.
 * Invoked automatically by server.py on every boot, or run directly:
 *   php database/migrate.php
 */

$root       = dirname(__DIR__);
$dbPath     = $root . '/database/jayfoods.sqlite';
$schemaPath = $root . '/database/schema.sql';

$schema = file_get_contents($schemaPath);
if ($schema === false) {
    fwrite(STDERR, "[migrate] Could not read schema at $schemaPath\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec($schema);
} catch (Throwable $e) {
    fwrite(STDERR, '[migrate] Failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "[migrate] Schema applied to $dbPath\n";

// ---------------------------------------------------------------------------
// Idempotent column additions for databases created before a column existed.
// (CREATE TABLE IF NOT EXISTS does not alter existing tables.)
// ---------------------------------------------------------------------------
$productCols = array_column($pdo->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('image_url', $productCols, true)) {
    $pdo->exec("ALTER TABLE products ADD COLUMN image_url TEXT NOT NULL DEFAULT ''");
    echo "[migrate] Added products.image_url column\n";
}
$sizeCols = array_column($pdo->query('PRAGMA table_info(product_sizes)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('is_active', $sizeCols, true)) {
    $pdo->exec("ALTER TABLE product_sizes ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1");
    echo "[migrate] Added product_sizes.is_active\n";
}

$orderCols = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('payment_status', $orderCols, true)) {
    $pdo->exec("ALTER TABLE orders ADD COLUMN payment_status TEXT NOT NULL DEFAULT 'unpaid'");
    echo "[migrate] Added orders.payment_status\n";
}
if (!in_array('payment_reference', $orderCols, true)) {
    $pdo->exec("ALTER TABLE orders ADD COLUMN payment_reference TEXT NOT NULL DEFAULT ''");
    echo "[migrate] Added orders.payment_reference\n";
}
if (!in_array('delivery_zone_id', $orderCols, true)) {
    $pdo->exec("ALTER TABLE orders ADD COLUMN delivery_zone_id INTEGER");
    echo "[migrate] Added orders.delivery_zone_id\n";
}
if (!in_array('delivery_fee_pesewas', $orderCols, true)) {
    $pdo->exec("ALTER TABLE orders ADD COLUMN delivery_fee_pesewas INTEGER NOT NULL DEFAULT 0");
    echo "[migrate] Added orders.delivery_fee_pesewas\n";
}
if (!in_array('total_pesewas', $orderCols, true)) {
    $pdo->exec("ALTER TABLE orders ADD COLUMN total_pesewas INTEGER NOT NULL DEFAULT 0");
    $pdo->exec("UPDATE orders SET total_pesewas = subtotal_pesewas WHERE total_pesewas = 0");
    echo "[migrate] Added orders.total_pesewas\n";
}
if (!in_array('promo_code', $orderCols, true)) {$pdo->exec("ALTER TABLE orders ADD COLUMN promo_code TEXT NOT NULL DEFAULT ''");echo "[migrate] Added orders.promo_code\n";}
if (!in_array('admin_notes', $orderCols, true)) {$pdo->exec("ALTER TABLE orders ADD COLUMN admin_notes TEXT NOT NULL DEFAULT ''");echo "[migrate] Added orders.admin_notes\n";}
if (!in_array('discount_pesewas', $orderCols, true)) {$pdo->exec("ALTER TABLE orders ADD COLUMN discount_pesewas INTEGER NOT NULL DEFAULT 0");echo "[migrate] Added orders.discount_pesewas\n";}
if (!in_array('stock_state', $orderCols, true)) {
    $pdo->exec("ALTER TABLE orders ADD COLUMN stock_state TEXT NOT NULL DEFAULT 'none'");
    echo "[migrate] Added orders.stock_state\n";
}
if (!in_array('reservation_expires_at', $orderCols, true)) {
    $pdo->exec("ALTER TABLE orders ADD COLUMN reservation_expires_at TEXT NOT NULL DEFAULT ''");
    echo "[migrate] Added orders.reservation_expires_at\n";
}
$itemCols = array_column($pdo->query('PRAGMA table_info(order_items)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('size_id', $itemCols, true)) {
    $pdo->exec("ALTER TABLE order_items ADD COLUMN size_id INTEGER");
    echo "[migrate] Added order_items.size_id\n";
}
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_orders_payment_reference ON orders(payment_reference) WHERE payment_reference != ''");
$pdo->exec("INSERT INTO order_status_history(order_id,status,note,created_at) SELECT o.id,o.status,'Existing order',o.created_at FROM orders o WHERE NOT EXISTS(SELECT 1 FROM order_status_history h WHERE h.order_id=o.id)");

$pdo->exec(
    "INSERT INTO product_sizes(product_id,label,unit_price_pesewas,stock_quantity,bulk_min_quantity,bulk_price_pesewas)
     SELECT p.id,p.unit_label,p.unit_price_pesewas,p.stock_quantity,p.bulk_min_quantity,p.bulk_price_pesewas
       FROM products p WHERE NOT EXISTS(SELECT 1 FROM product_sizes s WHERE s.product_id=p.id)"
);
$adminCols=array_column($pdo->query('PRAGMA table_info(admins)')->fetchAll(PDO::FETCH_ASSOC),'name');if(!in_array('is_active',$adminCols,true)){$pdo->exec('ALTER TABLE admins ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');echo "[migrate] Added admins.is_active\n";}

// ---------------------------------------------------------------------------
// Ensure a signing key exists for JWT (HS256). Generated once, kept out of VCS.
// ---------------------------------------------------------------------------
$keyPath = $root . '/database/app.key';
if (!is_file($keyPath)) {
    file_put_contents($keyPath, bin2hex(random_bytes(32)));
    @chmod($keyPath, 0600);
    echo "[migrate] Generated JWT signing key at database/app.key\n";
}

// ---------------------------------------------------------------------------
// Seed a default admin account on first run only.
// ---------------------------------------------------------------------------
$adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
if ($adminCount === 0) {
    $email    = 'admin@jayfoods.gh';
    $password = 'changeme123';
    $stmt = $pdo->prepare(
        'INSERT INTO admins (email, name, password_hash) VALUES (:email, :name, :hash)'
    );
    $stmt->execute([
        ':email' => $email,
        ':name'  => 'Jay fooDs Admin',
        ':hash'  => password_hash($password, PASSWORD_DEFAULT),
    ]);
    echo "[migrate] Seeded default admin:\n";
    echo "[migrate]   email:    $email\n";
    echo "[migrate]   password: $password   <-- CHANGE THIS after first login (Settings)\n";
}
