<?php

declare(strict_types=1);

/**
 * Admin order management.
 *   GET   /api/v1/admin/orders         index()
 *   GET   /api/v1/admin/orders/{id}    show()
 *   PATCH /api/v1/admin/orders/{id}    updateStatus()
 */
final class AdminOrderController
{
    private const STATUSES = ['pending', 'confirmed', 'processing', 'delivered', 'cancelled'];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function index(): void
    {
        $rows = $this->db->query(
            'SELECT o.id, o.reference, o.order_type, o.customer_name, o.customer_phone,
                    o.region, o.subtotal_pesewas, o.status, o.payment_status, o.payment_reference, o.created_at,
                    COUNT(oi.id) AS item_count,
                    COALESCE(SUM(oi.quantity), 0) AS unit_count
               FROM orders o
          LEFT JOIN order_items oi ON oi.order_id = o.id
           GROUP BY o.id
           ORDER BY o.created_at DESC, o.id DESC'
        )->fetchAll();

        $data = array_map(static function (array $r): array {
            return [
                'id'               => (int) $r['id'],
                'reference'        => $r['reference'],
                'order_type'       => $r['order_type'],
                'customer_name'    => $r['customer_name'],
                'customer_phone'   => $r['customer_phone'],
                'region'           => $r['region'],
                'subtotal_pesewas' => (int) $r['subtotal_pesewas'],
                'status'           => $r['status'],
                'payment_status'   => $r['payment_status'],
                'payment_reference'=> $r['payment_reference'],
                'item_count'       => (int) $r['item_count'],
                'unit_count'       => (int) $r['unit_count'],
                'created_at'       => $r['created_at'],
            ];
        }, $rows);

        Response::json(['data' => $data]);
    }

    public function show(int $id): void
    {
        $stmt = $this->db->prepare(
            'SELECT id, reference, order_type, customer_name, customer_phone, customer_email,
                    delivery_address, region, notes, subtotal_pesewas, status,
                    payment_status, payment_reference, created_at
               FROM orders WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $order = $stmt->fetch();

        if (!$order) {
            Response::json(['error' => 'Order not found.'], 404);
            return;
        }

        $items = $this->db->prepare(
            'SELECT product_name, unit_price_pesewas, quantity, is_bulk, line_total_pesewas
               FROM order_items WHERE order_id = :id'
        );
        $items->execute([':id' => $id]);

        Response::json([
            'data' => [
                'id'               => (int) $order['id'],
                'reference'        => $order['reference'],
                'order_type'       => $order['order_type'],
                'customer_name'    => $order['customer_name'],
                'customer_phone'   => $order['customer_phone'],
                'customer_email'   => $order['customer_email'],
                'delivery_address' => $order['delivery_address'],
                'region'           => $order['region'],
                'notes'            => $order['notes'],
                'subtotal_pesewas' => (int) $order['subtotal_pesewas'],
                'status'           => $order['status'],
                'payment_status'   => $order['payment_status'],
                'payment_reference'=> $order['payment_reference'],
                'created_at'       => $order['created_at'],
                'items'            => array_map(static function (array $i): array {
                    return [
                        'product_name'       => $i['product_name'],
                        'unit_price_pesewas' => (int) $i['unit_price_pesewas'],
                        'quantity'           => (int) $i['quantity'],
                        'is_bulk'            => (bool) $i['is_bulk'],
                        'line_total_pesewas' => (int) $i['line_total_pesewas'],
                    ];
                }, $items->fetchAll()),
            ],
        ]);
    }

    public function updateStatus(int $id): void
    {
        $input  = json_decode((string) file_get_contents('php://input'), true);
        $status = (string) ($input['status'] ?? '');

        if (!in_array($status, self::STATUSES, true)) {
            Response::json(['error' => 'Invalid status.', 'allowed' => self::STATUSES], 422);
            return;
        }

        $stmt = $this->db->prepare('SELECT id, reference, customer_name, customer_email, status FROM orders WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $order = $stmt->fetch();
        if (!$order) {
            Response::json(['error' => 'Order not found.'], 404);
            return;
        }

        $update = $this->db->prepare('UPDATE orders SET status = :status WHERE id = :id');
        $update->execute([':status' => $status, ':id' => $id]);

        if ($order['status'] !== $status) EmailNotifications::statusChanged($order, $status);

        Response::json(['data' => ['ok' => true, 'status' => $status]]);
    }
}
