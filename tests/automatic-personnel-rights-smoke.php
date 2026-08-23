<?php
declare(strict_types=1);

use PersonelHakedis\SpreadsheetService;

$source=$argv[1]??'';if(!is_file($source))throw new RuntimeException('Kaynak bordro dosyası bulunamadı.');
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';require_once $root.'/src/SpreadsheetService.php';$config=require $root.'/config/personel-hakedis.php';
$db=new PDO($config['dsn'],$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$suffix=bin2hex(random_bytes(5));$ids=['union'=>0,'period'=>0,'contract'=>0,'job'=>0,'payroll'=>0];$personIds=[];
try{
    $db->prepare('INSERT INTO sendikalar(ad) VALUES(?)')->execute(['TEST OTOMATİK '.$suffix]);$ids['union']=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO tis_donemleri(ad,sendika_id,baslangic_tarihi,bitis_tarihi) VALUES(?,?,?,?)')->execute(['TEST TİS '.$suffix,$ids['union'],'2025-01-01','2026-12-31']);$ids['period']=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO sozlesmeler(ad,numara,idare,baslangic_tarihi,bitis_tarihi) VALUES(?,?,?,?,?)')->execute(['TEST SÖZLEŞME '.$suffix,'TEST-'.$suffix,'TEST','2025-01-01','2026-12-31']);$ids['contract']=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO is_tanimlari(ad) VALUES(?)')->execute(['TEST İŞ '.$suffix]);$ids['job']=(int)$db->lastInsertId();$db->prepare('INSERT INTO sozlesme_isleri(sozlesme_id,is_id) VALUES(?,?)')->execute([$ids['contract'],$ids['job']]);
    $service=new SpreadsheetService($db,null);$result=$service->importPayroll($source,['yil'=>2026,'ay'=>6,'sozlesme_id'=>$ids['contract'],'is_id'=>$ids['job'],'tis_donem_id'=>$ids['period'],'dosya_adi'=>basename($source),'auto_personnel'=>1,'auto_rights'=>1],'version');$ids['payroll']=(int)$result['bordro_id'];
    $q=$db->prepare('SELECT personel_id FROM bordro_personelleri WHERE bordro_id=? AND personel_id IS NOT NULL');$q->execute([$ids['payroll']]);$personIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    if((int)$result['toplam_personel']!==45||(int)$result['olusturulan_personel']!==45||(int)$result['eslesen']!==45||count($result['eslesmeyen'])!==0)throw new RuntimeException('Personel otomatik oluşturma sonucu beklenen değerlerde değil.');
    if((int)$result['otomatik_haklar']['hak_kalemi']!==31||(int)$result['otomatik_haklar']['personel_hakki']<1000||(int)$result['otomatik_haklar']['tarihce_satiri']<1000)throw new RuntimeException('Yan hak aktarım sayıları beklenen değerlerde değil: '.json_encode($result['otomatik_haklar'],JSON_UNESCAPED_UNICODE));
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),PHP_EOL;
}finally{
    if($ids['payroll']){$db->prepare('DELETE FROM personel_sendikal_hak_tarihcesi WHERE bordro_id=?')->execute([$ids['payroll']]);$db->prepare('DELETE d FROM puantaj_detaylari d JOIN puantajlar p ON p.id=d.puantaj_id WHERE p.bordro_id=?')->execute([$ids['payroll']]);$db->prepare('DELETE FROM puantajlar WHERE bordro_id=?')->execute([$ids['payroll']]);$db->prepare('DELETE k FROM bordro_kalemleri k JOIN bordro_personelleri bp ON bp.id=k.bordro_personel_id WHERE bp.bordro_id=?')->execute([$ids['payroll']]);$db->prepare('DELETE FROM bordro_personelleri WHERE bordro_id=?')->execute([$ids['payroll']]);$db->prepare('DELETE FROM bordrolar WHERE id=?')->execute([$ids['payroll']]);}
    if($ids['period']){$db->prepare('DELETE FROM personel_donem_haklari WHERE tis_donem_id=?')->execute([$ids['period']]);$db->prepare('DELETE FROM personel_is_gecmisi WHERE tis_donem_id=?')->execute([$ids['period']]);$db->prepare('UPDATE sendikal_haklar SET onceki_hak_id=NULL WHERE tis_donem_id=?')->execute([$ids['period']]);$db->prepare('DELETE FROM sendikal_haklar WHERE tis_donem_id=?')->execute([$ids['period']]);}
    if($personIds){$marks=implode(',',array_fill(0,count($personIds),'?'));$db->prepare("DELETE FROM personeller WHERE id IN ({$marks})")->execute($personIds);}
    if($ids['contract']&&$ids['job'])$db->prepare('DELETE FROM sozlesme_isleri WHERE sozlesme_id=? AND is_id=?')->execute([$ids['contract'],$ids['job']]);
    if($ids['job'])$db->prepare('DELETE FROM is_tanimlari WHERE id=?')->execute([$ids['job']]);if($ids['contract'])$db->prepare('DELETE FROM sozlesmeler WHERE id=?')->execute([$ids['contract']]);if($ids['period'])$db->prepare('DELETE FROM tis_donemleri WHERE id=?')->execute([$ids['period']]);if($ids['union'])$db->prepare('DELETE FROM sendikalar WHERE id=?')->execute([$ids['union']]);
}
