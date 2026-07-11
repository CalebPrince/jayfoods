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

    public static function sendLowStockAlerts(): int
    {
        $db=Database::connection();$settings=[];foreach($db->query("SELECT content_key,value FROM site_content WHERE content_key IN ('low_stock_alerts_enabled','low_stock_threshold')")->fetchAll() as $r)$settings[$r['content_key']]=$r['value'];if(($settings['low_stock_alerts_enabled']??'1')!=='1')return 0;$threshold=max(0,min(100000,(int)($settings['low_stock_threshold']??10)));
        $db->prepare('DELETE FROM low_stock_alerts WHERE size_id IN (SELECT id FROM product_sizes WHERE stock_quantity>:threshold OR is_active=0)')->execute([':threshold'=>$threshold]);$q=$db->prepare('SELECT ps.id,p.name,ps.label,ps.stock_quantity FROM product_sizes ps JOIN products p ON p.id=ps.product_id LEFT JOIN low_stock_alerts a ON a.size_id=ps.id WHERE ps.is_active=1 AND p.is_active=1 AND ps.stock_quantity<=:threshold AND a.size_id IS NULL ORDER BY ps.stock_quantity,p.name');$q->execute([':threshold'=>$threshold]);$rows=$q->fetchAll();if(!$rows)return 0;$mailer=new SmtpMailer();if(!$mailer->isConfigured()||!$mailer->notificationEmail())return 0;$items='';foreach($rows as $r)$items.='<tr><td style="padding:8px;border-bottom:1px solid #ddd">'.htmlspecialchars($r['name'].' — '.$r['label'],ENT_QUOTES,'UTF-8').'</td><td style="padding:8px;border-bottom:1px solid #ddd;text-align:center"><b>'.(int)$r['stock_quantity'].'</b></td></tr>';$html='<!doctype html><html><body style="font-family:Arial,sans-serif;color:#173c25"><h2 style="color:#176b3a">Low-stock alert</h2><p>The following active bottle sizes have reached '.$threshold.' units or fewer.</p><table style="width:100%;border-collapse:collapse"><tr><th align="left">Product</th><th>Remaining</th></tr>'.$items.'</table><p>Update stock from the Jay Foods admin product page.</p></body></html>';$mailer->send($mailer->notificationEmail(),'Jay Foods low-stock alert',$html);$mark=$db->prepare("INSERT OR REPLACE INTO low_stock_alerts(size_id,alerted_at) VALUES(:id,datetime('now'))");foreach($rows as $r)$mark->execute([':id'=>$r['id']]);return count($rows);
    }
}
