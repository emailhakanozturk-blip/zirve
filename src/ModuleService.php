<?php
declare(strict_types=1);

namespace PersonelHakedis;

use PDO;
use RuntimeException;
use Throwable;

final class ModuleService
{
    private const UNION_RIGHT_PRESET = [
        ['AİLE YARDIMI','adet'], ['BAYRAM YARDIMI','adet'], ['BAYRAM YARDIMI (K)','adet'],
        ['ÇOCUK YARDIMI','adet'], ['DİNİ BAYRAM ÇALIŞMASI','gun'], ['FAZLA ÇALIŞMA YAPILMASI','saat'],
        ['GECE MESAİSİ','saat'], ['GİYİM YARDIMI','adet'], ['HAFTALIK TATİL MESAİSİ','gun'],
        ['İKRAMİYE','gun'], ['İZİN YARDIMI','adet'], ['İZİN YARDIMI (2024 )','adet'],
        ['KIDEM','adet'], ['KIRTASİYE YARDIMI (EYLÜL AYI)','adet'], ['KIRTASİYE YARDIMI (ÜNİVERSİTE)','adet'],
        ['KULLANILMAYAN İZİN ÜCRETİ','gun'], ['KULLANILMAYAN İZİN YARDIMI','adet'], ['ÖLÜM YARDIMI','adet'],
        ['RESMİ TATİL GÜNLERİ ÇALIŞMASI','gun'], ['SAHA ÇALIŞMA PRİMİ','adet'], ['SENDİKA TEMSİLCİSİ','adet'],
        ['SEYYANEN(BİRLEŞTİRİLMİŞ SOSYAL YARDIM)','adet'], ['SORUMLULUK PRİMİ','adet'], ['SÜNNET YARDIMI','adet'],
        ['TEDİYE (2024 YILI İÇİN 20 GÜN)','gun'], ['TEDİYE (2025 YILI İÇİN 40 GÜN)','gun'],
        ['ULUSAL BAYRAM MESAİSİ','gun'], ['YAKACAK','adet'], ['YEMEK','gun'], ['YEVMİYE ÜCRETİ','gun'], ['YOL','gun'],
    ];
    private const CRUD = [
        'sozlesmeler' => ['ad','numara','idare','baslangic_tarihi','bitis_tarihi','sozlesme_bedeli','aciklama','aktif'],
        'is_tanimlari' => ['ad','aciklama','aktif'],
        'sendikalar' => ['ad','aciklama','aktif'],
        'tis_donemleri' => ['ad','sendika_id','baslangic_tarihi','bitis_tarihi','aciklama','aktif'],
        'sendikal_haklar' => ['tis_donem_id','hak_adi','bordro_kalem_adi','birim','birim_fiyat','hesaplama_sekli','hakedise_dahil','gecerlilik_baslangic','gecerlilik_bitis','aktif'],
    ];

    public function __construct(private PDO $db, private ?int $userId, private ?string $ip) {}

    public function dashboard(): array
    {
        $counts = [];
        foreach (['sozlesmeler','is_tanimlari','personeller','bordrolar','puantajlar','hakedisler'] as $table) {
            $counts[$table] = (int) $this->db->query("SELECT COUNT(*) FROM {$table} WHERE " . (in_array($table,['personeller','sozlesmeler','is_tanimlari','bordrolar','hakedisler'],true) ? 'aktif=1' : '1=1'))->fetchColumn();
        }
        $counts['bekleyen_puantaj'] = (int) $this->db->query("SELECT COUNT(*) FROM puantajlar WHERE durum <> 'onayli'")->fetchColumn();
        $counts['eslesmeyen_personel'] = (int) $this->db->query("SELECT COUNT(*) FROM bordro_personelleri WHERE eslesme_durumu <> 'eslesti'")->fetchColumn();
        return $counts;
    }

    public function options(): array
    {
        return [
            'sozlesmeler' => $this->db->query("SELECT id, ad, numara FROM sozlesmeler WHERE aktif=1 ORDER BY ad")->fetchAll(),
            'isler' => $this->db->query("SELECT i.id,i.ad,si.sozlesme_id FROM is_tanimlari i JOIN sozlesme_isleri si ON si.is_id=i.id AND si.aktif=1 WHERE i.aktif=1 ORDER BY i.ad")->fetchAll(),
            'sendikalar' => $this->db->query("SELECT id,ad FROM sendikalar WHERE aktif=1 ORDER BY ad")->fetchAll(),
            'donemler' => $this->db->query("SELECT id,ad,sendika_id FROM tis_donemleri WHERE aktif=1 ORDER BY baslangic_tarihi DESC")->fetchAll(),
            'personeller' => $this->db->query("SELECT id,ad_soyad FROM personeller WHERE aktif=1 ORDER BY ad_soyad")->fetchAll(),
            'haklar' => $this->db->query("SELECT h.id,h.hak_adi,h.tis_donem_id FROM sendikal_haklar h WHERE h.aktif=1 AND NOT EXISTS(SELECT 1 FROM sendikal_haklar n WHERE n.onceki_hak_id=h.id) ORDER BY h.hak_adi")->fetchAll(),
        ];
    }

    public function list(string $entity, array $filters = [], int $page = 1, int $size = 25): array
    {
        $page = max(1, $page); $size = min(100, max(5, $size)); $offset = ($page - 1) * $size;
        $queries = [
            'sozlesmeler' => "SELECT * FROM sozlesmeler",
            'is_tanimlari' => "SELECT i.*,GROUP_CONCAT(s.ad ORDER BY s.ad SEPARATOR ', ') sozlesmeler FROM is_tanimlari i LEFT JOIN sozlesme_isleri si ON si.is_id=i.id AND si.aktif=1 LEFT JOIN sozlesmeler s ON s.id=si.sozlesme_id GROUP BY i.id",
            'personeller' => "SELECT p.*,s.ad sendika,ig.sozlesme_id,ig.is_id,ig.tis_donem_id,so.ad sozlesme_adi,i.ad is_adi,t.ad donem_adi FROM personeller p LEFT JOIN personel_is_gecmisi ig ON ig.personel_id=p.id AND ig.aktif=1 LEFT JOIN sozlesmeler so ON so.id=ig.sozlesme_id LEFT JOIN is_tanimlari i ON i.id=ig.is_id LEFT JOIN tis_donemleri t ON t.id=ig.tis_donem_id LEFT JOIN sendikalar s ON s.id=p.sendika_id",
            'sendikalar' => "SELECT * FROM sendikalar",
            'tis_donemleri' => "SELECT t.*,s.ad sendika FROM tis_donemleri t JOIN sendikalar s ON s.id=t.sendika_id",
            'sendikal_haklar' => "SELECT h.*,t.ad donem_adi,CASE WHEN EXISTS(SELECT 1 FROM sendikal_haklar n WHERE n.onceki_hak_id=h.id) THEN 'gecmis' ELSE 'guncel' END surum_durumu FROM sendikal_haklar h JOIN tis_donemleri t ON t.id=h.tis_donem_id",
            'personel_haklari' => "SELECT x.id,x.yil,x.ay,x.personel_id,x.sendikal_hak_id,x.tis_donem_id,p.ad_soyad,h.hak_adi,t.ad donem_adi,x.birim,x.birim_fiyat,x.bu_hakedis_miktari,x.bu_hakedis_tutari,x.gecerlilik_tarihi,x.kaynak_dosya FROM personel_sendikal_hak_tarihcesi x JOIN personeller p ON p.id=x.personel_id JOIN sendikal_haklar h ON h.id=x.sendikal_hak_id JOIN tis_donemleri t ON t.id=x.tis_donem_id",
            'bordrolar' => "SELECT b.*,s.ad sozlesme_adi,i.ad is_adi,t.ad donem_adi,(SELECT COUNT(*) FROM bordro_personelleri bp WHERE bp.bordro_id=b.id AND bp.eslesme_durumu<>'eslesti') eslesmeyen FROM bordrolar b JOIN sozlesmeler s ON s.id=b.sozlesme_id JOIN is_tanimlari i ON i.id=b.is_id JOIN tis_donemleri t ON t.id=b.tis_donem_id",
            'puantajlar' => "SELECT b.id,b.id bordro_id,b.yil,b.ay,b.sozlesme_id,b.is_id,b.dosya_adi,b.surum,s.ad sozlesme_adi,i.ad is_adi,COUNT(p.id) personel_sayisi,CASE WHEN SUM(p.durum='farkli')>0 THEN 'farkli' WHEN SUM(p.durum='onayli')=COUNT(p.id) THEN 'onayli' ELSE 'taslak' END durum FROM bordrolar b JOIN puantajlar p ON p.bordro_id=b.id JOIN sozlesmeler s ON s.id=b.sozlesme_id JOIN is_tanimlari i ON i.id=b.is_id WHERE b.aktif=1 GROUP BY b.id,b.yil,b.ay,b.sozlesme_id,b.is_id,b.dosya_adi,b.surum,s.ad,i.ad",
            'karsilastirmalar' => "SELECT k.*,p.ad_soyad FROM puantaj_karsilastirmalari k JOIN personeller p ON p.id=k.personel_id",
            'hakedisler' => "SELECT h.*,s.ad sozlesme_adi FROM hakedisler h JOIN sozlesmeler s ON s.id=h.sozlesme_id",
        ];
        if (!isset($queries[$entity])) throw new RuntimeException('Geçersiz liste türü.');
        $sql = "SELECT SQL_CALC_FOUND_ROWS q.* FROM ({$queries[$entity]}) q WHERE 1=1"; $args = [];
        foreach (['yil','ay','sozlesme_id','is_id','tis_donem_id','personel_id','durum','aktif'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') { $sql .= " AND q.{$field}=:{$field}"; $args[$field]=$filters[$field]; }
        }
        $searchColumns = [
            'sozlesmeler' => ['numara','ad','idare'], 'is_tanimlari' => ['ad','sozlesmeler'],
            'personeller' => ['ad_soyad','tc_kimlik_no','sgk_sicil_no','sozlesme_adi','is_adi'],
            'sendikalar' => ['ad','aciklama'],
            'tis_donemleri' => ['ad','sendika'], 'sendikal_haklar' => ['hak_adi','bordro_kalem_adi','donem_adi'],
            'personel_haklari' => ['ad_soyad','hak_adi','donem_adi','kaynak_dosya'],
            'bordrolar' => ['sozlesme_adi','is_adi'], 'puantajlar' => ['dosya_adi','sozlesme_adi','is_adi'],
            'karsilastirmalar' => ['ad_soyad','alan_adi'], 'hakedisler' => ['sozlesme_adi'],
        ];
        if (!empty($filters['q'])) { $columns=implode(',',array_map(fn($c)=>"q.{$c}",$searchColumns[$entity]));$sql .= " AND CONCAT_WS(' ',{$columns}) LIKE :q"; $args['q']='%'.$filters['q'].'%'; }
        $sortColumns = [
            'sozlesmeler'=>['id','numara','ad','idare','baslangic_tarihi','bitis_tarihi','sozlesme_bedeli','aktif'],
            'is_tanimlari'=>['id','ad','sozlesmeler','aktif'],'personeller'=>['id','ad_soyad','tc_kimlik_no','sgk_sicil_no','sozlesme_adi','is_adi','donem_adi','aktif'],
            'sendikalar'=>['id','ad','aktif'],'tis_donemleri'=>['id','ad','sendika','baslangic_tarihi','bitis_tarihi','aktif'],
            'sendikal_haklar'=>['id','hak_adi','donem_adi','bordro_kalem_adi','birim','birim_fiyat','surum','gecerlilik_baslangic','gecerlilik_bitis','hakedise_dahil','aktif'],
            'personel_haklari'=>['id','yil','ay','ad_soyad','hak_adi','donem_adi','birim','birim_fiyat','bu_hakedis_miktari','bu_hakedis_tutari','gecerlilik_tarihi'],
            'bordrolar'=>['id','yil','ay','sozlesme_adi','is_adi','surum','durum','eslesmeyen'],
            'puantajlar'=>['id','bordro_id','yil','ay','sozlesme_adi','is_adi','dosya_adi','surum','personel_sayisi','durum'],
            'karsilastirmalar'=>['id','ad_soyad','alan_adi','sistem_degeri','excel_degeri','fark','son_islem'],
            'hakedisler'=>['id','yil','ay','sozlesme_adi','surum','toplam_tutar','durum'],
        ];
        $sort=in_array($filters['sort']??'', $sortColumns[$entity], true)?$filters['sort']:'id';$direction=strtoupper((string)($filters['dir']??'DESC'))==='ASC'?'ASC':'DESC';
        $sql .= " ORDER BY q.{$sort} {$direction} LIMIT {$size} OFFSET {$offset}";
        $stmt=$this->db->prepare($sql); $stmt->execute($args); $rows=$stmt->fetchAll();
        return ['rows'=>$rows,'total'=>(int)$this->db->query('SELECT FOUND_ROWS()')->fetchColumn(),'page'=>$page,'size'=>$size];
    }

    public function save(string $entity, array $data): int
    {
        if (!isset(self::CRUD[$entity])) throw new RuntimeException('Bu kayıt türü desteklenmiyor.');
        if ($entity === 'sendikal_haklar') return $this->saveUnionRightVersion($data);
        $id=(int)($data['id']??0); $values=[];
        foreach (self::CRUD[$entity] as $field) if (array_key_exists($field,$data)) $values[$field]=$data[$field]===''?null:$data[$field];
        if (!$values) throw new RuntimeException('Kaydedilecek alan bulunamadı.');
        $this->db->beginTransaction();
        try {
            if ($id) {
                $old=$this->find($entity,$id); $sets=[]; foreach($values as $field=>$v)$sets[]="{$field}=:{$field}";
                $values['id']=$id; $stmt=$this->db->prepare("UPDATE {$entity} SET ".implode(',',$sets)." WHERE id=:id"); $stmt->execute($values);
                $this->logChanges($entity,$id,$old,$values);
            } else {
                $fields=array_keys($values); $stmt=$this->db->prepare("INSERT INTO {$entity} (".implode(',',$fields).") VALUES (:".implode(',:',$fields).")");
                $stmt->execute($values); $id=(int)$this->db->lastInsertId(); $this->log('ekle',$entity,$id,null,null,null);
            }
            if ($entity==='is_tanimlari' && !empty($data['sozlesme_id'])) $this->linkJob($id,(int)$data['sozlesme_id']);
            $this->db->commit(); return $id;
        } catch(Throwable $e){$this->db->rollBack();throw $e;}
    }

    public function seedUnionRights(int $periodId, string $effectiveDate): int
    {
        if ($periodId < 1 || !$this->validDate($effectiveDate)) throw new RuntimeException('TİS dönemi ve geçerlilik tarihi zorunludur.');
        $period=$this->find('tis_donemleri',$periodId);
        if(!$period)throw new RuntimeException('TİS dönemi bulunamadı.');
        if($effectiveDate<$period['baslangic_tarihi']||$effectiveDate>$period['bitis_tarihi'])throw new RuntimeException('Geçerlilik tarihi TİS dönemi içinde olmalıdır.');
        $exists=$this->db->prepare("SELECT COUNT(*) FROM sendikal_haklar WHERE tis_donem_id=? AND UPPER(TRIM(hak_adi))=UPPER(TRIM(?))");
        $insert=$this->db->prepare("INSERT INTO sendikal_haklar(tis_donem_id,hak_grup_kodu,surum,hak_adi,bordro_kalem_adi,birim,birim_fiyat,hesaplama_sekli,hakedise_dahil,gecerlilik_baslangic,gecerlilik_bitis,aktif) VALUES(?,?,1,?,?,?,?,'bordro_tutari',1,?,NULL,1)");
        $count=0;$this->db->beginTransaction();
        try{
            foreach(self::UNION_RIGHT_PRESET as[$name,$unit]){$exists->execute([$periodId,$name]);if($exists->fetchColumn())continue;$insert->execute([$periodId,$this->uuid(),$name,$name,$unit,0,$effectiveDate]);$id=(int)$this->db->lastInsertId();$this->syncRightToPersonnel($id);$this->log('toplu_hak_ekle','sendikal_haklar',$id,null,null,$name);$count++;}
            $this->db->commit();return$count;
        }catch(Throwable$e){$this->db->rollBack();throw$e;}
    }

    private function saveUnionRightVersion(array $data): int
    {
        foreach(['tis_donem_id','hak_adi','bordro_kalem_adi','birim','hesaplama_sekli','gecerlilik_baslangic'] as$f)if(($data[$f]??'')==='')throw new RuntimeException('Zorunlu sendikal hak alanları eksik.');
        $start=(string)$data['gecerlilik_baslangic'];$end=($data['gecerlilik_bitis']??'')?:null;
        if(!$this->validDate($start)||($end!==null&&!$this->validDate($end)))throw new RuntimeException('Geçerlilik tarihi hatalı.');
        if($end!==null&&$end<$start)throw new RuntimeException('Bitiş tarihi başlangıçtan önce olamaz.');
        $period=$this->find('tis_donemleri',(int)$data['tis_donem_id']);
        if(!$period||$start<$period['baslangic_tarihi']||$start>$period['bitis_tarihi'])throw new RuntimeException('Başlangıç tarihi seçilen TİS dönemi içinde olmalıdır.');
        if($end!==null&&$end>$period['bitis_tarihi'])throw new RuntimeException('Bitiş tarihi TİS dönemi bitişini aşamaz.');
        $fields=['tis_donem_id','hak_adi','bordro_kalem_adi','birim','birim_fiyat','hesaplama_sekli','hakedise_dahil','gecerlilik_baslangic','gecerlilik_bitis','aktif'];
        $id=(int)($data['id']??0);$this->db->beginTransaction();
        try{
            if($id){
                $old=$this->find('sendikal_haklar',$id);if(!$old)throw new RuntimeException('Sendikal hak bulunamadı.');
                if((int)$old['tis_donem_id']!==(int)$data['tis_donem_id'])throw new RuntimeException('Bir hak sürümünün TİS dönemi değiştirilemez.');
                $successor=$this->db->prepare('SELECT id FROM sendikal_haklar WHERE onceki_hak_id=? LIMIT 1');$successor->execute([$id]);if($successor->fetchColumn())throw new RuntimeException('Yalnızca hakkın en son sürümü değiştirilebilir.');
                if($start<=$old['gecerlilik_baslangic'])throw new RuntimeException('Yeni sürüm başlangıcı eski sürüm başlangıcından sonra olmalıdır.');
                $close=(new \DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');
                $this->db->prepare('UPDATE sendikal_haklar SET gecerlilik_bitis=? WHERE id=?')->execute([$close,$id]);
                $values=[];foreach($fields as$f)$values[$f]=array_key_exists($f,$data)&&$data[$f]!==''?$data[$f]:($f==='gecerlilik_bitis'?null:$old[$f]);
                $values['hak_grup_kodu']=$old['hak_grup_kodu'];$values['surum']=(int)$old['surum']+1;$values['onceki_hak_id']=$id;
                $all=array_merge(['hak_grup_kodu','surum','onceki_hak_id'],$fields);$stmt=$this->db->prepare('INSERT INTO sendikal_haklar('.implode(',',$all).') VALUES (:'.implode(',:',$all).')');$stmt->execute($values);$newId=(int)$this->db->lastInsertId();
                $this->syncRightToPersonnel($newId);$this->log('yeni_surum','sendikal_haklar',$newId,'onceki_hak_id',(string)$id,(string)$newId);$id=$newId;
            }else{
                $values=[];foreach($fields as$f)$values[$f]=($data[$f]??'')===''?($f==='gecerlilik_bitis'?null:($f==='birim_fiyat'?0:($f==='aktif'||$f==='hakedise_dahil'?1:null))):$data[$f];
                $values['hak_grup_kodu']=$this->uuid();$all=array_merge(['hak_grup_kodu'],$fields);$stmt=$this->db->prepare('INSERT INTO sendikal_haklar('.implode(',',$all).') VALUES (:'.implode(',:',$all).')');$stmt->execute($values);$id=(int)$this->db->lastInsertId();$this->syncRightToPersonnel($id);$this->log('ekle','sendikal_haklar',$id,null,null,null);
            }
            $this->db->commit();return$id;
        }catch(Throwable$e){$this->db->rollBack();throw$e;}
    }

    public function savePersonnel(array $data): int
    {
        foreach(['ad_soyad','ise_giris_tarihi','sozlesme_id','is_id'] as $f) if(empty($data[$f])) throw new RuntimeException('Zorunlu personel alanları eksik.');
        $this->db->beginTransaction();
        try {
            $id=(int)($data['id']??0); $fields=['ad_soyad','tc_kimlik_no','sgk_sicil_no','ise_giris_tarihi','isten_cikis_tarihi','sendika_id','aktif','aciklama']; $v=[];
            foreach($fields as $f)$v[$f]=($data[$f]??'')===''?null:$data[$f];
            if($id){$old=$this->find('personeller',$id);$set=implode(',',array_map(fn($f)=>"{$f}=:{$f}",$fields));$v['id']=$id;$this->db->prepare("UPDATE personeller SET {$set} WHERE id=:id")->execute($v);$this->logChanges('personeller',$id,$old,$v);}
            else{$this->db->prepare("INSERT INTO personeller (".implode(',',$fields).") VALUES (:".implode(',:',$fields).")")->execute($v);$id=(int)$this->db->lastInsertId();}
            $this->db->prepare("UPDATE personel_is_gecmisi SET aktif=0,bitis_tarihi=COALESCE(bitis_tarihi,DATE_SUB(:baslangic,INTERVAL 1 DAY)) WHERE personel_id=:p AND aktif=1 AND (sozlesme_id<>:s OR is_id<>:i OR NOT(tis_donem_id<=>:t))")->execute(['baslangic'=>$data['ise_giris_tarihi'],'p'=>$id,'s'=>$data['sozlesme_id'],'i'=>$data['is_id'],'t'=>$data['tis_donem_id']?:null]);
            $check=$this->db->prepare("SELECT id FROM personel_is_gecmisi WHERE personel_id=? AND sozlesme_id=? AND is_id=? AND aktif=1");$check->execute([$id,$data['sozlesme_id'],$data['is_id']]);
            if(!$check->fetchColumn()){$this->db->prepare("INSERT INTO personel_is_gecmisi(personel_id,sozlesme_id,is_id,tis_donem_id,baslangic_tarihi,aciklama) VALUES(?,?,?,?,?,?)")->execute([$id,$data['sozlesme_id'],$data['is_id'],$data['tis_donem_id']?:null,$data['ise_giris_tarihi'],$data['aciklama']??null]);}
            $this->log($data['id']?'guncelle':'ekle','personeller',$id,null,null,null);$this->db->commit();return$id;
        }catch(Throwable$e){$this->db->rollBack();throw$e;}
    }

    public function copyRights(int $sourceId,int $targetId): int
    {
        $stmt=$this->db->prepare("INSERT INTO sendikal_haklar(tis_donem_id,hak_grup_kodu,surum,onceki_hak_id,hak_adi,bordro_kalem_adi,birim,birim_fiyat,hesaplama_sekli,hakedise_dahil,gecerlilik_baslangic,gecerlilik_bitis,aktif) SELECT :target_id,UUID(),1,NULL,h.hak_adi,h.bordro_kalem_adi,h.birim,h.birim_fiyat,h.hesaplama_sekli,h.hakedise_dahil,(SELECT baslangic_tarihi FROM tis_donemleri WHERE id=:period_id),NULL,h.aktif FROM sendikal_haklar h WHERE h.tis_donem_id=:source_id AND h.aktif=1 AND NOT EXISTS(SELECT 1 FROM sendikal_haklar n WHERE n.onceki_hak_id=h.id)");
        $stmt->execute(['target_id'=>$targetId,'period_id'=>$targetId,'source_id'=>$sourceId]); $this->log('haklari_kopyala','tis_donemleri',$targetId,null,(string)$sourceId,(string)$targetId);return$stmt->rowCount();
    }

    public function applyRights(int $periodId): int
    {
        $sql="INSERT INTO personel_donem_haklari(personel_id,sendikal_hak_id,tis_donem_id,birim,birim_fiyat,hesaplama_sekli,hakedise_dahil,gecerlilik_baslangic,gecerlilik_bitis,aktif) SELECT ig.personel_id,h.id,h.tis_donem_id,h.birim,h.birim_fiyat,h.hesaplama_sekli,h.hakedise_dahil,h.gecerlilik_baslangic,h.gecerlilik_bitis,1 FROM personel_is_gecmisi ig JOIN personeller p ON p.id=ig.personel_id AND p.aktif=1 JOIN sendikal_haklar h ON h.tis_donem_id=ig.tis_donem_id AND h.aktif=1 WHERE ig.tis_donem_id=:period AND ig.aktif=1 ON DUPLICATE KEY UPDATE birim=VALUES(birim),birim_fiyat=VALUES(birim_fiyat),hesaplama_sekli=VALUES(hesaplama_sekli),hakedise_dahil=VALUES(hakedise_dahil),gecerlilik_baslangic=VALUES(gecerlilik_baslangic),gecerlilik_bitis=VALUES(gecerlilik_bitis),aktif=1";
        $stmt=$this->db->prepare($sql);$stmt->execute(['period'=>$periodId]);$this->log('toplu_hak_uygula','tis_donemleri',$periodId,null,null,null);return$stmt->rowCount();
    }

    public function updateTimesheet(int $id,array $values): void
    {
        $allowed=['normal_gun','hafta_tatili','fazla_mesai','rapor','ucretli_izin','ucretsiz_izin','resmi_tatil','gece_mesaisi','yol_gunu','yemek_gunu','prim_gunu','durum'];$old=$this->find('puantajlar',$id);$set=[];$args=['id'=>$id];
        foreach($allowed as$f)if(array_key_exists($f,$values)){$set[]="{$f}=:{$f}";$args[$f]=$values[$f];}
        if(!$set)throw new RuntimeException('Değişiklik yok.');$this->db->prepare("UPDATE puantajlar SET ".implode(',',$set)." WHERE id=:id")->execute($args);$this->logChanges('puantajlar',$id,$old,$args);
    }

    public function deleteTimesheet(int$id):void
    {
        $old=$this->find('puantajlar',$id);if(!$old)throw new RuntimeException('Puantaj kaydı bulunamadı.');
        $this->db->beginTransaction();
        try{
            $this->db->prepare('DELETE FROM puantaj_karsilastirmalari WHERE puantaj_id=?')->execute([$id]);
            $this->db->prepare('DELETE FROM puantaj_detaylari WHERE puantaj_id=?')->execute([$id]);
            $this->db->prepare('DELETE FROM puantajlar WHERE id=?')->execute([$id]);
            $this->log('sil','puantajlar',$id,null,json_encode($old,JSON_UNESCAPED_UNICODE),null);$this->db->commit();
        }catch(Throwable$e){$this->db->rollBack();throw$e;}
    }

    public function generateProgress(int $year,int $month,int $contractId,int $payrollId=0): int
    {
        if($payrollId>0){$payrollQuery=$this->db->prepare("SELECT id FROM bordrolar WHERE id=? AND yil=? AND ay=? AND sozlesme_id=? AND aktif=1 AND durum='tamamlandi'");$payrollQuery->execute([$payrollId,$year,$month,$contractId]);}
        else{$payrollQuery=$this->db->prepare("SELECT id FROM bordrolar WHERE yil=? AND ay=? AND sozlesme_id=? AND aktif=1 AND durum='tamamlandi' ORDER BY is_id,surum DESC");$payrollQuery->execute([$year,$month,$contractId]);}
        $payrollIds=array_map('intval',$payrollQuery->fetchAll(PDO::FETCH_COLUMN));if(!$payrollIds)throw new RuntimeException('Seçilen dönem ve sözleşme için tamamlanmış aktif bordro bulunamadı.');
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT COALESCE(MAX(surum),0)+1 FROM hakedisler WHERE yil=? AND ay=? AND sozlesme_id=?');$q->execute([$year,$month,$contractId]);$version=(int)$q->fetchColumn();$this->db->prepare('UPDATE hakedisler SET aktif=0 WHERE yil=? AND ay=? AND sozlesme_id=? AND aktif=1')->execute([$year,$month,$contractId]);
            $this->db->prepare('INSERT INTO hakedisler(yil,ay,sozlesme_id,surum,olusturan_kullanici_id) VALUES(?,?,?,?,?)')->execute([$year,$month,$contractId,$version,$this->userId]);$id=(int)$this->db->lastInsertId();$link=$this->db->prepare('INSERT INTO hakedis_bordrolari(hakedis_id,bordro_id) VALUES(?,?)');foreach($payrollIds as$payrollId)$link->execute([$id,$payrollId]);
            $marks=implode(',',array_fill(0,count($payrollIds),'?'));$sql="INSERT INTO hakedis_detaylari(hakedis_id,bordro_id,is_id,personel_id,sendikal_hak_id,hak_kalemi,birim,birim_fiyat,toplam_miktar,onceki_miktar,bu_hakedis_miktari,bu_ayki_kazanc,onceki_ay_toplami,kumulatif_toplam,aciklama) SELECT ?,x.bordro_id,b.is_id,x.personel_id,x.sendikal_hak_id,h.hak_adi,x.birim,x.birim_fiyat,ROUND(SUM(x.toplam_miktar),2),ROUND(SUM(x.toplam_miktar-x.bu_hakedis_miktari),2),ROUND(SUM(x.bu_hakedis_miktari),2),ROUND(SUM(x.bu_hakedis_tutari),2),ROUND(SUM(x.toplam_tutar-x.bu_hakedis_tutari),2),ROUND(SUM(x.toplam_tutar),2),CONCAT(b.dosya_adi,' / bordro #',b.id) FROM personel_sendikal_hak_tarihcesi x JOIN bordrolar b ON b.id=x.bordro_id JOIN sendikal_haklar h ON h.id=x.sendikal_hak_id WHERE x.bordro_id IN ({$marks}) GROUP BY x.bordro_id,b.is_id,x.personel_id,x.sendikal_hak_id,h.hak_adi,x.birim,x.birim_fiyat,b.dosya_adi,b.id ORDER BY x.personel_id,h.hak_adi";$stmt=$this->db->prepare($sql);$stmt->execute(array_merge([$id],$payrollIds));if($stmt->rowCount()===0)throw new RuntimeException('Bordrodan hakediş hareketi bulunamadı. Bordroyu personel yan haklarını otomatik getir seçeneğiyle yeniden yükleyin.');
            $costQuery=$this->db->prepare("SELECT ROUND(SUM(toplam_kazanc),2) toplam_kazanc,ROUND(SUM(ssk_prim_isveren),2) ssk_prim_isveren,ROUND(SUM(issizlik_prim_isveren),2) issizlik_prim_isveren,ROUND(SUM(baz_toplam),2) baz_toplam,ROUND(SUM(genel_gider_tutari),2) genel_gider_tutari,ROUND(SUM(kar_tutari),2) kar_tutari,ROUND(SUM(toplam_hakedis_tutari),2) toplam_hakedis_tutari FROM bordro_maliyet_ozetleri WHERE bordro_id IN ({$marks})");$costQuery->execute($payrollIds);$cost=$costQuery->fetch();if(!$cost||(float)$cost['baz_toplam']<=0)throw new RuntimeException('İşveren primleri bordro özetinden alınamadı. Bordroyu yeniden yükleyin.');$service=round((float)$cost['baz_toplam'],2);$current=round((float)$cost['toplam_hakedis_tutari'],2);
            $general=$this->db->prepare('INSERT INTO hakedis_genel_kalemleri(hakedis_id,kalem_adi,oran,onceki_tutar,bu_hakedis_tutari,kumulatif_tutar,sira) VALUES(?,?,?,0,?,?,?)');$generalRows=[['TOPLAM KAZANÇ',null,$cost['toplam_kazanc'],1],['SSK PRİM İŞVEREN',null,$cost['ssk_prim_isveren'],2],['İŞSİZLİK PRİM İŞVEREN',null,$cost['issizlik_prim_isveren'],3],['PRİMLER DAHİL TOPLAM',null,$service,4],['SÖZLEŞME GENEL GİDERLERİ',4,$cost['genel_gider_tutari'],5],['YÜKLENİCİ KARI',7,$cost['kar_tutari'],6],['GENEL TOPLAM (KDV HARİÇ)',null,$current,7]];foreach($generalRows as[$label,$rate,$amount,$order])$general->execute([$id,$label,$rate,$amount,$amount,$order]);
            $vat=round($current*.01,2);$accrual=round($current+$vat,2);$reportDate=sprintf('%04d-%02d-%02d',$year,$month,(int)date('t',strtotime(sprintf('%04d-%02d-01',$year,$month))));$this->db->prepare('UPDATE hakedisler SET toplam_tutar=? WHERE id=?')->execute([$current,$id]);$this->db->prepare('INSERT INTO hakedis_mali_ozetleri(hakedis_id,hak_edis_no,rapor_tarihi,sozlesme_fiyatlariyla_hizmet,fiyat_farki,onceki_hakedis_toplami,bu_hakedis_tutari,kdv_orani,kdv_tutari,tahakkuk_tutari,damga_vergisi,kesinti_toplami,odenecek_tutar,kaynak_dosya) VALUES(?,?,?,?,0,0,?,1,?,?,0,0,?,?)')->execute([$id,str_pad((string)$month,2,'0',STR_PAD_LEFT),$reportDate,$service,$current,$vat,$accrual,$accrual,'Yüklenen bordro #'.implode(', #',$payrollIds)]);$this->log('bordrodan_olustur','hakedisler',$id,'bordro_id',null,implode(',',$payrollIds));$this->db->commit();return$id;
        }catch(Throwable$e){$this->db->rollBack();throw$e;}
    }

    public function payrollMovements(int$id):array
    {
        $payroll=$this->db->prepare("SELECT b.id,b.yil,b.ay,b.sozlesme_id,b.is_id,b.surum,b.dosya_adi,b.durum,b.aktif,s.ad sozlesme_adi,i.ad is_adi FROM bordrolar b JOIN sozlesmeler s ON s.id=b.sozlesme_id JOIN is_tanimlari i ON i.id=b.is_id WHERE b.id=?");$payroll->execute([$id]);$meta=$payroll->fetch();if(!$meta)throw new RuntimeException('Bordro kaydı bulunamadı.');
        $q=$this->db->prepare("SELECT bp.id bordro_personel_id,bp.personel_id,COALESCE(p.ad_soyad,bp.ad_soyad) ad_soyad,k.kalem_adi,k.miktar,k.tutar,k.kaynak_sutun FROM bordro_personelleri bp LEFT JOIN personeller p ON p.id=bp.personel_id JOIN bordro_kalemleri k ON k.bordro_personel_id=bp.id WHERE bp.bordro_id=? ORDER BY bp.satir_no,k.id");$q->execute([$id]);
        $normalize=static function($v):string{$s=mb_strtolower(trim((string)$v),'UTF-8');$s=str_replace("\u{0307}",'',$s);$s=strtr($s,['ı'=>'i','ş'=>'s','ğ'=>'g','ü'=>'u','ö'=>'o','ç'=>'c','.'=>'','_'=>' ','('=>' ',')'=>' ']);return(string)preg_replace('/\s+/u',' ',$s);};
        $summary=array_combine(array_map($normalize,['NORMAL KAZANÇ','DİĞER KAZANÇ','TOPLAM KAZANÇ','SSK PRİM İŞVEREN','İŞSİZLİK PRİM İŞVEREN']),['normal_kazanc','diger_kazanc','toplam_kazanc','ssk_prim_isveren','issizlik_prim_isveren']);
        $excluded=array_fill_keys(array_map($normalize,['Normal Gün','Hafta Tatili','Ücretli İzin','Sendika','Günlük Ücret']),true);$people=[];$rights=[];$catalog=[];
        $catalogQuery=$this->db->prepare('SELECT id,hak_adi,bordro_kalem_adi,birim,birim_fiyat FROM sendikal_haklar WHERE tis_donem_id=(SELECT tis_donem_id FROM bordrolar WHERE id=?) AND aktif=1 ORDER BY surum DESC,id DESC');$catalogQuery->execute([$id]);
        foreach($catalogQuery->fetchAll()as$right){$canonical=['key'=>'H'.$right['id'],'label'=>$right['hak_adi'],'id'=>(int)$right['id'],'birim'=>$right['birim'],'birim_fiyat'=>(float)$right['birim_fiyat']];foreach([$right['hak_adi'],$right['bordro_kalem_adi']]as$alias){$aliasKey=$normalize($alias);if($aliasKey!==''&&!isset($catalog[$aliasKey]))$catalog[$aliasKey]=$canonical;}}
        foreach($q->fetchAll()as$item){$personKey=(string)$item['bordro_personel_id'];if(!isset($people[$personKey]))$people[$personKey]=['bordro_personel_id'=>(int)$item['bordro_personel_id'],'personel_id'=>$item['personel_id']?(int)$item['personel_id']:null,'ad_soyad'=>$item['ad_soyad'],'normal_kazanc'=>0.0,'diger_kazanc'=>0.0,'toplam_kazanc'=>0.0,'ssk_prim_isveren'=>0.0,'issizlik_prim_isveren'=>0.0,'haklar'=>[],'hak_miktarlari'=>[]];$name=trim((string)preg_replace('/\s*:\s*$/u','',trim((string)$item['kalem_adi'])));$key=$normalize($name);
            if(isset($summary[$key])){$people[$personKey][$summary[$key]]+=(float)$item['tutar'];continue;}
            if(!preg_match('/^[A-Z]+\d+$/i',(string)$item['kaynak_sutun'])||isset($excluded[$key])||abs((float)$item['tutar'])<.005)continue;
            $right=$catalog[$key]??null;if(!$right&&str_contains($key,$normalize('Sorumluluk')))$right=$catalog[$normalize('Sorumluluk Primi')]??null;if(!$right)continue;$rightKey=$right['key'];
            if(!isset($rights[$rightKey]))$rights[$rightKey]=$right;$people[$personKey]['haklar'][$rightKey]=($people[$personKey]['haklar'][$rightKey]??0)+(float)$item['tutar'];$people[$personKey]['hak_miktarlari'][$rightKey]=($people[$personKey]['hak_miktarlari'][$rightKey]??0)+(float)$item['miktar'];
        }
        $rows=[];$totals=['normal_kazanc'=>0.0,'diger_kazanc'=>0.0,'toplam_kazanc'=>0.0,'ssk_prim_isveren'=>0.0,'issizlik_prim_isveren'=>0.0,'bordro_hakedis_tutari'=>0.0,'hakedis_normal_kazanc'=>0.0,'haklar'=>[],'hakedis_toplami'=>0.0,'fark'=>0.0];
        foreach($people as$row){$row['bordro_hakedis_tutari']=round((float)$row['toplam_kazanc']+(float)$row['ssk_prim_isveren']+(float)$row['issizlik_prim_isveren'],2);$row['hakedis_normal_kazanc']=round((float)$row['normal_kazanc'],2);$row['hakedis_toplami']=round($row['hakedis_normal_kazanc']+array_sum($row['haklar']),2);$row['fark']=round((float)$row['toplam_kazanc']-$row['hakedis_toplami'],2);foreach(['normal_kazanc','diger_kazanc','toplam_kazanc','ssk_prim_isveren','issizlik_prim_isveren','bordro_hakedis_tutari','hakedis_normal_kazanc','hakedis_toplami','fark']as$field)$totals[$field]+=(float)$row[$field];foreach($row['haklar']as$key=>$amount)$totals['haklar'][$key]=($totals['haklar'][$key]??0)+(float)$amount;$rows[]=$row;}
        array_walk_recursive($totals,static function(&$v){if(is_float($v))$v=round($v,2);});
        $costQuery=$this->db->prepare('SELECT toplam_kazanc,ssk_prim_isveren,issizlik_prim_isveren,baz_toplam,kar_orani,kar_tutari,genel_gider_orani,genel_gider_tutari,toplam_hakedis_tutari FROM bordro_maliyet_ozetleri WHERE bordro_id=?');$costQuery->execute([$id]);$cost=$costQuery->fetch();
        if(!$cost){$earnings=(float)$totals['toplam_kazanc'];$cost=['toplam_kazanc'=>$earnings,'ssk_prim_isveren'=>0.0,'issizlik_prim_isveren'=>0.0,'baz_toplam'=>$earnings,'kar_orani'=>7.0,'kar_tutari'=>round($earnings*.07,2),'genel_gider_orani'=>4.0,'genel_gider_tutari'=>round($earnings*.04,2),'toplam_hakedis_tutari'=>round($earnings+round($earnings*.04,2)+round($earnings*.07,2),2),'bordro_ozeti_var'=>false];}else$cost['bordro_ozeti_var']=true;
        return['payroll'=>$meta,'rights'=>array_values($rights),'rows'=>$rows,'totals'=>$totals,'cost_summary'=>$cost,'total'=>$totals['hakedis_toplami']];
    }

    public function generateProgressFromPayroll(int$id):int
    {
        $payroll=$this->find('bordrolar',$id);if(!$payroll)throw new RuntimeException('Bordro kaydı bulunamadı.');
        if((int)$payroll['aktif']!==1||$payroll['durum']!=='tamamlandi')throw new RuntimeException('Hakediş yalnızca tamamlanmış aktif bordrodan oluşturulabilir.');
        $existing=$this->db->prepare('SELECT h.id FROM hakedisler h JOIN hakedis_bordrolari hb ON hb.hakedis_id=h.id WHERE hb.bordro_id=? AND h.aktif=1 ORDER BY h.surum DESC,h.id DESC LIMIT 1');$existing->execute([$id]);$existingId=(int)$existing->fetchColumn();if($existingId)return$existingId;
        $movement=$this->payrollMovements($id);if(!$movement['rows'])throw new RuntimeException('Bordrodan hakediş hareketi bulunamadı.');foreach($movement['rows']as$row)if(!$row['personel_id'])throw new RuntimeException($row['ad_soyad'].' personel kartıyla eşleşmediği için hakediş oluşturulamadı.');
        $normalRight=$this->db->prepare("SELECT id,hak_adi,birim,birim_fiyat FROM sendikal_haklar WHERE tis_donem_id=? AND aktif=1 AND (UPPER(TRIM(hak_adi))='YEVMİYE ÜCRETİ' OR UPPER(TRIM(bordro_kalem_adi))='SENDİKA') ORDER BY surum DESC,id DESC LIMIT 1");$normalRight->execute([(int)$payroll['tis_donem_id']]);$normal=$normalRight->fetch();if(!$normal)throw new RuntimeException('Normal kazanç için YEVMİYE ÜCRETİ sendikal hakkı bulunamadı.');
        $rights=[];foreach($movement['rights']as$right)$rights[$right['key']]=$right;$year=(int)$payroll['yil'];$month=(int)$payroll['ay'];$contractId=(int)$payroll['sozlesme_id'];$cost=$movement['cost_summary'];if(empty($cost['bordro_ozeti_var']))throw new RuntimeException('İşveren primleri bordro özetinden alınamadı. Bordroyu yeniden yükleyin.');$service=round((float)$cost['baz_toplam'],2);$current=round((float)$cost['toplam_hakedis_tutari'],2);
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT COALESCE(MAX(surum),0)+1 FROM hakedisler WHERE yil=? AND ay=? AND sozlesme_id=?');$q->execute([$year,$month,$contractId]);$version=(int)$q->fetchColumn();$this->db->prepare('UPDATE hakedisler SET aktif=0 WHERE yil=? AND ay=? AND sozlesme_id=? AND aktif=1')->execute([$year,$month,$contractId]);
            $this->db->prepare('INSERT INTO hakedisler(yil,ay,sozlesme_id,surum,toplam_tutar,olusturan_kullanici_id) VALUES(?,?,?,?,?,?)')->execute([$year,$month,$contractId,$version,$current,$this->userId]);$progressId=(int)$this->db->lastInsertId();$this->db->prepare('INSERT INTO hakedis_bordrolari(hakedis_id,bordro_id) VALUES(?,?)')->execute([$progressId,$id]);
            $insert=$this->db->prepare('INSERT INTO hakedis_detaylari(hakedis_id,bordro_id,is_id,personel_id,sendikal_hak_id,hak_kalemi,birim,birim_fiyat,toplam_miktar,onceki_miktar,bu_hakedis_miktari,bu_ayki_kazanc,onceki_ay_toplami,kumulatif_toplam,aciklama) VALUES(?,?,?,?,?,?,?,?,?,0,?,?,0,?,?)');
            foreach($movement['rows']as$row){$normalAmount=round((float)$row['hakedis_normal_kazanc'],2);if(abs($normalAmount)>=.005){$price=(float)$normal['birim_fiyat'];$quantity=$price>0?round($normalAmount/$price,2):1;$insert->execute([$progressId,$id,(int)$payroll['is_id'],(int)$row['personel_id'],(int)$normal['id'],$normal['hak_adi'],$normal['birim'],$price,$quantity,$quantity,$normalAmount,$normalAmount,$payroll['dosya_adi'].' / bordro #'.$id]);}
                foreach($row['haklar']as$key=>$amount){$amount=round((float)$amount,2);if(abs($amount)<.005||!isset($rights[$key]))continue;$right=$rights[$key];$quantity=round((float)($row['hak_miktarlari'][$key]??0),2);$price=(float)$right['birim_fiyat'];if($quantity<=0&&$price>0)$quantity=round($amount/$price,2);if($price<=0&&$quantity>0)$price=round($amount/$quantity,2);if($quantity<=0)$quantity=1;$insert->execute([$progressId,$id,(int)$payroll['is_id'],(int)$row['personel_id'],(int)$right['id'],$right['label'],$right['birim'],$price,$quantity,$quantity,$amount,$amount,$payroll['dosya_adi'].' / bordro #'.$id]);}
            }
            $general=$this->db->prepare('INSERT INTO hakedis_genel_kalemleri(hakedis_id,kalem_adi,oran,onceki_tutar,bu_hakedis_tutari,kumulatif_tutar,sira) VALUES(?,?,?,0,?,?,?)');$generalRows=[['TOPLAM KAZANÇ',null,$cost['toplam_kazanc'],1],['SSK PRİM İŞVEREN',null,$cost['ssk_prim_isveren'],2],['İŞSİZLİK PRİM İŞVEREN',null,$cost['issizlik_prim_isveren'],3],['PRİMLER DAHİL TOPLAM',null,$cost['baz_toplam'],4],['SÖZLEŞME GENEL GİDERLERİ',4,$cost['genel_gider_tutari'],5],['YÜKLENİCİ KARI',7,$cost['kar_tutari'],6],['GENEL TOPLAM (KDV HARİÇ)',null,$cost['toplam_hakedis_tutari'],7]];foreach($generalRows as[$label,$rate,$amount,$order])$general->execute([$progressId,$label,$rate,$amount,$amount,$order]);
            $vat=round($current*.01,2);$accrual=round($current+$vat,2);$reportDate=sprintf('%04d-%02d-%02d',$year,$month,(int)date('t',strtotime(sprintf('%04d-%02d-01',$year,$month))));$this->db->prepare('INSERT INTO hakedis_mali_ozetleri(hakedis_id,hak_edis_no,rapor_tarihi,sozlesme_fiyatlariyla_hizmet,fiyat_farki,onceki_hakedis_toplami,bu_hakedis_tutari,kdv_orani,kdv_tutari,tahakkuk_tutari,damga_vergisi,kesinti_toplami,odenecek_tutar,kaynak_dosya) VALUES(?,?,?,?,0,0,?,1,?,?,0,0,?,?)')->execute([$progressId,str_pad((string)$month,2,'0',STR_PAD_LEFT),$reportDate,$service,$current,$vat,$accrual,$accrual,'Bordro hareketleri #'.$id]);$this->log('bordro_hareketlerinden_olustur','hakedisler',$progressId,'bordro_id',null,(string)$id);$this->db->commit();return$progressId;
        }catch(Throwable$e){$this->db->rollBack();throw$e;}
    }

    public function deletePayroll(int$id):void
    {
        $old=$this->find('bordrolar',$id);if(!$old)throw new RuntimeException('Bordro kaydı bulunamadı.');
        $linked=$this->db->prepare('SELECT (SELECT COUNT(*) FROM hakedis_bordrolari WHERE bordro_id=?)+(SELECT COUNT(*) FROM hakedis_detaylari WHERE bordro_id=?)');$linked->execute([$id,$id]);
        if((int)$linked->fetchColumn()>0)throw new RuntimeException('Bu bordro bir hakedişe bağlı. Önce bağlı hakedişi silin, ardından bordroyu silebilirsiniz.');
        $this->db->beginTransaction();
        try{
            $this->db->prepare('DELETE k FROM puantaj_karsilastirmalari k JOIN puantajlar p ON p.id=k.puantaj_id WHERE p.bordro_id=?')->execute([$id]);
            $this->db->prepare('DELETE d FROM puantaj_detaylari d JOIN puantajlar p ON p.id=d.puantaj_id WHERE p.bordro_id=?')->execute([$id]);
            $this->db->prepare('DELETE FROM puantajlar WHERE bordro_id=?')->execute([$id]);
            $this->db->prepare('DELETE FROM personel_sendikal_hak_tarihcesi WHERE bordro_id=?')->execute([$id]);$this->db->prepare('DELETE FROM bordro_maliyet_ozetleri WHERE bordro_id=?')->execute([$id]);
            $this->db->prepare('DELETE k FROM bordro_kalemleri k JOIN bordro_personelleri p ON p.id=k.bordro_personel_id WHERE p.bordro_id=?')->execute([$id]);
            $this->db->prepare('DELETE FROM bordro_personelleri WHERE bordro_id=?')->execute([$id]);
            $this->db->prepare('UPDATE bordrolar SET onceki_bordro_id=? WHERE onceki_bordro_id=?')->execute([$old['onceki_bordro_id']?:null,$id]);
            $this->db->prepare('DELETE FROM bordrolar WHERE id=?')->execute([$id]);
            if((int)$old['aktif']===1){
                $q=$this->db->prepare('SELECT id FROM bordrolar WHERE yil=? AND ay=? AND sozlesme_id=? AND is_id=? ORDER BY surum DESC LIMIT 1');
                $q->execute([(int)$old['yil'],(int)$old['ay'],(int)$old['sozlesme_id'],(int)$old['is_id']]);$previousId=(int)$q->fetchColumn();
                if($previousId)$this->db->prepare('UPDATE bordrolar SET aktif=1 WHERE id=?')->execute([$previousId]);
            }
            $this->log('sil','bordrolar',$id,null,json_encode($old,JSON_UNESCAPED_UNICODE),null);$this->db->commit();
        }catch(Throwable$e){$this->db->rollBack();throw$e;}
    }

    public function db(): PDO{return $this->db;}
    public function payrollTimesheet(int$id):array
    {
        $q=$this->db->prepare("SELECT b.*,s.ad sozlesme_adi,i.ad is_adi FROM bordrolar b JOIN sozlesmeler s ON s.id=b.sozlesme_id JOIN is_tanimlari i ON i.id=b.is_id WHERE b.id=?");$q->execute([$id]);$payroll=$q->fetch();if(!$payroll)throw new RuntimeException('Bordro bulunamadı.');
        $q=$this->db->prepare("SELECT p.*,x.ad_soyad,x.tc_kimlik_no,x.ise_giris_tarihi,x.isten_cikis_tarihi FROM puantajlar p JOIN personeller x ON x.id=p.personel_id WHERE p.bordro_id=? ORDER BY x.ad_soyad,p.id");$q->execute([$id]);$rows=$q->fetchAll();if(!$rows)throw new RuntimeException('Bu bordroya bağlı puantaj bulunamadı.');
        $detailQuery=$this->db->prepare("SELECT d.puantaj_id,d.kalem_adi,SUM(d.miktar) miktar,SUM(d.tutar) tutar,MIN(d.id) ilk_id FROM puantaj_detaylari d JOIN puantajlar p ON p.id=d.puantaj_id WHERE p.bordro_id=? GROUP BY d.puantaj_id,d.kalem_adi ORDER BY ilk_id");$detailQuery->execute([$id]);$details=[];$allLabels=[];
        $excluded=['Prim Günü','İSTİSNA','NORMAL KAZANÇ','DİĞER KAZANÇ','TOPLAM KAZANÇ','SSK MATRAH','SSK PRİMİ','SSK PRİM İŞVEREN','SSK TEŞVİKİ','İŞSİZLİK PRİMİ','İŞSİZLİK PRİM İŞVEREN','G.V.M.  (AYLIK)','TOPLAM G.V.M.','GELİR VERGİSİ','ASGARİ GEÇ.İND.','KALAN G.VER.','DAMGA VERGİSİ','Normal Gün','Hafta Tatili','Ücretli İzin','Ücretsiz İzin','Rapor','Günlük Ücreti'];
        foreach($detailQuery->fetchAll()as$item){$label=trim((string)$item['kalem_adi']);if($label===''||in_array($label,$excluded,true)||is_numeric($label)||$label==='-')continue;$details[(int)$item['puantaj_id']][$label]=$this->timesheetDetailValue($label,(float)$item['miktar'],(float)$item['tutar']);if(!in_array($label,$allLabels,true))$allLabels[]=$label;}
        $preferred=['Fazla Mesai','Gece Mesaisi','Tatil Mesaisi','Yol','Yemek'];$labels=[];foreach($preferred as$label)if(in_array($label,$allLabels,true))$labels[]=$label;foreach($allLabels as$label)if(!in_array($label,$labels,true))$labels[]=$label;
        $columns=[];foreach($labels as$label)$columns[]=['key'=>$label,'label'=>$label,'unit'=>$this->timesheetDetailUnit($label)];$days=(int)date('t',strtotime(sprintf('%04d-%02d-01',(int)$payroll['yil'],(int)$payroll['ay'])));
        foreach($rows as&$row){$row['gunler']=$this->timesheetDayCodes($row,$days);$counts=array_count_values($row['gunler']);$row['calisilan_gun']=round((float)($counts['N']??0)+(float)($counts['O']??0)+(float)($counts['G']??0)+(float)($counts['T']??0)+(float)($counts['Y']??0)/2,2);$row['ssk_gun']=(float)$row['prim_gunu'];$row['izin_gun']=round((float)($counts['İ']??0)+(float)($counts['S']??0)+(float)($counts['R']??0)+(float)($counts['E']??0),2);$row['toplam_gun']=round($row['calisilan_gun']+(float)($counts['H']??0)+$row['izin_gun'],2);$row['eksik_gun']=max(0,round($days-(float)$row['prim_gunu'],2));$row['eksik_neden']=$row['eksik_gun']>0?'Eksik gün':' ';$row['detaylar']=$details[(int)$row['id']]??[];}unset($row);
        return['payroll'=>$payroll,'days'=>$days,'columns'=>$columns,'rows'=>$rows];
    }

    public function deletePayrollTimesheets(int$id):void
    {
        $q=$this->db->prepare('SELECT id FROM bordrolar WHERE id=?');$q->execute([$id]);if(!$q->fetchColumn())throw new RuntimeException('Bordro bulunamadı.');$this->db->beginTransaction();
        try{$this->db->prepare('DELETE k FROM puantaj_karsilastirmalari k JOIN puantajlar p ON p.id=k.puantaj_id WHERE p.bordro_id=?')->execute([$id]);$this->db->prepare('DELETE d FROM puantaj_detaylari d JOIN puantajlar p ON p.id=d.puantaj_id WHERE p.bordro_id=?')->execute([$id]);$this->db->prepare('DELETE FROM puantajlar WHERE bordro_id=?')->execute([$id]);$this->log('sil','puantajlar',null,'bordro_id',(string)$id,null);$this->db->commit();}catch(Throwable$e){$this->db->rollBack();throw$e;}
    }

    private function timesheetDayCodes(array$row,int$days):array
    {
        $codes=array_fill(1,$days,'');$assign=function(array$indexes,int$count,string$code)use(&$codes):void{foreach($indexes as$day){if($count<=0)break;if($codes[$day]===''){$codes[$day]=$code;$count--;}}};$weekends=[];$weekdays=[];for($day=1;$day<=$days;$day++){if((int)date('N',strtotime(sprintf('%04d-%02d-%02d',(int)$row['yil'],(int)$row['ay'],$day)))===7)$weekends[]=$day;else$weekdays[]=$day;}
        $assign($weekends,(int)round((float)$row['hafta_tatili']),'H');$assign($weekdays,(int)round((float)$row['ucretli_izin']),'İ');$assign($weekdays,(int)round((float)$row['rapor']),'R');$assign($weekdays,(int)round((float)$row['ucretsiz_izin']),'E');$assign(array_merge($weekdays,$weekends),(int)round((float)$row['normal_gun']),'N');$assign(array_merge($weekdays,$weekends),(int)round((float)$row['resmi_tatil']),'T');$covered=count(array_filter($codes,fn($value)=>$value!==''));$assign(array_merge($weekdays,$weekends),max(0,min($days,(int)round((float)$row['prim_gunu']))-$covered),'N');return$codes;
    }

    private function timesheetDetailValue(string$label,float$quantity,float$amount):float
    {
        return in_array($label,['Fazla Mesai','Gece Mesaisi','Tatil Mesaisi','Yol','Yemek','İKRAMİYE'],true)?$quantity:($amount!=0.0?$amount:$quantity);
    }

    private function timesheetDetailUnit(string$label):string
    {
        return match($label){'Fazla Mesai','Gece Mesaisi'=>'Saat','Tatil Mesaisi','Yol','Yemek','İKRAMİYE'=>'Gün',default=>'Net'};
    }

    public function timesheetDetails(int$id):array{$q=$this->db->prepare("SELECT kalem_adi,miktar,tutar,kaynak FROM puantaj_detaylari WHERE puantaj_id=? ORDER BY kalem_adi");$q->execute([$id]);return$q->fetchAll();}
    public function progressDetails(int$id):array{$q=$this->db->prepare("SELECT i.ad is_adi,p.ad_soyad,d.hak_kalemi,d.birim,d.birim_fiyat,d.toplam_miktar,d.onceki_miktar,d.bu_hakedis_miktari,d.bu_ayki_kazanc,d.onceki_ay_toplami,d.kumulatif_toplam,d.aciklama,b.id bordro_id,b.dosya_adi bordro_dosyasi,b.surum bordro_surum FROM hakedis_detaylari d JOIN is_tanimlari i ON i.id=d.is_id JOIN personeller p ON p.id=d.personel_id LEFT JOIN bordrolar b ON b.id=d.bordro_id WHERE d.hakedis_id=? ORDER BY i.ad,p.ad_soyad,d.hak_kalemi");$q->execute([$id]);return$q->fetchAll();}
    public function progressFinancial(int$id):array{$q=$this->db->prepare('SELECT * FROM hakedis_mali_ozetleri WHERE hakedis_id=?');$q->execute([$id]);$summary=$q->fetch()?:[];$g=$this->db->prepare('SELECT kalem_adi,oran,onceki_tutar,bu_hakedis_tutari,kumulatif_tutar FROM hakedis_genel_kalemleri WHERE hakedis_id=? ORDER BY sira,id');$g->execute([$id]);return['summary'=>$summary,'general'=>$g->fetchAll()];}
    public function progressEditData(int$id):array{$q=$this->db->prepare('SELECT h.id,h.yil,h.ay,h.sozlesme_id,h.surum,h.durum,h.aktif,h.toplam_tutar,m.hak_edis_no,m.rapor_tarihi,m.sozlesme_fiyatlariyla_hizmet,m.fiyat_farki,m.onceki_hakedis_toplami,m.kdv_orani,m.damga_vergisi FROM hakedisler h LEFT JOIN hakedis_mali_ozetleri m ON m.hakedis_id=h.id WHERE h.id=?');$q->execute([$id]);$row=$q->fetch();if(!$row)throw new RuntimeException('Hakediş kaydı bulunamadı.');return$row;}
    public function updateProgress(int$id,array$data):void
    {
        $old=$this->progressEditData($id);$status=(string)($data['durum']??$old['durum']);if(!in_array($status,['taslak','onayli','iptal'],true))throw new RuntimeException('Hakediş durumu geçersiz.');
        $reportDate=(string)($data['rapor_tarihi']??$old['rapor_tarihi']);if(!$this->validDate($reportDate))throw new RuntimeException('Rapor tarihi geçersiz.');
        $number=static fn($value):float=>round((float)str_replace(',','.',(string)$value),2);$service=$number($data['sozlesme_fiyatlariyla_hizmet']??$old['sozlesme_fiyatlariyla_hizmet']);$price=$number($data['fiyat_farki']??$old['fiyat_farki']);$previous=$number($data['onceki_hakedis_toplami']??$old['onceki_hakedis_toplami']);$vatRate=round((float)str_replace(',','.',(string)($data['kdv_orani']??$old['kdv_orani'])),4);$stamp=$number($data['damga_vergisi']??$old['damga_vergisi']);if(min($service,$price,$previous,$vatRate,$stamp)<0)throw new RuntimeException('Tutarlar negatif olamaz.');
        $current=round($service+$price-$previous,2);$vat=round($current*$vatRate/100,2);$accrual=round($previous+$current+$vat,2);$deduction=$stamp;$payable=round($accrual-$deduction,2);$reportNo=trim((string)($data['hak_edis_no']??$old['hak_edis_no']));
        $this->db->beginTransaction();try{$this->db->prepare('UPDATE hakedisler SET durum=?,toplam_tutar=? WHERE id=?')->execute([$status,$current,$id]);$this->db->prepare('UPDATE hakedis_mali_ozetleri SET hak_edis_no=?,rapor_tarihi=?,sozlesme_fiyatlariyla_hizmet=?,fiyat_farki=?,onceki_hakedis_toplami=?,bu_hakedis_tutari=?,kdv_orani=?,kdv_tutari=?,tahakkuk_tutari=?,damga_vergisi=?,kesinti_toplami=?,odenecek_tutar=? WHERE hakedis_id=?')->execute([$reportNo,$reportDate,$service,$price,$previous,$current,$vatRate,$vat,$accrual,$stamp,$deduction,$payable,$id]);$this->log('guncelle','hakedisler',$id,null,json_encode($old,JSON_UNESCAPED_UNICODE),json_encode($data,JSON_UNESCAPED_UNICODE));$this->db->commit();}catch(Throwable$e){$this->db->rollBack();throw$e;}
    }
    public function deleteProgress(int$id):void
    {
        $old=$this->progressEditData($id);$this->db->beginTransaction();try{$this->db->prepare('DELETE FROM hakedis_genel_kalemleri WHERE hakedis_id=?')->execute([$id]);$this->db->prepare('DELETE FROM hakedis_mali_ozetleri WHERE hakedis_id=?')->execute([$id]);$this->db->prepare('DELETE FROM hakedis_detaylari WHERE hakedis_id=?')->execute([$id]);$this->db->prepare('DELETE FROM hakedis_bordrolari WHERE hakedis_id=?')->execute([$id]);$this->db->prepare('DELETE FROM hakedisler WHERE id=?')->execute([$id]);if((int)$old['aktif']===1){$q=$this->db->prepare('SELECT id FROM hakedisler WHERE yil=? AND ay=? AND sozlesme_id=? ORDER BY surum DESC LIMIT 1');$q->execute([(int)$old['yil'],(int)$old['ay'],(int)$old['sozlesme_id']]);$previousId=(int)$q->fetchColumn();if($previousId)$this->db->prepare('UPDATE hakedisler SET aktif=1 WHERE id=?')->execute([$previousId]);}$this->log('sil','hakedisler',$id,null,json_encode($old,JSON_UNESCAPED_UNICODE),null);$this->db->commit();}catch(Throwable$e){$this->db->rollBack();throw$e;}
    }
    public function simpleProgressReport(int$year,int$month):array
    {
        $q=$this->db->prepare('SELECT h.id,h.yil,h.ay,h.surum,h.toplam_tutar,s.ad sozlesme_adi,m.*,h.id hakedis_id FROM hakedisler h JOIN sozlesmeler s ON s.id=h.sozlesme_id JOIN hakedis_mali_ozetleri m ON m.hakedis_id=h.id WHERE h.yil=? AND h.ay=? AND h.aktif=1 ORDER BY h.surum DESC LIMIT 1');$q->execute([$year,$month]);$row=$q->fetch();if(!$row)throw new RuntimeException('Seçilen dönem için hakediş raporu bulunamadı.');
        $general=$this->db->prepare('SELECT kalem_adi,bu_hakedis_tutari FROM hakedis_genel_kalemleri WHERE hakedis_id=?');$general->execute([$row['hakedis_id']]);foreach($general->fetchAll()as$item){if($item['kalem_adi']==='SÖZLEŞME GENEL GİDERLERİ')$row['sozlesme_genel_giderleri']=(float)$item['bu_hakedis_tutari'];elseif($item['kalem_adi']==='YÜKLENİCİ KARI')$row['yuklenici_kari']=(float)$item['bu_hakedis_tutari'];elseif($item['kalem_adi']==='GENEL TOPLAM (KDV HARİÇ)')$row['genel_toplam_kdv_haric']=(float)$item['bu_hakedis_tutari'];}
        $row['sozlesme_genel_giderleri']??=0.0;$row['yuklenici_kari']??=0.0;$row['genel_toplam_kdv_haric']??=(float)$row['bu_hakedis_tutari'];return$row;
    }
    private function syncRightToPersonnel(int$rightId):void{$sql="INSERT INTO personel_donem_haklari(personel_id,sendikal_hak_id,tis_donem_id,birim,birim_fiyat,hesaplama_sekli,hakedise_dahil,gecerlilik_baslangic,gecerlilik_bitis,aktif) SELECT ig.personel_id,h.id,h.tis_donem_id,h.birim,h.birim_fiyat,h.hesaplama_sekli,h.hakedise_dahil,h.gecerlilik_baslangic,h.gecerlilik_bitis,1 FROM sendikal_haklar h JOIN personel_is_gecmisi ig ON ig.tis_donem_id=h.tis_donem_id AND ig.aktif=1 JOIN personeller p ON p.id=ig.personel_id AND p.aktif=1 WHERE h.id=? ON DUPLICATE KEY UPDATE birim=VALUES(birim),birim_fiyat=VALUES(birim_fiyat),hesaplama_sekli=VALUES(hesaplama_sekli),hakedise_dahil=VALUES(hakedise_dahil),gecerlilik_baslangic=VALUES(gecerlilik_baslangic),gecerlilik_bitis=VALUES(gecerlilik_bitis),aktif=1";$this->db->prepare($sql)->execute([$rightId]);}
    private function validDate(string$value):bool{$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);return$date!==false&&$date->format('Y-m-d')===$value;}
    private function uuid():string{$b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);$hex=bin2hex($b);return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);}
    private function linkJob(int$isId,int$contractId):void{$this->db->prepare("INSERT INTO sozlesme_isleri(sozlesme_id,is_id) VALUES(?,?) ON DUPLICATE KEY UPDATE aktif=1")->execute([$contractId,$isId]);}
    private function find(string$table,int$id):array{$stmt=$this->db->prepare("SELECT * FROM {$table} WHERE id=?");$stmt->execute([$id]);return$stmt->fetch()?:[];}
    private function logChanges(string$table,int$id,array$old,array$new):void{foreach($new as$f=>$v)if($f!=='id'&&(string)($old[$f]??'')!==(string)($v??''))$this->log('guncelle',$table,$id,$f,(string)($old[$f]??''),(string)($v??''));}
    private function log(string$action,string$table,?int$id,?string$field,?string$old,?string$new):void{$this->db->prepare("INSERT INTO islem_loglari(kullanici_id,islem,tablo_adi,kayit_id,alan_adi,eski_deger,yeni_deger,ip_adresi) VALUES(?,?,?,?,?,?,?,?)")->execute([$this->userId,$action,$table,$id,$field,$old,$new,$this->ip]);}
}
