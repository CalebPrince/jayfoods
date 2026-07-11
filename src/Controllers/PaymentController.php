<?php
declare(strict_types=1);
final class PaymentController
{
    private PDO $db;
    public function __construct() { $this->db = Database::connection(); }

    public function initialize(string $reference): void
    {
        $o = $this->order($reference);
        if (!$o) { Response::json(['error'=>'Order not found.'], 404); return; }
        if (!filter_var($o['customer_email'], FILTER_VALIDATE_EMAIL)) { Response::json(['error'=>'A valid customer email is required for payment.'], 422); return; }
        try {
            $paymentRef = 'PAY-' . preg_replace('/[^A-Za-z0-9.-]/', '', $reference) . '-' . strtoupper(bin2hex(random_bytes(3)));
            $scheme = $this->isHttps() ? 'https' : 'http';
            $callback = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/order.html?payment=callback';
            $result = $this->request('POST', '/transaction/initialize', [
                'email'=>$o['customer_email'], 'amount'=>(string) $o['total_pesewas'], 'currency'=>'GHS',
                'reference'=>$paymentRef, 'callback_url'=>$callback,
                'metadata'=>json_encode(['order_reference'=>$reference], JSON_UNESCAPED_SLASHES),
            ]);
            $u = $this->db->prepare('UPDATE orders SET payment_reference=:p WHERE id=:id');
            $u->execute([':p'=>$paymentRef, ':id'=>$o['id']]);
            Response::json(['data'=>['authorization_url'=>$result['data']['authorization_url'],'reference'=>$paymentRef]]);
        } catch (Throwable $e) { Response::json(['error'=>'Could not start payment: '.$e->getMessage()], 502); }
    }

    public function verify(string $paymentReference): void
    {
        try { $data = $this->request('GET', '/transaction/verify/' . rawurlencode($paymentReference))['data'] ?? []; }
        catch (Throwable $e) { Response::json(['error'=>'Could not verify payment.'], 502); return; }
        $paid = $this->markPaidIfValid($paymentReference, $data);
        Response::json(['data'=>['paid'=>$paid,'status'=>$data['status'] ?? 'unknown']]);
    }

    public function webhook(): void
    {
        $raw = (string) file_get_contents('php://input');
        try { $secret = $this->secret(); } catch (Throwable $e) { http_response_code(503); return; }
        $signature = (string) ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '');
        if ($signature === '' || !hash_equals(hash_hmac('sha512', $raw, $secret), $signature)) { http_response_code(401); return; }
        $event = json_decode($raw, true);
        if (($event['event'] ?? '') === 'charge.success') $this->markPaidIfValid((string) ($event['data']['reference'] ?? ''), $event['data'] ?? []);
        http_response_code(200);
    }

    private function markPaidIfValid(string $ref, array $data): bool
    {
        $s=$this->db->prepare('SELECT id,reference,customer_name,customer_email,subtotal_pesewas,delivery_fee_pesewas,total_pesewas,payment_status FROM orders WHERE payment_reference=:r'); $s->execute([':r'=>$ref]); $o=$s->fetch();
        if (!$o || ($data['status'] ?? '') !== 'success' || (int)($data['amount'] ?? -1) !== (int)$o['total_pesewas'] || ($data['currency'] ?? '') !== 'GHS') return false;
        if ($o['payment_status'] !== 'paid') {
            $u=$this->db->prepare("UPDATE orders SET payment_status='paid' WHERE id=:id AND payment_status!='paid'"); $u->execute([':id'=>$o['id']]);
            if ($u->rowCount() > 0) {
                Inventory::finalize((int)$o['id']);
                $items=$this->db->prepare('SELECT product_name,unit_price_pesewas,quantity,is_bulk,line_total_pesewas FROM order_items WHERE order_id=:id');$items->execute([':id'=>$o['id']]);
                EmailNotifications::paymentConfirmed($o,$items->fetchAll());
            }
        }
        return true;
    }
    private function order(string $ref): ?array { $s=$this->db->prepare('SELECT id,customer_email,subtotal_pesewas,total_pesewas FROM orders WHERE reference=:r');$s->execute([':r'=>$ref]);$r=$s->fetch();return $r?:null; }
    private function secret(): string { $r=$this->db->query('SELECT secret_key_encrypted FROM paystack_settings WHERE id=1')->fetch(); if(empty($r['secret_key_encrypted'])) throw new RuntimeException('Paystack is not configured.'); return SecretBox::decrypt($r['secret_key_encrypted']); }
    private function request(string $method,string $path,?array $body=null): array
    {
        $headers="Authorization: Bearer ".$this->secret()."\r\nContent-Type: application/json\r\n";
        $opts=['http'=>['method'=>$method,'header'=>$headers,'ignore_errors'=>true,'timeout'=>20]]; if($body!==null)$opts['http']['content']=json_encode($body);
        $raw=@file_get_contents('https://api.paystack.co'.$path,false,stream_context_create($opts)); if($raw===false)throw new RuntimeException('Paystack is unreachable.');
        $data=json_decode($raw,true); if(!is_array($data)||empty($data['status']))throw new RuntimeException((string)($data['message']??'Paystack rejected the request.')); return $data;
    }
    private function isHttps(): bool { $v=strtolower((string)($_SERVER['HTTPS']??'')); return ($v!==''&&$v!=='off'&&$v!=='0')||strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https'; }
}
