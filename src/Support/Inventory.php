<?php
declare(strict_types=1);

final class Inventory
{
    public static function finalize(int $orderId): void
    {
        $s=Database::connection()->prepare("UPDATE orders SET stock_state='committed',reservation_expires_at='' WHERE id=:id AND stock_state='reserved'");
        $s->execute([':id'=>$orderId]);
    }

    public static function releaseOrder(int $orderId): bool
    {
        $db=Database::connection();$owned=!$db->inTransaction();if($owned)$db->beginTransaction();
        try{$promo=$db->prepare('SELECT promo_code FROM orders WHERE id=:id');$promo->execute([':id'=>$orderId]);$promoCode=(string)$promo->fetchColumn();$s=$db->prepare("UPDATE orders SET stock_state='released',reservation_expires_at='' WHERE id=:id AND stock_state='reserved' AND payment_status!='paid'");$s->execute([':id'=>$orderId]);if($s->rowCount()===0){if($owned)$db->commit();return false;}$items=$db->prepare('SELECT size_id,quantity FROM order_items WHERE order_id=:id AND size_id IS NOT NULL');$items->execute([':id'=>$orderId]);$restore=$db->prepare('UPDATE product_sizes SET stock_quantity=stock_quantity+:qty WHERE id=:size');foreach($items->fetchAll() as $item)$restore->execute([':qty'=>(int)$item['quantity'],':size'=>(int)$item['size_id']]);if($promoCode!==''){$release=$db->prepare('UPDATE promo_codes SET used_count=MAX(0,used_count-1) WHERE code=:code');$release->execute([':code'=>$promoCode]);}if($owned)$db->commit();return true;}catch(Throwable $e){if($owned&&$db->inTransaction())$db->rollBack();throw $e;}
    }

    public static function releaseExpired(): int
    {
        $db=Database::connection();$rows=$db->query("SELECT id FROM orders WHERE stock_state='reserved' AND payment_status!='paid' AND reservation_expires_at!='' AND reservation_expires_at<=datetime('now')")->fetchAll();$count=0;foreach($rows as $r)if(self::releaseOrder((int)$r['id']))$count++;return $count;
    }
}
