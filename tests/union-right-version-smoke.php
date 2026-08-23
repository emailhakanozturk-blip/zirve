<?php
declare(strict_types=1);

use PersonelHakedis\ModuleService;

$root=dirname(__DIR__);
require_once $root.'/src/ModuleService.php';
$config=require $root.'/config/personel-hakedis.php';
$db=new PDO($config['dsn'],$config['user'],$config['password'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$service=new ModuleService($db,null,'127.0.0.1');
$suffix=bin2hex(random_bytes(5));$unionId=0;$periodId=0;

try{
    $db->prepare('INSERT INTO sendikalar(ad,aciklama) VALUES(?,?)')->execute(['TEST SENDİKA '.$suffix,'Sürüm testi']);
    $unionId=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO tis_donemleri(ad,sendika_id,baslangic_tarihi,bitis_tarihi) VALUES(?,?,?,?)')->execute(['TEST TİS '.$suffix,$unionId,'2025-01-01','2026-12-31']);
    $periodId=(int)$db->lastInsertId();
    $count=$service->seedUnionRights($periodId,'2025-01-01');
    if($count!==31)throw new RuntimeException("31 hak bekleniyordu, {$count} geldi.");
    $q=$db->prepare("SELECT * FROM sendikal_haklar WHERE tis_donem_id=? AND hak_adi='AİLE YARDIMI'");$q->execute([$periodId]);$old=$q->fetch();
    if(!$old)throw new RuntimeException('AİLE YARDIMI bulunamadı.');
    $newId=$service->save('sendikal_haklar',[
        'id'=>$old['id'],'tis_donem_id'=>$periodId,'hak_adi'=>$old['hak_adi'],'bordro_kalem_adi'=>$old['bordro_kalem_adi'],
        'birim'=>$old['birim'],'birim_fiyat'=>'3500.00','hesaplama_sekli'=>'bordro_tutari','hakedise_dahil'=>1,
        'gecerlilik_baslangic'=>'2026-07-01','gecerlilik_bitis'=>'','aktif'=>1,
    ]);
    $oldNow=$db->query('SELECT * FROM sendikal_haklar WHERE id='.(int)$old['id'])->fetch();
    $new=$db->query('SELECT * FROM sendikal_haklar WHERE id='.(int)$newId)->fetch();
    if($oldNow['gecerlilik_bitis']!=='2026-06-30')throw new RuntimeException('Eski sürüm doğru tarihte kapanmadı.');
    if((int)$new['surum']!==2||(int)$new['onceki_hak_id']!==(int)$old['id']||$new['hak_grup_kodu']!==$old['hak_grup_kodu'])throw new RuntimeException('Sürüm zinciri hatalı.');
    echo json_encode(['hak_sayisi'=>$count,'eski_bitis'=>$oldNow['gecerlilik_bitis'],'yeni_surum'=>(int)$new['surum']],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
}finally{
    if($periodId){$db->prepare('DELETE FROM personel_donem_haklari WHERE tis_donem_id=?')->execute([$periodId]);$db->prepare('UPDATE sendikal_haklar SET onceki_hak_id=NULL WHERE tis_donem_id=?')->execute([$periodId]);$db->prepare('DELETE FROM sendikal_haklar WHERE tis_donem_id=?')->execute([$periodId]);$db->prepare('DELETE FROM tis_donemleri WHERE id=?')->execute([$periodId]);}
    if($unionId)$db->prepare('DELETE FROM sendikalar WHERE id=?')->execute([$unionId]);
}
