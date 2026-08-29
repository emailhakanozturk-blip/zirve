<?php
declare(strict_types=1);

namespace PersonelHakedis;

use PDO;
use RuntimeException;
use Throwable;

final class SpreadsheetService
{
    private const TIMESHEET_MAP = [
        'normal gun'=>'normal_gun','normal calisma'=>'normal_gun','hafta tatili'=>'hafta_tatili',
        'fazla mesai'=>'fazla_mesai','rapor'=>'rapor','ucretli izin'=>'ucretli_izin','ucretsiz izin'=>'ucretsiz_izin',
        'resmi tatil'=>'resmi_tatil','tatil mesaisi'=>'resmi_tatil','gece mesaisi'=>'gece_mesaisi',
        'yol'=>'yol_gunu','yol gunu'=>'yol_gunu','yemek'=>'yemek_gunu','yemek gunu'=>'yemek_gunu','prim gunu'=>'prim_gunu'
    ];

    public function __construct(private PDO $db,private ?int $userId){}

    public function importPayroll(string $file,array $meta,string $mode='version'):array
    {
        $this->ensureLibrary();$sheets=$this->readSheets($file);$rows=[];foreach($sheets as$sheetRows)if($this->isDetailedPayroll($sheetRows)){$rows=$sheetRows;break;}if(!$rows)$rows=reset($sheets)?:[];if(count($rows)<2)throw new RuntimeException('Excel dosyasında veri satırı yok.');
        if($this->isDetailedPayroll($rows))return $this->importDetailedPayroll($file,$rows,$meta,$mode,$this->sheetByName($sheets,'temizlik'));
        $headers=array_map([$this,'normalize'],array_shift($rows));$this->requireHeaders($headers,['ad soyad'],['tc kimlik no','sgk sicil no']);
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare("SELECT * FROM bordrolar WHERE yil=? AND ay=? AND sozlesme_id=? AND is_id=? AND aktif=1 ORDER BY surum DESC LIMIT 1");$q->execute([$meta['yil'],$meta['ay'],$meta['sozlesme_id'],$meta['is_id']]);$old=$q->fetch();
            if($old&&$mode==='cancel')throw new RuntimeException('Yükleme kullanıcı tarafından iptal edildi.');
            if($old&&$mode==='update'){$this->db->prepare("DELETE k FROM puantaj_karsilastirmalari k JOIN puantajlar p ON p.id=k.puantaj_id WHERE p.bordro_id=?")->execute([$old['id']]);$this->db->prepare("DELETE d FROM puantaj_detaylari d JOIN puantajlar p ON p.id=d.puantaj_id WHERE p.bordro_id=?")->execute([$old['id']]);$this->db->prepare("DELETE FROM puantajlar WHERE bordro_id=?")->execute([$old['id']]);$this->db->prepare("DELETE bk FROM bordro_kalemleri bk JOIN bordro_personelleri bp ON bp.id=bk.bordro_personel_id WHERE bp.bordro_id=?")->execute([$old['id']]);$this->db->prepare("DELETE FROM bordro_personelleri WHERE bordro_id=?")->execute([$old['id']]);$id=(int)$old['id'];$version=(int)$old['surum'];$this->db->prepare("UPDATE bordrolar SET tis_donem_id=?,dosya_adi=?,dosya_hash=?,durum='isleniyor' WHERE id=?")->execute([$meta['tis_donem_id'],basename($file),hash_file('sha256',$file),$id]);}
            else{$version=$old?(int)$old['surum']+1:1;if($old)$this->db->prepare("UPDATE bordrolar SET aktif=0 WHERE id=?")->execute([$old['id']]);$this->db->prepare("INSERT INTO bordrolar(yil,ay,sozlesme_id,is_id,tis_donem_id,surum,onceki_bordro_id,dosya_adi,dosya_hash,yukleyen_kullanici_id) VALUES(?,?,?,?,?,?,?,?,?,?)")->execute([$meta['yil'],$meta['ay'],$meta['sozlesme_id'],$meta['is_id'],$meta['tis_donem_id'],$version,$old['id']??null,basename($file),hash_file('sha256',$file),$this->userId]);$id=(int)$this->db->lastInsertId();}
            $matched=0;$unmatched=[];
            foreach($rows as$index=>$row){$record=[];foreach($headers as$i=>$h)$record[$h]=$row[$i]??null;if(!array_filter($record,fn($v)=>$v!==null&&$v!==''))continue;
                $person=$this->matchPerson((string)($record['tc kimlik no']??''),(string)($record['sgk sicil no']??''));$status=$person?'eslesti':'eslesmedi';
                $this->db->prepare("INSERT INTO bordro_personelleri(bordro_id,personel_id,satir_no,tc_kimlik_no,sgk_sicil_no,ad_soyad,eslesme_durumu) VALUES(?,?,?,?,?,?,?)")->execute([$id,$person['id']??null,$index+2,$record['tc kimlik no']??null,$record['sgk sicil no']??null,$record['ad soyad']??'Bilinmiyor',$status]);$bp=(int)$this->db->lastInsertId();
                if($person)$matched++;else$unmatched[]=['satir'=>$index+2,'ad_soyad'=>$record['ad soyad']??'','tc'=>$record['tc kimlik no']??'','sgk'=>$record['sgk sicil no']??''];
                foreach($record as$name=>$value){if(in_array($name,['ad soyad','tc kimlik no','sgk sicil no'],true)||$value===''||$value===null)continue;[$amount,$money]=$this->parseCell($value);$this->db->prepare("INSERT INTO bordro_kalemleri(bordro_personel_id,kalem_adi,miktar,tutar,kaynak_sutun) VALUES(?,?,?,?,?)")->execute([$bp,$name,$amount,$money,$name]);}
                if($person)$this->createTimesheet($id,(int)$person['id'],$meta,$bp);
            }
            $this->db->prepare("UPDATE bordrolar SET durum='tamamlandi' WHERE id=?")->execute([$id]);$this->db->commit();return['bordro_id'=>$id,'surum'=>$version,'eslesen'=>$matched,'eslesmeyen'=>$unmatched];
        }catch(Throwable$e){$this->db->rollBack();throw$e;}
    }

    public function importPayrollByJobs(string$file,array$meta,string$mode='update'):array
    {
        $this->ensureLibrary();$sheets=$this->readSheets($file);$rows=[];foreach($sheets as$sheetRows)if($this->isDetailedPayroll($sheetRows)){$rows=$sheetRows;break;}if(!$rows)throw new RuntimeException('Toplu iş bazlı yükleme için detaylı bordro biçimi gereklidir.');
        $period=$this->detailedPayrollPeriod($rows);if(!$period)throw new RuntimeException('Bordro dönemi dosyadan okunamadı.');if((int)($meta['yil']??0)!==$period['yil']||(int)($meta['ay']??0)!==$period['ay'])throw new RuntimeException("Excel dönemi {$period['ay']}/{$period['yil']}; seçilen dönem ".($meta['ay']??'').'/'.($meta['yil']??'').'.');
        $fallback=['sozlesme_id'=>(int)($meta['sozlesme_id']??0),'is_id'=>(int)($meta['is_id']??0),'tis_donem_id'=>(int)($meta['tis_donem_id']??0)];if(min($fallback)<1)throw new RuntimeException('Yeni veya iş ataması bulunmayan personeller için sözleşme, iş ve TİS dönemi seçilmelidir.');
        $records=$this->mergeDetailedPayrollRecords($this->detailedPayrollRecords($rows));if(!$records)throw new RuntimeException('Detaylı bordroda personel kaydı bulunamadı.');$replace=!isset($meta['replace_period'])||(string)$meta['replace_period']!=='0';$autoPersonnel=!isset($meta['auto_personnel'])||(string)$meta['auto_personnel']!=='0';
        $this->db->beginTransaction();
        try{
            $removed=['bordro'=>0,'hakedis'=>0];$groups=[];$created=0;$matched=0;$unmatched=[];
            foreach($records as$record){$person=$this->matchPerson($record['tc_kimlik_no'],$record['sgk_sicil_no']);if(!$person&&$autoPersonnel){$person=$this->createPersonFromPayroll($record,$meta);$created++;}if(!$person){$unmatched[]=['satir'=>$record['satir_no'],'ad_soyad'=>$record['ad_soyad'],'tc'=>$record['tc_kimlik_no'],'sgk'=>$record['sgk_sicil_no']];continue;}$matched++;
                $assignment=$this->personnelAssignmentForPeriod((int)$person['id'],$period['yil'],$period['ay'])??$fallback;$groupKey=$assignment['sozlesme_id'].'-'.$assignment['is_id'].'-'.$assignment['tis_donem_id'];$groupMeta=array_merge($meta,$assignment,['yil'=>$period['yil'],'ay'=>$period['ay'],'dosya_adi'=>basename((string)($meta['dosya_adi']??basename($file))) ]);$this->ensurePersonnelAssignment((int)$person['id'],$record,$groupMeta);$groups[$groupKey]['meta']=$groupMeta;$groups[$groupKey]['records'][]=['record'=>$record,'person_id'=>(int)$person['id']];
            }
            if($unmatched)throw new RuntimeException(count($unmatched).' personel kartıyla eşleştirilemedi. Eksik personelleri otomatik oluştur seçeneğini açık bırakın.');
            if($replace)$removed=$this->deletePayrollPeriod($period['yil'],$period['ay'],array_column($groups,'meta'));
            $payrolls=[];foreach($groups as$group){$groupMeta=$group['meta'];[$payrollId,$version]=$this->preparePayroll($file,$groupMeta,$mode);foreach($group['records']as$entry){$record=$entry['record'];$personId=$entry['person_id'];$this->db->prepare("INSERT INTO bordro_personelleri(bordro_id,personel_id,satir_no,tc_kimlik_no,sgk_sicil_no,ad_soyad,eslesme_durumu) VALUES(?,?,?,?,?,?,'eslesti')")->execute([$payrollId,$personId,$record['satir_no'],$record['tc_kimlik_no'],$record['sgk_sicil_no']?:null,$record['ad_soyad']]);$bp=(int)$this->db->lastInsertId();$insert=$this->db->prepare("INSERT INTO bordro_kalemleri(bordro_personel_id,kalem_adi,miktar,tutar,kaynak_sutun) VALUES(?,?,?,?,?)");foreach($record['kalemler']as$item)$insert->execute([$bp,$item['ad'],$item['miktar'],$item['tutar'],$item['kaynak']]);$this->createTimesheet($payrollId,$personId,$groupMeta,$bp);}
                $cost=$this->detailedRecordsCostSummary(array_column($group['records'],'record'));$this->savePayrollCostSummary($payrollId,$cost);$this->db->prepare("UPDATE bordrolar SET durum='tamamlandi' WHERE id=?")->execute([$payrollId]);$payrolls[]=['bordro_id'=>$payrollId,'surum'=>$version,'sozlesme_id'=>(int)$groupMeta['sozlesme_id'],'is_id'=>(int)$groupMeta['is_id'],'tis_donem_id'=>(int)$groupMeta['tis_donem_id'],'personel'=>count($group['records']),'maliyet'=>$cost];}
            $this->db->commit();
        }catch(Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
        $progress=[];$errors=[];if(!isset($meta['create_progress'])||(string)$meta['create_progress']!=='0')foreach($payrolls as$payroll){try{$progress[]=['bordro_id'=>$payroll['bordro_id'],'hakedis_id'=>(new ModuleService($this->db,$this->userId,null))->generateProgressFromPayroll((int)$payroll['bordro_id'])];}catch(Throwable$e){$errors[]=['bordro_id'=>$payroll['bordro_id'],'message'=>$e->getMessage()];}}
        return['format'=>'zirve_toplu_is_bazli_bordro','toplam_personel'=>count($records),'olusturulan_personel'=>$created,'eslesen'=>$matched,'eslesmeyen'=>[],'is_sayisi'=>count($payrolls),'bordrolar'=>$payrolls,'hakedisler'=>$progress,'hakedis_hatalari'=>$errors,'silinen'=>$removed];
    }

    public function analyzePayrollFile(string$file):array
    {
        $this->ensureLibrary();$rows=$this->read($file);
        if(!$this->isDetailedPayroll($rows))return['format'=>'duz_tablo','personel_sayisi'=>max(0,count($rows)-1)];
        $records=$this->detailedPayrollRecords($rows);
        return['format'=>'zirve_detayli_bordro','personel_sayisi'=>count($records),'ilk_personel'=>$records[0]['ad_soyad']??null,'son_personel'=>$records[array_key_last($records)]['ad_soyad']??null,'kalem_sayisi'=>array_sum(array_map(fn($r)=>count($r['kalemler']),$records))];
    }

    public function importPayrollCostSummary(string$file,int$payrollId):array
    {
        $this->ensureLibrary();$values=$this->detailedPayrollCostSummary($this->read($file));$this->savePayrollCostSummary($payrollId,$values);return$values;
    }

    private function importDetailedPayroll(string$file,array$rows,array$meta,string$mode,array$rightsRows=[]):array
    {
        $records=$this->detailedPayrollRecords($rows);if(!$records)throw new RuntimeException('Detaylı bordroda personel kaydı bulunamadı.');
        $period=$this->detailedPayrollPeriod($rows);if($period&&((int)$meta['yil']!==$period['yil']||(int)$meta['ay']!==$period['ay']))throw new RuntimeException("Excel dönemi {$period['ay']}/{$period['yil']}; seçilen dönem {$meta['ay']}/{$meta['yil']}. Lütfen dönem seçimini düzeltin.");
        $this->db->beginTransaction();
        try{
            [$id,$version]=$this->preparePayroll($file,$meta,$mode);$matched=0;$unmatched=[];$created=0;$peopleByName=[];
            foreach($records as$record){$person=$this->matchPerson($record['tc_kimlik_no'],$record['sgk_sicil_no']);$autoPersonnel=!isset($meta['auto_personnel'])||(string)$meta['auto_personnel']!=='0';if(!$person&&$autoPersonnel){$person=$this->createPersonFromPayroll($record,$meta);$created++;}if($person)$this->ensurePersonnelAssignment((int)$person['id'],$record,$meta);$status=$person?'eslesti':'eslesmedi';
                $this->db->prepare("INSERT INTO bordro_personelleri(bordro_id,personel_id,satir_no,tc_kimlik_no,sgk_sicil_no,ad_soyad,eslesme_durumu) VALUES(?,?,?,?,?,?,?)")->execute([$id,$person['id']??null,$record['satir_no'],$record['tc_kimlik_no'],$record['sgk_sicil_no']?:null,$record['ad_soyad'],$status]);$bp=(int)$this->db->lastInsertId();
                if($person){$matched++;$peopleByName[$this->normalize($record['ad_soyad'])]=(int)$person['id'];}else$unmatched[]=['satir'=>$record['satir_no'],'ad_soyad'=>$record['ad_soyad'],'tc'=>$record['tc_kimlik_no'],'sgk'=>$record['sgk_sicil_no']];
                $insert=$this->db->prepare("INSERT INTO bordro_kalemleri(bordro_personel_id,kalem_adi,miktar,tutar,kaynak_sutun) VALUES(?,?,?,?,?)");foreach($record['kalemler']as$item)$insert->execute([$bp,$item['ad'],$item['miktar'],$item['tutar'],$item['kaynak']]);
                if($person)$this->createTimesheet($id,(int)$person['id'],$meta,$bp);
            }
            $rights=['hak_kalemi'=>0,'personel_hakki'=>0,'tarihce_satiri'=>0,'eslesmeyen_hak_personeli'=>0];if($rightsRows&&(!isset($meta['auto_rights'])||(string)$meta['auto_rights']!=='0'))$rights=$this->importSupplementalRights($rightsRows,$peopleByName,$meta,$id,(string)($meta['dosya_adi']??basename($file)),hash_file('sha256',$file));$this->savePayrollCostSummary($id,$this->detailedPayrollCostSummary($rows));
            $this->db->prepare("UPDATE bordrolar SET durum='tamamlandi' WHERE id=?")->execute([$id]);$this->db->commit();return['bordro_id'=>$id,'surum'=>$version,'format'=>'zirve_detayli_bordro','toplam_personel'=>count($records),'olusturulan_personel'=>$created,'eslesen'=>$matched,'eslesmeyen'=>$unmatched,'otomatik_haklar'=>$rights];
        }catch(Throwable$e){$this->db->rollBack();throw$e;}
    }

    private function preparePayroll(string$file,array$meta,string$mode):array
    {
        $q=$this->db->prepare("SELECT * FROM bordrolar WHERE yil=? AND ay=? AND sozlesme_id=? AND is_id=? AND aktif=1 ORDER BY surum DESC LIMIT 1");$q->execute([$meta['yil'],$meta['ay'],$meta['sozlesme_id'],$meta['is_id']]);$old=$q->fetch();
        if($old&&$mode==='cancel')throw new RuntimeException('Bu dönem ve iş için bordro zaten var; yükleme iptal edildi.');
        $fileName=basename((string)($meta['dosya_adi']??basename($file)));
        if($old&&$mode==='update'){$this->db->prepare("DELETE k FROM puantaj_karsilastirmalari k JOIN puantajlar p ON p.id=k.puantaj_id WHERE p.bordro_id=?")->execute([$old['id']]);$this->db->prepare("DELETE d FROM puantaj_detaylari d JOIN puantajlar p ON p.id=d.puantaj_id WHERE p.bordro_id=?")->execute([$old['id']]);$this->db->prepare("DELETE FROM puantajlar WHERE bordro_id=?")->execute([$old['id']]);$this->db->prepare("DELETE bk FROM bordro_kalemleri bk JOIN bordro_personelleri bp ON bp.id=bk.bordro_personel_id WHERE bp.bordro_id=?")->execute([$old['id']]);$this->db->prepare("DELETE FROM bordro_personelleri WHERE bordro_id=?")->execute([$old['id']]);$id=(int)$old['id'];$version=(int)$old['surum'];$this->db->prepare("UPDATE bordrolar SET tis_donem_id=?,dosya_adi=?,dosya_hash=?,durum='isleniyor' WHERE id=?")->execute([$meta['tis_donem_id'],$fileName,hash_file('sha256',$file),$id]);return[$id,$version];}
        $version=$old?(int)$old['surum']+1:1;if($old)$this->db->prepare("UPDATE bordrolar SET aktif=0 WHERE id=?")->execute([$old['id']]);$this->db->prepare("INSERT INTO bordrolar(yil,ay,sozlesme_id,is_id,tis_donem_id,surum,onceki_bordro_id,dosya_adi,dosya_hash,yukleyen_kullanici_id) VALUES(?,?,?,?,?,?,?,?,?,?)")->execute([$meta['yil'],$meta['ay'],$meta['sozlesme_id'],$meta['is_id'],$meta['tis_donem_id'],$version,$old['id']??null,$fileName,hash_file('sha256',$file),$this->userId]);return[(int)$this->db->lastInsertId(),$version];
    }

    private function isDetailedPayroll(array$rows):bool
    {
        foreach(array_slice($rows,0,20)as$row)if($this->normalize($row[0]??'')==='adi soyadi'&&$this->normalize($row[3]??'')==='tc kimlik no')return true;return false;
    }

    private function detailedPayrollPeriod(array$rows):?array
    {
        $months=['ocak'=>1,'subat'=>2,'mart'=>3,'nisan'=>4,'mayis'=>5,'haziran'=>6,'temmuz'=>7,'agustos'=>8,'eylul'=>9,'ekim'=>10,'kasim'=>11,'aralik'=>12];foreach(array_slice($rows,0,8)as$row)foreach($row as$cell){$text=$this->normalize($cell??'');if(preg_match('/\b('.implode('|',array_keys($months)).')\s+(20\d{2})\b/u',$text,$match))return['ay'=>$months[$match[1]],'yil'=>(int)$match[2]];}return null;
    }

    private function detailedPayrollCostSummary(array$rows):array
    {
        $values=['toplam_kazanc'=>0.0,'ssk_prim_isveren'=>0.0,'issizlik_prim_isveren'=>0.0];
        foreach($rows as$row){$label=$this->normalize($row[0]??'');$amount=$this->numericValue($row[3]??0);if($label==='brut kazanclar toplami')$values['toplam_kazanc']=$amount;elseif($label==='net sigorta isveren hissesi')$values['ssk_prim_isveren']=$amount;elseif($label==='sigorta isveren hissesi'&&$values['ssk_prim_isveren']==0.0)$values['ssk_prim_isveren']=$amount;elseif($label==='issizlik isveren hissesi')$values['issizlik_prim_isveren']=$amount;}
        return$values;
    }

    private function detailedRecordsCostSummary(array$records):array
    {
        $values=['toplam_kazanc'=>0.0,'ssk_prim_isveren'=>0.0,'issizlik_prim_isveren'=>0.0];$map=['toplam kazanc'=>'toplam_kazanc','ssk prim isveren'=>'ssk_prim_isveren','issizlik prim isveren'=>'issizlik_prim_isveren'];foreach($records as$record)foreach($record['kalemler']as$item){$key=$this->normalize($item['ad']);if(isset($map[$key]))$values[$map[$key]]+=(float)$item['tutar'];}foreach($values as&$value)$value=round($value,2);unset($value);return$values;
    }

    private function savePayrollCostSummary(int$payrollId,array$values):void
    {
        $earnings=round((float)($values['toplam_kazanc']??0),2);$social=round((float)($values['ssk_prim_isveren']??0),2);$unemployment=round((float)($values['issizlik_prim_isveren']??0),2);$base=round($earnings+$social+$unemployment,2);$overhead=round($base*.04,2);$profit=round($base*.07,2);$total=round($base+$overhead+$profit,2);
        $this->db->prepare('INSERT INTO bordro_maliyet_ozetleri(bordro_id,toplam_kazanc,ssk_prim_isveren,issizlik_prim_isveren,baz_toplam,kar_orani,kar_tutari,genel_gider_orani,genel_gider_tutari,toplam_hakedis_tutari) VALUES(?,?,?,?,?,7,?,4,?,?) ON DUPLICATE KEY UPDATE toplam_kazanc=VALUES(toplam_kazanc),ssk_prim_isveren=VALUES(ssk_prim_isveren),issizlik_prim_isveren=VALUES(issizlik_prim_isveren),baz_toplam=VALUES(baz_toplam),kar_orani=7,kar_tutari=VALUES(kar_tutari),genel_gider_orani=4,genel_gider_tutari=VALUES(genel_gider_tutari),toplam_hakedis_tutari=VALUES(toplam_hakedis_tutari)')->execute([$payrollId,$earnings,$social,$unemployment,$base,$profit,$overhead,$total]);
    }

    private function detailedPayrollRecords(array$rows):array
    {
        $headerIndex=null;foreach(array_slice($rows,0,25,true)as$i=>$row)if($this->normalize($row[0]??'')==='adi soyadi'&&$this->normalize($row[3]??'')==='tc kimlik no'){$headerIndex=$i;break;}if($headerIndex===null)return[];$headers=$rows[$headerIndex];$personIndexes=[];$summaryIndex=count($rows);
        foreach($rows as$i=>$row){if($i<=$headerIndex)continue;$first=$this->normalize($row[0]??'');if($first==='brut odemeler'){$summaryIndex=$i;break;}$tc=preg_replace('/\D/','',(string)($row[3]??''));if(strlen($tc)===11&&trim((string)($row[0]??''))!=='')$personIndexes[]=$i;}
        $records=[];foreach($personIndexes as$position=>$index){$row=$rows[$index];$next=$personIndexes[$position+1]??$summaryIndex;$items=[];
            foreach(range(6,23)as$column){if($column===7)continue;$label=trim((string)($headers[$column]??''));$value=$row[$column]??null;if($label===''||$label==='11'||$value===null||$value==='')continue;if($column===6)$items[]=['ad'=>'Prim Günü','miktar'=>$this->numericValue($value),'tutar'=>0.0,'kaynak'=>'G'.($index+1)];else$items[]=['ad'=>$label,'miktar'=>0.0,'tutar'=>$this->numericValue($value),'kaynak'=>$this->columnName($column).($index+1)];}
            for($detail=$index+1;$detail<$next;$detail++){$detailRow=$rows[$detail];$first=$this->normalize($detailRow[0]??'');if($first==='gunluk ucreti'){$items[]=['ad'=>'Günlük Ücret','miktar'=>1.0,'tutar'=>$this->numericValue($detailRow[1]??0),'kaynak'=>'B'.($detail+1)];continue;}foreach([0,5,10,15]as$offset){$rawLabel=trim((string)($detailRow[$offset]??''));if($rawLabel===''||$rawLabel==='11'||$rawLabel==='---')continue;$label=trim((string)preg_replace('/\s*:\s*$/u','',$rawLabel));$amount=$this->quantityValue($detailRow[$offset+1]??null);$money=$this->numericValue($detailRow[$offset+2]??0);if($label!==''&&($amount!=0.0||$money!=0.0))$items[]=['ad'=>$label,'miktar'=>$amount,'tutar'=>$money,'kaynak'=>$this->columnName($offset).($detail+1)];}}
            $records[]=['satir_no'=>$index+1,'ad_soyad'=>trim((string)$row[0]),'tc_kimlik_no'=>preg_replace('/\D/','',(string)$row[3]),'sgk_sicil_no'=>trim((string)($row[4]??'')),'ise_giris_tarihi'=>trim((string)($row[1]??'')),'isten_cikis_tarihi'=>trim((string)($row[2]??'')),'kalemler'=>$items];}
        return$records;
    }

    private function mergeDetailedPayrollRecords(array$records):array
    {
        $merged=[];foreach($records as$record){$key=$record['tc_kimlik_no']!==''?$record['tc_kimlik_no']:$this->normalize($record['ad_soyad']);if(!isset($merged[$key])){$record['kaynak_satirlari']=[$record['satir_no']];$merged[$key]=$record;continue;}$merged[$key]['kaynak_satirlari'][]=$record['satir_no'];$merged[$key]['satir_no']=min((int)$merged[$key]['satir_no'],(int)$record['satir_no']);$items=[];foreach(array_merge($merged[$key]['kalemler'],$record['kalemler'])as$item){$itemKey=$this->normalize($item['ad']);if(!isset($items[$itemKey]))$items[$itemKey]=$item;else{$items[$itemKey]['miktar']=round((float)$items[$itemKey]['miktar']+(float)$item['miktar'],2);$items[$itemKey]['tutar']=round((float)$items[$itemKey]['tutar']+(float)$item['tutar'],2);$items[$itemKey]['kaynak'].=','.$item['kaynak'];}}$merged[$key]['kalemler']=array_values($items);}return array_values($merged);
    }

    private function personnelAssignmentForPeriod(int$personId,int$year,int$month):?array
    {
        $start=sprintf('%04d-%02d-01',$year,$month);$end=date('Y-m-t',strtotime($start));$q=$this->db->prepare('SELECT sozlesme_id,is_id,tis_donem_id FROM personel_is_gecmisi WHERE personel_id=? AND baslangic_tarihi<=? AND (bitis_tarihi IS NULL OR bitis_tarihi>=?) ORDER BY aktif DESC,baslangic_tarihi DESC,id DESC');$q->execute([$personId,$end,$start]);$rows=$q->fetchAll();if(!$rows)return null;$pairs=[];foreach($rows as$row)$pairs[$row['sozlesme_id'].'-'.$row['is_id'].'-'.$row['tis_donem_id']]=$row;if(count($pairs)>1)throw new RuntimeException('Personelin aynı ay içinde birden fazla iş ataması var. Personel iş geçmişini düzeltin.');$row=reset($pairs);return['sozlesme_id'=>(int)$row['sozlesme_id'],'is_id'=>(int)$row['is_id'],'tis_donem_id'=>(int)$row['tis_donem_id']];
    }

    private function deletePayrollPeriod(int$year,int$month,array$scopes):array
    {
        $unique=[];foreach($scopes as$scope){$contractId=(int)($scope['sozlesme_id']??0);$jobId=(int)($scope['is_id']??0);if($contractId>0&&$jobId>0)$unique[$contractId.'-'.$jobId]=[$contractId,$jobId];}if(!$unique)return['bordro'=>0,'hakedis'=>0];
        $conditions=[];$scopeParams=[];foreach($unique as[$contractId,$jobId]){$conditions[]='(sozlesme_id=? AND is_id=?)';$scopeParams[]=$contractId;$scopeParams[]=$jobId;}$scopeSql=' AND ('.implode(' OR ',$conditions).')';$params=array_merge([$year,$month],$scopeParams);
        $progress=$this->db->prepare('SELECT id FROM hakedisler WHERE yil=? AND ay=?'.$scopeSql);$progress->execute($params);$progressIds=array_map('intval',$progress->fetchAll(PDO::FETCH_COLUMN));if($progressIds){$marks=implode(',',array_fill(0,count($progressIds),'?'));foreach(['hakedis_genel_kalemleri','hakedis_mali_ozetleri','hakedis_detaylari','hakedis_bordrolari']as$table)$this->db->prepare("DELETE FROM {$table} WHERE hakedis_id IN ({$marks})")->execute($progressIds);$this->db->prepare("DELETE FROM hakedisler WHERE id IN ({$marks})")->execute($progressIds);}
        $payroll=$this->db->prepare('SELECT id FROM bordrolar WHERE yil=? AND ay=?'.$scopeSql);$payroll->execute($params);$payrollIds=array_map('intval',$payroll->fetchAll(PDO::FETCH_COLUMN));if($payrollIds){$marks=implode(',',array_fill(0,count($payrollIds),'?'));$this->db->prepare("DELETE k FROM puantaj_karsilastirmalari k JOIN puantajlar p ON p.id=k.puantaj_id WHERE p.bordro_id IN ({$marks})")->execute($payrollIds);$this->db->prepare("DELETE d FROM puantaj_detaylari d JOIN puantajlar p ON p.id=d.puantaj_id WHERE p.bordro_id IN ({$marks})")->execute($payrollIds);$this->db->prepare("DELETE FROM puantajlar WHERE bordro_id IN ({$marks})")->execute($payrollIds);foreach(['personel_sendikal_hak_tarihcesi','bordro_maliyet_ozetleri']as$table)$this->db->prepare("DELETE FROM {$table} WHERE bordro_id IN ({$marks})")->execute($payrollIds);$this->db->prepare("DELETE k FROM bordro_kalemleri k JOIN bordro_personelleri p ON p.id=k.bordro_personel_id WHERE p.bordro_id IN ({$marks})")->execute($payrollIds);$this->db->prepare("DELETE FROM bordro_personelleri WHERE bordro_id IN ({$marks})")->execute($payrollIds);$this->db->prepare("UPDATE bordrolar SET onceki_bordro_id=NULL WHERE onceki_bordro_id IN ({$marks})")->execute($payrollIds);$this->db->prepare("DELETE FROM bordrolar WHERE id IN ({$marks})")->execute($payrollIds);}
        return['bordro'=>count($payrollIds),'hakedis'=>count($progressIds)];
    }

    public function importComparison(string$file,array$meta):array
    {
        $this->ensureLibrary();$rows=$this->read($file);$headers=array_map([$this,'normalize'],array_shift($rows));$this->requireHeaders($headers,['ad soyad'],['tc kimlik no','sgk sicil no']);$this->db->beginTransaction();
        try{$this->db->prepare("INSERT INTO puantaj_excel_yuklemeleri(yil,ay,sozlesme_id,is_id,dosya_adi,dosya_hash,yukleyen_kullanici_id)VALUES(?,?,?,?,?,?,?)")->execute([$meta['yil'],$meta['ay'],$meta['sozlesme_id'],$meta['is_id'],basename($file),hash_file('sha256',$file),$this->userId]);$upload=(int)$this->db->lastInsertId();$differences=0;$people=0;
            foreach($rows as$row){$r=[];foreach($headers as$i=>$h)$r[$h]=$row[$i]??null;$person=$this->matchPerson((string)($r['tc kimlik no']??''),(string)($r['sgk sicil no']??''));if(!$person)continue;$q=$this->db->prepare("SELECT * FROM puantajlar WHERE yil=? AND ay=? AND sozlesme_id=? AND is_id=? AND personel_id=? ORDER BY id DESC LIMIT 1");$q->execute([$meta['yil'],$meta['ay'],$meta['sozlesme_id'],$meta['is_id'],$person['id']]);$p=$q->fetch();if(!$p)continue;$people++;
                foreach(self::TIMESHEET_MAP as$header=>$field){if(!array_key_exists($header,$r))continue;$excel=(float)str_replace(',','.',(string)$r[$header]);$system=(float)$p[$field];$fark=$excel-$system;if(abs($fark)>=0.005)$differences++;$this->db->prepare("INSERT INTO puantaj_karsilastirmalari(yukleme_id,puantaj_id,personel_id,alan_adi,sistem_degeri,excel_degeri,fark,son_islem)VALUES(?,?,?,?,?,?,?,?)")->execute([$upload,$p['id'],$person['id'],$field,$system,$excel,$fark,abs($fark)<0.005?'sistem':'bekliyor']);if(abs($fark)>=0.005)$this->db->prepare("UPDATE puantajlar SET durum='farkli' WHERE id=?")->execute([$p['id']]);}
            }$this->db->prepare("UPDATE puantaj_excel_yuklemeleri SET durum='tamamlandi' WHERE id=?")->execute([$upload]);$this->db->commit();return['yukleme_id'=>$upload,'personel'=>$people,'fark'=>$differences];
        }catch(Throwable$e){$this->db->rollBack();throw$e;}
    }

    public function resolveComparison(int$id,string$choice,?float$manual=null):void
    {$q=$this->db->prepare("SELECT * FROM puantaj_karsilastirmalari WHERE id=?");$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('Karşılaştırma kaydı bulunamadı.');$value=$choice==='excel'?(float)$r['excel_degeri']:($choice==='manuel'?$manual:(float)$r['sistem_degeri']);if(!in_array($r['alan_adi'],array_values(self::TIMESHEET_MAP),true))throw new RuntimeException('Geçersiz alan.');$this->db->prepare("UPDATE puantajlar SET {$r['alan_adi']}=? WHERE id=?")->execute([$value,$r['puantaj_id']]);$this->db->prepare("UPDATE puantaj_karsilastirmalari SET son_islem=?,islem_kullanici_id=?,islem_tarihi=NOW() WHERE id=?")->execute([$choice,$this->userId,$id]);$pending=$this->db->prepare("SELECT COUNT(*) FROM puantaj_karsilastirmalari WHERE puantaj_id=? AND son_islem='bekliyor'");$pending->execute([$r['puantaj_id']]);if(!(int)$pending->fetchColumn())$this->db->prepare("UPDATE puantajlar SET durum='onayli' WHERE id=?")->execute([$r['puantaj_id']]);}

    private function createTimesheet(int$payroll,int$person,array$meta,int$bp):void{$values=array_fill_keys(array_values(self::TIMESHEET_MAP),0.0);$q=$this->db->prepare("SELECT kalem_adi,miktar FROM bordro_kalemleri WHERE bordro_personel_id=?");$q->execute([$bp]);foreach($q->fetchAll()as$item){$key=$this->normalize($item['kalem_adi']);if(isset(self::TIMESHEET_MAP[$key]))$values[self::TIMESHEET_MAP[$key]]+=(float)$item['miktar'];}$cols=array_keys($values);$allColumns=array_merge(['bordro_id','personel_id','yil','ay','sozlesme_id','is_id'],$cols);$sql='INSERT INTO puantajlar('.implode(',',$allColumns).') VALUES ('.implode(',',array_fill(0,count($allColumns),'?')).')';$this->db->prepare($sql)->execute(array_merge([$payroll,$person,$meta['yil'],$meta['ay'],$meta['sozlesme_id'],$meta['is_id']],array_values($values)));$timesheet=(int)$this->db->lastInsertId();$this->db->prepare("INSERT INTO puantaj_detaylari(puantaj_id,kalem_adi,miktar,tutar,kaynak) SELECT ?,kalem_adi,miktar,tutar,'bordro' FROM bordro_kalemleri WHERE bordro_personel_id=?")->execute([$timesheet,$bp]);}
    private function matchPerson(string$tc,string$sgk):array|false{$tc=preg_replace('/\D/','',$tc);if($tc!==''){$q=$this->db->prepare("SELECT id FROM personeller WHERE tc_kimlik_no=? LIMIT 1");$q->execute([$tc]);if($r=$q->fetch())return$r;}if(trim($sgk)!==''){$q=$this->db->prepare("SELECT id FROM personeller WHERE sgk_sicil_no=? LIMIT 1");$q->execute([trim($sgk)]);if($r=$q->fetch())return$r;}return false;}
    private function createPersonFromPayroll(array$record,array$meta):array
    {
        $tc=preg_replace('/\D/','',(string)$record['tc_kimlik_no']);if(strlen($tc)!==11)throw new RuntimeException($record['ad_soyad'].' için geçerli T.C. kimlik numarası bulunamadı.');
        $period=$this->db->prepare('SELECT sendika_id,baslangic_tarihi FROM tis_donemleri WHERE id=?');$period->execute([(int)$meta['tis_donem_id']]);$tis=$period->fetch();if(!$tis)throw new RuntimeException('Seçilen TİS dönemi bulunamadı.');
        $entry=$this->parseDate($record['ise_giris_tarihi']??null)??sprintf('%04d-%02d-01',(int)$meta['yil'],(int)$meta['ay']);$exit=$this->parseDate($record['isten_cikis_tarihi']??null);
        $this->db->prepare('INSERT INTO personeller(ad_soyad,tc_kimlik_no,sgk_sicil_no,ise_giris_tarihi,isten_cikis_tarihi,sendika_id,aktif,aciklama) VALUES(?,?,?,?,?,?,1,?)')->execute([$record['ad_soyad'],$tc,trim((string)$record['sgk_sicil_no'])?:null,$entry,$exit,$tis['sendika_id'],'Bordro dosyasından otomatik oluşturuldu.']);
        return['id'=>(int)$this->db->lastInsertId()];
    }
    private function ensurePersonnelAssignment(int$personId,array$record,array$meta):void
    {
        $start=sprintf('%04d-%02d-01',(int)$meta['yil'],(int)$meta['ay']);$q=$this->db->prepare('SELECT id FROM personel_is_gecmisi WHERE personel_id=? AND sozlesme_id=? AND is_id=? AND tis_donem_id=? AND aktif=1 LIMIT 1');$q->execute([$personId,(int)$meta['sozlesme_id'],(int)$meta['is_id'],(int)$meta['tis_donem_id']]);if($q->fetchColumn())return;
        $this->db->prepare('UPDATE personel_is_gecmisi SET aktif=0,bitis_tarihi=COALESCE(bitis_tarihi,DATE_SUB(?,INTERVAL 1 DAY)) WHERE personel_id=? AND aktif=1')->execute([$start,$personId]);
        $entry=$this->parseDate($record['ise_giris_tarihi']??null);$assignmentStart=$entry&&$entry>$start?$entry:$start;$this->db->prepare('INSERT INTO personel_is_gecmisi(personel_id,sozlesme_id,is_id,tis_donem_id,baslangic_tarihi,aciklama) VALUES(?,?,?,?,?,?)')->execute([$personId,(int)$meta['sozlesme_id'],(int)$meta['is_id'],(int)$meta['tis_donem_id'],$assignmentStart,'Bordro dosyasından otomatik bağlandı.']);
    }
    private function importSupplementalRights(array$rows,array$people,array$meta,int$payrollId,string$fileName,string$fileHash):array
    {
        $header=null;foreach(array_slice($rows,0,20,true)as$i=>$row)if($this->normalize($row[1]??'')==='ad soyad'&&$this->normalize($row[2]??'')==='aciklama'){$header=$i;break;}if($header===null)return['hak_kalemi'=>0,'yeni_hak_kalemi'=>0,'personel_hakki'=>0,'tarihce_satiri'=>0,'eslesmeyen_hak_personeli'=>0];
        $effective=sprintf('%04d-%02d-01',(int)$meta['yil'],(int)$meta['ay']);$period=$this->db->prepare('SELECT baslangic_tarihi,bitis_tarihi FROM tis_donemleri WHERE id=?');$period->execute([(int)$meta['tis_donem_id']]);$tis=$period->fetch();if(!$tis)throw new RuntimeException('Seçilen TİS dönemi bulunamadı.');if($effective<$tis['baslangic_tarihi'])$effective=$tis['baslangic_tarihi'];if($effective>$tis['bitis_tarihi'])throw new RuntimeException('Bordro dönemi seçilen TİS döneminin dışındadır.');
        $catalog=[];$newRights=0;$assignments=[];$historyCount=0;$unknownPeople=[];
        $find=$this->db->prepare("SELECT h.* FROM sendikal_haklar h WHERE h.tis_donem_id=? AND UPPER(TRIM(h.hak_adi))=UPPER(TRIM(?)) AND h.aktif=1 ORDER BY h.surum DESC,h.id DESC LIMIT 1");
        $insertRight=$this->db->prepare("INSERT INTO sendikal_haklar(tis_donem_id,hak_grup_kodu,surum,hak_adi,bordro_kalem_adi,birim,birim_fiyat,hesaplama_sekli,hakedise_dahil,gecerlilik_baslangic,gecerlilik_bitis,aktif) VALUES(?,?,1,?,?,?,?,'bordro_tutari',1,?,NULL,1)");
        $assign=$this->db->prepare("INSERT INTO personel_donem_haklari(personel_id,sendikal_hak_id,tis_donem_id,birim,birim_fiyat,hesaplama_sekli,hakedise_dahil,gecerlilik_baslangic,gecerlilik_bitis,aktif) VALUES(?,?,?,?,?,'bordro_tutari',1,?,NULL,1) ON DUPLICATE KEY UPDATE birim=VALUES(birim),birim_fiyat=VALUES(birim_fiyat),hesaplama_sekli='bordro_tutari',hakedise_dahil=1,gecerlilik_baslangic=LEAST(gecerlilik_baslangic,VALUES(gecerlilik_baslangic)),aktif=1");
        $history=$this->db->prepare("INSERT IGNORE INTO personel_sendikal_hak_tarihcesi(personel_id,sendikal_hak_id,tis_donem_id,bordro_id,yil,ay,birim,birim_fiyat,bu_hakedis_miktari,bu_hakedis_tutari,toplam_miktar,toplam_tutar,kaynak_dosya,dosya_hash,kaynak_satir,gecerlilik_tarihi) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach(array_slice($rows,$header+1,null,true)as$rowIndex=>$row){$name=trim((string)($row[1]??''));$rightName=trim((string)($row[2]??''));if($name===''||$rightName==='')continue;$personKey=$this->normalize($name);$rightKey=$this->normalize($rightName);$unit=$this->rightUnit($row[3]??'');$price=$this->numericValue($row[4]??0);
            if(!isset($catalog[$rightKey])){$find->execute([(int)$meta['tis_donem_id'],$rightName]);$right=$find->fetch();if(!$right){$insertRight->execute([(int)$meta['tis_donem_id'],$this->uuid(),$rightName,$this->rightPayrollAlias($rightName),$unit,$price,$effective]);$right=['id'=>(int)$this->db->lastInsertId()];$newRights++;}$catalog[$rightKey]=(int)$right['id'];}
            $personId=$this->personIdByName($personKey,$people);if($personId===null){$unknownPeople[$personKey]=$name;continue;}$rightId=$catalog[$rightKey];$assign->execute([$personId,$rightId,(int)$meta['tis_donem_id'],$unit,$price,$effective]);$assignments[$personId.'-'.$rightId]=true;
            $history->execute([$personId,$rightId,(int)$meta['tis_donem_id'],$payrollId,(int)$meta['yil'],(int)$meta['ay'],$unit,$price,$this->numericValue($row[8]??0),$this->numericValue($row[11]??0),$this->numericValue($row[6]??0),$this->numericValue($row[9]??0),basename($fileName),$fileHash,$rowIndex+1,$effective]);$historyCount+=$history->rowCount();
        }
        return['hak_kalemi'=>count($catalog),'yeni_hak_kalemi'=>$newRights,'personel_hakki'=>count($assignments),'tarihce_satiri'=>$historyCount,'eslesmeyen_hak_personeli'=>count($unknownPeople)];
    }
    private function rightUnit(mixed$value):string{return match($this->normalize($value)){'gun'=>'gun','saat'=>'saat','ay'=>'ay','tutar'=>'tutar',default=>'adet'};}
    private function personIdByName(string$key,array$people):?int{if(isset($people[$key]))return(int)$people[$key];$matches=[];foreach($people as$name=>$id)if(levenshtein($key,$name)<=2)$matches[]=(int)$id;return count(array_unique($matches))===1?$matches[0]:null;}
    private function rightPayrollAlias(string$name):string{return match($this->normalize($name)){'aile yardimi'=>'Aile','cocuk yardimi'=>'Çocuk','dini bayram calismasi'=>'Tatil Mesaisi','fazla calisma yapilmasi'=>'Fazla Mesai','haftalik tatil mesaisi'=>'Hafta Tatili','kidem'=>'KIDEM ZAMMI','seyyanen(birlestirilmis sosyal yardim)'=>'Birleştirilmiş Sosyal Yardım','yakacak'=>'El.Doğ.Isınma Ödemesi','yevmiye ucreti'=>'Sendika',default=>$name};}
    private function parseDate(mixed$value):?string{if($value instanceof \DateTimeInterface)return$value->format('Y-m-d');$text=trim((string)$value);if($text==='')return null;foreach(['!d/m/Y','!d.m.Y','!Y-m-d']as$format){$date=\DateTimeImmutable::createFromFormat($format,$text);if($date!==false)return$date->format('Y-m-d');}return null;}
    private function uuid():string{$b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);}
    private function read(string$file):array{$reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file);$reader->setReadDataOnly(true);return$reader->load($file)->getActiveSheet()->toArray(null,true,true,false);}
    private function readSheets(string$file):array{$reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file);$reader->setReadDataOnly(true);$book=$reader->load($file);$sheets=[];foreach($book->getWorksheetIterator()as$sheet)$sheets[$sheet->getTitle()]=$sheet->toArray(null,true,true,false);return$sheets;}
    private function sheetByName(array$sheets,string$name):array{foreach($sheets as$title=>$rows)if($this->normalize($title)===$this->normalize($name))return$rows;return[];}
    private function normalize(mixed$value):string{$s=mb_strtolower(trim((string)$value),'UTF-8');$s=str_replace("\u{0307}",'',$s);$s=strtr($s,['ı'=>'i','ş'=>'s','ğ'=>'g','ü'=>'u','ö'=>'o','ç'=>'c','.'=>'','_'=>' ']);return preg_replace('/\s+/',' ',$s);}
    private function numericValue(mixed$value):float{if(is_int($value)||is_float($value))return(float)$value;$text=trim((string)$value);if($text===''||$text==='---'||$text==='11')return 0.0;$text=preg_replace('/[^0-9,.-]/u','',$text);if($text===''||$text==='-')return 0.0;if(str_contains($text,',')&&str_contains($text,'.'))$text=str_replace(['.',','],['','.'],$text);elseif(str_contains($text,','))$text=str_replace(',','.',$text);return(float)$text;}
    private function quantityValue(mixed$value):float{if(is_int($value)||is_float($value))return(float)$value;$text=trim((string)$value);if($text===''||$text==='11'||$text==='---')return 0.0;if(!preg_match('/-?\d+(?:[.,]\d+)?/u',$text,$match))return 0.0;return(float)str_replace(',','.',$match[0]);}
    private function columnName(int$zeroBased):string{$name='';for($index=$zeroBased+1;$index>0;$index=intdiv($index-1,26))$name=chr(65+(($index-1)%26)).$name;return$name;}
    private function parseCell(mixed$v):array{if(is_numeric($v))return[(float)$v,(float)$v];$s=preg_replace('/[^0-9,.-]/','',(string)$v);$n=(float)str_replace(['.',','],['','.'],$s);return[$n,$n];}
    private function requireHeaders(array$headers,array$all,array$oneOf):void{foreach($all as$h)if(!in_array($h,$headers,true))throw new RuntimeException("Excel sütunu eksik: {$h}");if(!array_intersect($oneOf,$headers))throw new RuntimeException('T.C. kimlik no veya SGK sicil no sütunlarından biri zorunludur.');}
    private function ensureLibrary():void{if(!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class))throw new RuntimeException('PhpSpreadsheet kurulu değil. Proje dizininde composer install çalıştırın.');}
}
