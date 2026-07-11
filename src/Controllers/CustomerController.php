<?php
declare(strict_types=1);
final class CustomerController
{
    public function index(): void
    {
        $rows=Database::connection()->query("SELECT customer_phone,MAX(customer_name) AS customer_name,MAX(customer_email) AS customer_email,MAX(region) AS region,COUNT(*) AS order_count,SUM(CASE WHEN payment_status='paid' AND status!='cancelled' THEN total_pesewas ELSE 0 END) AS lifetime_value_pesewas,MAX(created_at) AS last_order_at FROM orders GROUP BY customer_phone ORDER BY last_order_at DESC")->fetchAll();
        Response::json(['data'=>array_map(static fn(array $r):array=>['customer_phone'=>$r['customer_phone'],'customer_name'=>$r['customer_name'],'customer_email'=>$r['customer_email'],'region'=>$r['region'],'order_count'=>(int)$r['order_count'],'lifetime_value_pesewas'=>(int)$r['lifetime_value_pesewas'],'last_order_at'=>$r['last_order_at']],$rows)]);
    }
}
