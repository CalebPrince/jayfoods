<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$src=dirname(__DIR__).'/src';
require $src.'/Support/Config.php';require $src.'/Support/Database.php';require $src.'/Support/Inventory.php';
try{$count=Inventory::releaseExpired();echo "[inventory] Released $count expired reservation(s)\n";}catch(Throwable $e){fwrite(STDERR,'[inventory] Failed: '.$e->getMessage()."\n");exit(1);}
