<?php

declare(strict_types=1);

/**
 * Aggregate stats for the admin dashboard overview.
 *   GET /api/v1/admin/stats
 */
final class DashboardController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function stats(): void
    {
        $db = $this->db;

        $ordersTotal    = (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $ordersPending  = (int) $db->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
        $revenue        = (int) $db->query("SELECT COALESCE(SUM(total_pesewas), 0) FROM orders WHERE payment_status = 'paid' AND status != 'cancelled'")->fetchColumn();
        $ordersPaid     = (int) $db->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'paid'")->fetchColumn();
        $productsTotal  = (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $productsActive = (int) $db->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();
        $messagesUnread = (int) $db->query('SELECT COUNT(*) FROM messages WHERE is_read = 0')->fetchColumn();

        $recent = $db->query(
            'SELECT reference, order_type, customer_name, subtotal_pesewas, total_pesewas, status, payment_status, created_at
               FROM orders
           ORDER BY created_at DESC, id DESC
              LIMIT 5'
        )->fetchAll();

        $recentOrders = array_map(static function (array $r): array {
            return [
                'reference'        => $r['reference'],
                'order_type'       => $r['order_type'],
                'customer_name'    => $r['customer_name'],
                'subtotal_pesewas' => (int) $r['subtotal_pesewas'],
                'total_pesewas'    => (int) $r['total_pesewas'],
                'status'           => $r['status'],
                'payment_status'   => $r['payment_status'],
                'created_at'       => $r['created_at'],
            ];
        }, $recent);

        Response::json([
            'data' => [
                'orders_total'     => $ordersTotal,
                'orders_pending'   => $ordersPending,
                'orders_paid'      => $ordersPaid,
                'revenue_pesewas'  => $revenue,
                'products_total'   => $productsTotal,
                'products_active'  => $productsActive,
                'messages_unread'  => $messagesUnread,
                'recent_orders'    => $recentOrders,
            ],
        ]);
    }
}
