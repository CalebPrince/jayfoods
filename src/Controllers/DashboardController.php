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
        $lowStockSizes  = (int) $db->query('SELECT COUNT(*) FROM product_sizes ps JOIN products p ON p.id=ps.product_id WHERE ps.is_active=1 AND p.is_active=1 AND ps.stock_quantity<=10')->fetchColumn();
        $reservedOrders = (int) $db->query("SELECT COUNT(*) FROM orders WHERE stock_state='reserved' AND payment_status!='paid'")->fetchColumn();
        $salesToday     = (int) $db->query("SELECT COALESCE(SUM(total_pesewas),0) FROM orders WHERE payment_status='paid' AND status!='cancelled' AND date(created_at)=date('now')")->fetchColumn();
        $sales7Days     = (int) $db->query("SELECT COALESCE(SUM(total_pesewas),0) FROM orders WHERE payment_status='paid' AND status!='cancelled' AND created_at>=datetime('now','-7 days')")->fetchColumn();
        $sales30Days    = (int) $db->query("SELECT COALESCE(SUM(total_pesewas),0) FROM orders WHERE payment_status='paid' AND status!='cancelled' AND created_at>=datetime('now','-30 days')")->fetchColumn();
        $averageOrder   = (int) round((float) $db->query("SELECT COALESCE(AVG(total_pesewas),0) FROM orders WHERE payment_status='paid' AND status!='cancelled'")->fetchColumn());
        $topProducts = array_map(static fn(array $r): array => ['name'=>$r['name'],'units'=>(int)$r['units'],'sales_pesewas'=>(int)$r['sales_pesewas']], $db->query(
            "SELECT p.name,SUM(oi.quantity) AS units,SUM(oi.line_total_pesewas) AS sales_pesewas FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN products p ON p.id=oi.product_id WHERE o.payment_status='paid' AND o.status!='cancelled' GROUP BY p.id,p.name ORDER BY units DESC,sales_pesewas DESC LIMIT 5"
        )->fetchAll());

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
                'low_stock_sizes'  => $lowStockSizes,
                'reserved_orders'  => $reservedOrders,
                'sales_today_pesewas' => $salesToday,
                'sales_7_days_pesewas' => $sales7Days,
                'sales_30_days_pesewas' => $sales30Days,
                'average_order_pesewas' => $averageOrder,
                'top_products'     => $topProducts,
                'recent_orders'    => $recentOrders,
            ],
        ]);
    }
}
