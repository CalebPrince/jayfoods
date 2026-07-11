<?php
declare(strict_types=1);

final class DeliveryZoneController
{
    private PDO $db;
    public function __construct() { $this->db=Database::connection(); }
    public function publicIndex(): void { Response::json(['data'=>$this->rows(true)]); }
    public function adminIndex(): void { Response::json(['data'=>$this->rows(false)]); }
    public function save(): void
    {
        $in=json_decode((string)file_get_contents('php://input'),true);$in=is_array($in)?$in:[];$zones=$in['zones']??[];
        if(!is_array($zones)||!$zones){Response::json(['error'=>'Add at least one delivery zone.'],422);return;}
        $seen=[];$clean=[];
        foreach($zones as $i=>$z){$name=trim((string)($z['name']??''));$fee=(int)($z['fee_pesewas']??-1);if($name===''||$fee<0){Response::json(['error'=>'Every zone needs a name and a valid fee.'],422);return;}if(isset($seen[strtolower($name)])){Response::json(['error'=>'Delivery zone names must be unique.'],422);return;}$seen[strtolower($name)]=true;$clean[]=['id'=>(int)($z['id']??0),'name'=>$name,'fee'=>$fee,'active'=>!empty($z['is_active'])?1:0,'sort'=>$i];}
        $this->db->beginTransaction();
        try{$ids=[];foreach($clean as $z){if($z['id']>0){$s=$this->db->prepare("UPDATE delivery_zones SET name=:name,fee_pesewas=:fee,is_active=:active,sort_order=:sort,updated_at=datetime('now') WHERE id=:id");$s->execute([':name'=>$z['name'],':fee'=>$z['fee'],':active'=>$z['active'],':sort'=>$z['sort'],':id'=>$z['id']]);$ids[]=$z['id'];}else{$s=$this->db->prepare('INSERT INTO delivery_zones(name,fee_pesewas,is_active,sort_order) VALUES(:name,:fee,:active,:sort)');$s->execute([':name'=>$z['name'],':fee'=>$z['fee'],':active'=>$z['active'],':sort'=>$z['sort']]);$ids[]=(int)$this->db->lastInsertId();}}if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$s=$this->db->prepare("DELETE FROM delivery_zones WHERE id NOT IN ($marks)");$s->execute($ids);}$this->db->commit();}catch(Throwable $e){$this->db->rollBack();Response::json(['error'=>'Could not save delivery zones. Check for duplicate names.'],422);return;}
        Response::json(['data'=>$this->rows(false)]);
    }
    private function rows(bool $active): array
    {
        $sql='SELECT id,name,fee_pesewas,is_active FROM delivery_zones'.($active?' WHERE is_active=1':'').' ORDER BY sort_order,name';
        return array_map(static fn(array $r)=>['id'=>(int)$r['id'],'name'=>$r['name'],'fee_pesewas'=>(int)$r['fee_pesewas'],'is_active'=>(bool)$r['is_active']],$this->db->query($sql)->fetchAll());
    }
}
