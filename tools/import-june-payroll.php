<?php
declare(strict_types=1);

use PersonelHakedis\SpreadsheetService;

$source=$argv[1]??'';if(!is_file($source))throw new RuntimeException('Kaynak bordro bulunamadı.');
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';require_once $root.'/src/SpreadsheetService.php';$config=require $root.'/config/personel-hakedis.php';
$db=new PDO($config['dsn'],$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);

$findOrInsert=function(string$select,string$insert,array$selectArgs,array$insertArgs)use($db):int{$q=$db->prepare($select);$q->execute($selectArgs);$id=$q->fetchColumn();if($id)return(int)$id;$db->prepare($insert)->execute($insertArgs);return(int)$db->lastInsertId();};
$unionId=$findOrInsert('SELECT id FROM sendikalar WHERE ad=? LIMIT 1','INSERT INTO sendikalar(ad,aciklama) VALUES(?,?)',['Dosyadan Aktarılan Sendika'],['Dosyadan Aktarılan Sendika','Kaynak dosyada sendika adı bulunmadığı için oluşturulan, düzenlenebilir başlangıç kaydı.']);
$periodId=$findOrInsert('SELECT id FROM tis_donemleri WHERE ad=? AND sendika_id=? LIMIT 1','INSERT INTO tis_donemleri(ad,sendika_id,baslangic_tarihi,bitis_tarihi,aciklama) VALUES(?,?,?,?,?)',['2026 Temizlik Yan Hakları',$unionId],['2026 Temizlik Yan Hakları',$unionId,'2026-01-01','2026-12-31','Haziran 2026 bordrosunun temizlik sayfasından aktarıldı.']);
$contractId=$findOrInsert('SELECT id FROM sozlesmeler WHERE numara=? LIMIT 1','INSERT INTO sozlesmeler(ad,numara,idare,baslangic_tarihi,bitis_tarihi,aciklama) VALUES(?,?,?,?,?,?)',['TEMZLK-3-2026'],['Çiftlikköy Belediyesi Temizlik Hizmet Alımı 2026','TEMZLK-3-2026','Çiftlikköy Belediyesi','2026-01-01','2026-12-31','Bordro işyeri açıklamasından oluşturuldu.']);
$jobId=$findOrInsert('SELECT id FROM is_tanimlari WHERE ad=? LIMIT 1','INSERT INTO is_tanimlari(ad,aciklama) VALUES(?,?)',['Temizlik Hizmetleri'],['Temizlik Hizmetleri','Çiftlikköy Belediyesi temizlik işleri.']);
$db->prepare('INSERT INTO sozlesme_isleri(sozlesme_id,is_id,baslangic_tarihi,bitis_tarihi) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE aktif=1,baslangic_tarihi=VALUES(baslangic_tarihi),bitis_tarihi=VALUES(bitis_tarihi)')->execute([$contractId,$jobId,'2026-01-01','2026-12-31']);
$existing=$db->prepare('SELECT id FROM bordrolar WHERE yil=2026 AND ay=6 AND sozlesme_id=? AND is_id=? AND aktif=1 LIMIT 1');$existing->execute([$contractId,$jobId]);$mode=$existing->fetchColumn()?'update':'version';
$result=(new SpreadsheetService($db,null))->importPayroll($source,['yil'=>2026,'ay'=>6,'sozlesme_id'=>$contractId,'is_id'=>$jobId,'tis_donem_id'=>$periodId,'dosya_adi'=>basename($source),'auto_personnel'=>1,'auto_rights'=>1],$mode);
$result['sozlesme_id']=$contractId;$result['is_id']=$jobId;$result['tis_donem_id']=$periodId;$result['sendika_id']=$unionId;
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),PHP_EOL;
