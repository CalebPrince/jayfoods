<?php

declare(strict_types=1);

/**
 * Handles the catalogue read + order intake for Jayfoods.
 *
 * Endpoints (wired in public/index.php):
 *   GET  /api/v1/products              -> listProducts()
 *   POST /api/v1/orders                -> create()
 *   GET  /api/v1/orders/{reference}    -> show()
 *
 * Golden rule: the client is never trusted for money. Prices, bulk
 * eligibility and totals are always recomputed from the database.
 */
final class OrderController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * Public catalogue of active juices.
     */
    public function listProducts(): void
    {
        Inventory::releaseExpired();
        $rows = $this->db->query(
            'SELECT id, sku, name, flavour, description, unit_label, image_url,
                    unit_price_pesewas, bulk_available, bulk_min_quantity,
                    bulk_price_pesewas, stock_quantity
               FROM products
              WHERE is_active = 1
           ORDER BY name ASC'
        )->fetchAll();

        $sizeStmt = $this->db->prepare('SELECT id,label,unit_price_pesewas,stock_quantity,bulk_min_quantity,bulk_price_pesewas FROM product_sizes WHERE product_id=:id AND is_active=1 ORDER BY sort_order,id');
        $products = array_map(static function (array $r) use ($sizeStmt): array {
            $sizeStmt->execute([':id'=>$r['id']]);
            $sizes=array_map(static fn(array $s)=>['id'=>(int)$s['id'],'label'=>$s['label'],'unit_price_pesewas'=>(int)$s['unit_price_pesewas'],'stock_quantity'=>(int)$s['stock_quantity'],'bulk_min_quantity'=>(int)$s['bulk_min_quantity'],'bulk_price_pesewas'=>$s['bulk_price_pesewas']!==null?(int)$s['bulk_price_pesewas']:null],$sizeStmt->fetchAll());
            return [
                'id'                 => (int) $r['id'],
                'sku'                => $r['sku'],
                'name'               => $r['name'],
                'flavour'            => $r['flavour'],
                'description'        => $r['description'],
                'unit_label'         => $r['unit_label'],
                'image_url'          => $r['image_url'],
                'unit_price_pesewas' => (int) $r['unit_price_pesewas'],
                'bulk_available'     => (bool) $r['bulk_available'],
                'bulk_min_quantity'  => (int) $r['bulk_min_quantity'],
                'bulk_price_pesewas' => $r['bulk_price_pesewas'] !== null
                    ? (int) $r['bulk_price_pesewas']
                    : null,
                'in_stock'           => ((int) $r['stock_quantity']) > 0,
                'sizes'              => $sizes,
            ];
        }, $rows);

        Response::json(['data' => $products]);
    }

    /**
     * Create an order from a JSON payload:
     * {
     *   "order_type": "single" | "bulk",
     *   "customer_name": "...", "customer_phone": "...",
     *   "customer_email": "...", "delivery_address": "...",
     *   "region": "...", "notes": "...",
     *   "items": [ { "product_id": 1, "quantity": 24 }, ... ]
     * }
     */
    public function create(): void
    {
        Inventory::releaseExpired();
        $input = json_decode((string) file_get_contents('php://input'), true);

        if (!is_array($input)) {
            Response::json(['error' => 'Invalid JSON body.'], 400);
            return;
        }

        $orderType = ($input['order_type'] ?? '') === 'bulk' ? 'bulk' : 'single';
        $name      = trim((string) ($input['customer_name'] ?? ''));
        $phone     = trim((string) ($input['customer_phone'] ?? ''));
        $email     = trim((string) ($input['customer_email'] ?? ''));
        $address   = trim((string) ($input['delivery_address'] ?? ''));
        $zoneId    = (int) ($input['delivery_zone_id'] ?? 0);
        $region    = '';
        $deliveryFee = 0;
        $notes     = trim((string) ($input['notes'] ?? ''));
        $rawItems  = $input['items'] ?? [];

        // ---- Field validation -------------------------------------------------
        $errors = [];
        if ($name === '')    { $errors['customer_name']    = 'Full name is required.'; }
        if ($phone === '')   { $errors['customer_phone']   = 'Phone number is required.'; }
        if ($address === '') { $errors['delivery_address'] = 'Delivery address is required.'; }
        $zoneStmt=$this->db->prepare('SELECT id,name,fee_pesewas FROM delivery_zones WHERE id=:id AND is_active=1');$zoneStmt->execute([':id'=>$zoneId]);$zone=$zoneStmt->fetch();
        if(!$zone){$errors['delivery_zone_id']='Choose an available delivery zone.';}else{$region=$zone['name'];$deliveryFee=(int)$zone['fee_pesewas'];}
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['customer_email'] = 'A valid email address is required for payment.'; }
        if (!is_array($rawItems) || count($rawItems) === 0) {
            $errors['items'] = 'Add at least one product to your order.';
        }

        if ($errors) {
            Response::json(['error' => 'Validation failed.', 'fields' => $errors], 422);
            return;
        }

        // ---- Normalise & merge requested quantities --------------------------
        $wanted = [];
        foreach ($rawItems as $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            $sizeId = (int) ($item['size_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);
            if ($pid <= 0 || $sizeId <= 0 || $qty <= 0) {
                continue;
            }
            $wanted[$pid . ':' . $sizeId] = ['product_id'=>$pid,'size_id'=>$sizeId,'quantity'=>$qty];
        }

        if (count($wanted) === 0) {
            Response::json(
                ['error' => 'Validation failed.', 'fields' => ['items' => 'No valid line items supplied.']],
                422
            );
            return;
        }

        // ---- Price everything from the DB ------------------------------------
        $productIds=array_values(array_unique(array_column($wanted,'product_id')));
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, name, unit_price_pesewas, bulk_available,
                    bulk_min_quantity, bulk_price_pesewas, is_active
               FROM products
              WHERE id IN ($placeholders)"
        );
        $stmt->execute($productIds);

        $catalogue = [];
        foreach ($stmt->fetchAll() as $row) {
            $catalogue[(int) $row['id']] = $row;
        }

        $lines    = [];
        $subtotal = 0;

        $sizeStmt=$this->db->prepare('SELECT id,label,unit_price_pesewas,stock_quantity,bulk_min_quantity,bulk_price_pesewas FROM product_sizes WHERE id=:size AND product_id=:product AND is_active=1');
        foreach ($wanted as $choice) {
            $pid=$choice['product_id']; $qty=$choice['quantity'];
            if (!isset($catalogue[$pid]) || (int) $catalogue[$pid]['is_active'] !== 1) {
                Response::json(
                    ['error' => "Product #$pid is unavailable.", 'fields' => ['items' => 'One or more products are no longer available.']],
                    422
                );
                return;
            }

            $product   = $catalogue[$pid];
            $sizeStmt->execute([':size'=>$choice['size_id'],':product'=>$pid]); $size=$sizeStmt->fetch();
            if(!$size){ Response::json(['error'=>'The selected bottle size is unavailable.','fields'=>['items'=>'Choose an available bottle size.']],422); return; }
            if((int)$size['stock_quantity']<$qty){ Response::json(['error'=>'Not enough stock for '.$product['name'].' '.$size['label'].'.','fields'=>['items'=>'Reduce the quantity or choose another size.']],422); return; }
            $unitPrice = (int) $size['unit_price_pesewas'];
            $isBulk    = false;

            $bulkEligible = $orderType === 'bulk'
                && (int) $product['bulk_available'] === 1
                && $size['bulk_price_pesewas'] !== null
                && $qty >= (int) $size['bulk_min_quantity'];

            if ($bulkEligible) {
                $unitPrice = (int) $size['bulk_price_pesewas'];
                $isBulk    = true;
            }

            $lineTotal = $unitPrice * $qty;
            $subtotal += $lineTotal;

            $lines[] = [
                'product_id'         => $pid,
                'product_name'       => $product['name'] . ' — ' . $size['label'],
                'size_id'            => (int)$size['id'],
                'unit_price_pesewas' => $unitPrice,
                'quantity'           => $qty,
                'is_bulk'            => $isBulk ? 1 : 0,
                'line_total_pesewas' => $lineTotal,
            ];
        }

        // ---- Persist inside a transaction ------------------------------------
        $reference = $this->generateReference();
        $total = $subtotal + $deliveryFee;

        try {
            $this->db->beginTransaction();

            $orderStmt = $this->db->prepare(
                "INSERT INTO orders
                    (reference, order_type, customer_name, customer_phone, customer_email,
                     delivery_address, region, delivery_zone_id, notes, subtotal_pesewas,
                     delivery_fee_pesewas, total_pesewas, status, stock_state, reservation_expires_at)
                 VALUES
                    (:reference, :order_type, :customer_name, :customer_phone, :customer_email,
                     :delivery_address, :region, :zone_id, :notes, :subtotal,
                     :delivery_fee, :total, :status, :stock_state, datetime('now','+30 minutes'))"
            );
            $orderStmt->execute([
                ':reference'        => $reference,
                ':order_type'       => $orderType,
                ':customer_name'    => $name,
                ':customer_phone'   => $phone,
                ':customer_email'   => $email,
                ':delivery_address' => $address,
                ':region'           => $region,
                ':zone_id'          => $zoneId,
                ':notes'            => $notes,
                ':subtotal'         => $subtotal,
                ':delivery_fee'     => $deliveryFee,
                ':total'            => $total,
                ':status'           => 'pending',
                ':stock_state'      => 'reserved',
            ]);

            $orderId = (int) $this->db->lastInsertId();

            $itemStmt = $this->db->prepare(
                'INSERT INTO order_items
                    (order_id, product_id, size_id, product_name, unit_price_pesewas,
                     quantity, is_bulk, line_total_pesewas)
                 VALUES
                    (:order_id, :product_id, :size_id, :product_name, :unit_price,
                     :quantity, :is_bulk, :line_total)'
            );

            foreach ($lines as $line) {
                $reserve=$this->db->prepare('UPDATE product_sizes SET stock_quantity=stock_quantity-:qty WHERE id=:size AND is_active=1 AND stock_quantity>=:qty');
                $reserve->execute([':qty'=>$line['quantity'],':size'=>$line['size_id']]);
                if($reserve->rowCount()!==1)throw new RuntimeException('Stock changed while checking out. Please review your cart.');
                $itemStmt->execute([
                    ':order_id'     => $orderId,
                    ':product_id'   => $line['product_id'],
                    ':size_id'      => $line['size_id'],
                    ':product_name' => $line['product_name'],
                    ':unit_price'   => $line['unit_price_pesewas'],
                    ':quantity'     => $line['quantity'],
                    ':is_bulk'      => $line['is_bulk'],
                    ':line_total'   => $line['line_total_pesewas'],
                ]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Could not save your order. Please try again.';
            Response::json(['error' => $message], $e instanceof RuntimeException ? 409 : 500);
            return;
        }

        EmailNotifications::orderCreated([
            'reference' => $reference,
            'customer_name' => $name,
            'customer_phone' => $phone,
            'customer_email' => $email,
            'subtotal_pesewas' => $subtotal,
            'delivery_fee_pesewas' => $deliveryFee,
            'total_pesewas' => $total,
        ], $lines);

        Response::json([
            'data' => [
                'reference'        => $reference,
                'order_type'       => $orderType,
                'status'           => 'pending',
                'subtotal_pesewas' => $subtotal,
                'delivery_fee_pesewas' => $deliveryFee,
                'total_pesewas'    => $total,
                'items'            => $lines,
            ],
        ], 201);
    }

    /**
     * Fetch a single order by its public reference (order confirmation / tracking).
     */
    public function track(): void
    {
        Inventory::releaseExpired();
        $input = json_decode((string) file_get_contents('php://input'), true);
        $reference = strtoupper(trim((string) ($input['reference'] ?? '')));
        $phone = preg_replace('/\D+/', '', (string) ($input['phone'] ?? ''));
        if ($reference === '' || strlen($phone) < 9) {
            Response::json(['error' => 'Enter your order reference and phone number.'], 422);
            return;
        }
        $stmt = $this->db->prepare(
            'SELECT id, reference, order_type, customer_name, customer_phone,
                    customer_email, delivery_address, region, notes,
                    subtotal_pesewas, delivery_fee_pesewas, total_pesewas, status,
                    payment_status, stock_state, reservation_expires_at, created_at
               FROM orders
              WHERE reference = :reference'
        );
        $stmt->execute([':reference' => $reference]);
        $order = $stmt->fetch();

        $savedPhone = $order ? preg_replace('/\D+/', '', (string) $order['customer_phone']) : '';
        $phoneTail = substr($phone, -9);
        if (!$order || $phoneTail === '' || substr($savedPhone, -9) !== $phoneTail) {
            Response::json(['error' => 'No order matches those details. Check the reference and phone number.'], 404);
            return;
        }

        $itemStmt = $this->db->prepare(
            'SELECT product_name, unit_price_pesewas, quantity, is_bulk, line_total_pesewas
               FROM order_items
              WHERE order_id = :order_id'
        );
        $itemStmt->execute([':order_id' => (int) $order['id']]);

        Response::json([
            'data' => [
                'reference'        => $order['reference'],
                'order_type'       => $order['order_type'],
                'status'           => $order['status'],
                'payment_status'   => $order['payment_status'],
                'stock_state'      => $order['stock_state'],
                'reservation_expires_at' => $order['reservation_expires_at'],
                'customer_name'    => $order['customer_name'],
                'delivery_address' => $order['delivery_address'],
                'region'           => $order['region'],
                'subtotal_pesewas' => (int) $order['subtotal_pesewas'],
                'delivery_fee_pesewas' => (int) $order['delivery_fee_pesewas'],
                'total_pesewas'    => (int) $order['total_pesewas'],
                'created_at'       => $order['created_at'],
                'items'            => array_map(static function (array $i): array {
                    return [
                        'product_name'       => $i['product_name'],
                        'unit_price_pesewas' => (int) $i['unit_price_pesewas'],
                        'quantity'           => (int) $i['quantity'],
                        'is_bulk'            => (bool) $i['is_bulk'],
                        'line_total_pesewas' => (int) $i['line_total_pesewas'],
                    ];
                }, $itemStmt->fetchAll()),
            ],
        ]);
    }

    private function generateReference(): string
    {
        return 'JF-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}
