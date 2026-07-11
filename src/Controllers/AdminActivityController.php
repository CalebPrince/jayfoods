<?php
declare(strict_types=1);
final class AdminActivityController
{
    public function index():void
    {
        $rows=Database::connection()->query('SELECT id,admin_name,method,path,ip_address,created_at FROM admin_activity ORDER BY id DESC LIMIT 250')->fetchAll();
        Response::json(['data'=>array_map(static fn($r)=>['id'=>(int)$r['id'],'admin_name'=>$r['admin_name'],'method'=>$r['method'],'path'=>$r['path'],'ip_address'=>$r['ip_address'],'created_at'=>$r['created_at']],$rows)]);
    }
    public static function record(string $method,string $path):void
    {
        $user=Auth::user();if(!$user)return;$ip=trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_FOR']??$_SERVER['REMOTE_ADDR']??''))[0]);
        $s=Database::connection()->prepare('INSERT INTO admin_activity(admin_id,admin_name,method,path,ip_address) VALUES(:id,:name,:method,:path,:ip)');$s->execute([':id'=>$user['id'],':name'=>$user['name'],':method'=>$method,':path'=>$path,':ip'=>substr($ip,0,80)]);
    }
}
