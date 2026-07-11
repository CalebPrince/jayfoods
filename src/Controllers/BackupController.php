<?php
declare(strict_types=1);
final class BackupController
{
    public function download(): void
    {
        $db=dirname(__DIR__,2).'/database/jayfoods.sqlite';
        if(!is_file($db)){Response::json(['error'=>'Database file not found.'],404);return;}
        if(ob_get_level())ob_end_clean();
        header('Content-Type: application/vnd.sqlite3');
        header('Content-Disposition: attachment; filename="jayfoods-backup-'.gmdate('Y-m-d-His').'.sqlite"');
        header('Content-Length: '.filesize($db));
        header('Cache-Control: no-store, private');
        readfile($db);exit;
    }
}
