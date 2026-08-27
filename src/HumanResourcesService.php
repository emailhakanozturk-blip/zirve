<?php
declare(strict_types=1);

namespace PersonelHakedis;

use DateTimeImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

final class HumanResourcesService
{
    private ?array $privateProfiles = null;

    public function __construct(private PDO $db, private ?int $userId, private ?string $ip) {}

    public function overview(array $filters = []): array
    {
        $employees = $this->employees($filters);
        return ['summary'=>$this->summary(),'employees'=>$employees,'distributions'=>$this->distributions()];
    }

    public function employees(array $filters = []): array
    {
        $sql = $this->employeeSelect() . ' WHERE 1=1';
        $params = [];
        $status = (string)($filters['durum'] ?? 'active');
        if ($status === 'active') $sql .= ' AND p.aktif=1';
        if ($status === 'passive') $sql .= ' AND p.aktif=0';
        $search = trim((string)($filters['ara'] ?? ''));
        if ($search !== '') {
            $sql .= " AND CONCAT_WS(' ',p.ad_soyad,p.sgk_sicil_no,pr.personel_kodu,pr.unvan,pr.isyeri_adi) LIKE :search";
            $params['search'] = '%' . $search . '%';
        }
        $sql .= ' GROUP BY p.id ORDER BY p.aktif DESC,p.ad_soyad';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return array_map([$this,'normalizeEmployee'], $statement->fetchAll());
    }

    public function detail(int $id): array
    {
        $statement = $this->db->prepare($this->employeeSelect() . ' WHERE p.id=? GROUP BY p.id');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if (!$row) throw new RuntimeException('Personel bulunamadı.');
        $employee = array_merge($this->normalizeEmployee($row),$this->privateProfile($id));
        $leaves = $this->db->prepare('SELECT id,izin_turu,birim,sure_gun,baslangic_tarihi,bitis_tarihi,aciklama FROM ik_izin_kayitlari WHERE personel_id=? AND aktif=1 ORDER BY baslangic_tarihi DESC,id DESC');
        $leaves->execute([$id]);
        $employee['izinler'] = $leaves->fetchAll();
        return $employee;
    }

    public function saveProfile(array $data): void
    {
        $id = (int)($data['personel_id'] ?? 0);
        if ($id < 1 || !$this->personExists($id)) throw new RuntimeException('Personel seçimi geçersiz.');
        $fields = ['personel_kodu','unvan','isyeri_adi','cinsiyet','medeni_hali','ogrenim_durumu','hesaplama_sekli','meslek_kodu','meslek_kodu_tanimi'];
        $limits = ['personel_kodu'=>50,'unvan'=>150,'isyeri_adi'=>200,'cinsiyet'=>40,'medeni_hali'=>60,'ogrenim_durumu'=>120,'hesaplama_sekli'=>120,'meslek_kodu'=>50,'meslek_kodu_tanimi'=>200];
        $values = [];
        foreach ($fields as $field) $values[$field] = mb_substr(trim((string)($data[$field] ?? '')),0,$limits[$field]) ?: null;
        $wage = preg_replace('/[^0-9,.-]/','',trim((string)($data['ucret_tutari'] ?? ''))) ?? '';
        if (str_contains($wage, ',') && str_contains($wage, '.')) $wage = str_replace(',','.',str_replace('.','',$wage));
        elseif (str_contains($wage, ',')) $wage = str_replace(',','.',$wage);
        $values['ucret_tutari'] = $wage === '' ? null : round((float)$wage,2);
        $values['taseron_nakil'] = !empty($data['taseron_nakil']) ? 1 : 0;
        $sql = 'INSERT INTO ik_personel_profilleri(personel_id,personel_kodu,unvan,isyeri_adi,cinsiyet,medeni_hali,ogrenim_durumu,hesaplama_sekli,meslek_kodu,meslek_kodu_tanimi,ucret_tutari,taseron_nakil,created_by,updated_by)
                VALUES(:personel_id,:personel_kodu,:unvan,:isyeri_adi,:cinsiyet,:medeni_hali,:ogrenim_durumu,:hesaplama_sekli,:meslek_kodu,:meslek_kodu_tanimi,:ucret_tutari,:taseron_nakil,:created_by,:updated_by)
                ON DUPLICATE KEY UPDATE personel_kodu=VALUES(personel_kodu),unvan=VALUES(unvan),isyeri_adi=VALUES(isyeri_adi),cinsiyet=VALUES(cinsiyet),medeni_hali=VALUES(medeni_hali),ogrenim_durumu=VALUES(ogrenim_durumu),hesaplama_sekli=VALUES(hesaplama_sekli),meslek_kodu=VALUES(meslek_kodu),meslek_kodu_tanimi=VALUES(meslek_kodu_tanimi),ucret_tutari=VALUES(ucret_tutari),taseron_nakil=VALUES(taseron_nakil),updated_by=VALUES(updated_by)';
        $values += ['personel_id'=>$id,'created_by'=>$this->userId,'updated_by'=>$this->userId];
        $this->db->prepare($sql)->execute($values);
        $this->log('ik_profil_guncelle','ik_personel_profilleri',$id);
    }

    public function saveLeave(array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        $personId = (int)($data['personel_id'] ?? 0);
        $type = mb_substr(trim((string)($data['izin_turu'] ?? '')),0,120);
        $days = round((float)str_replace(',','.',(string)($data['sure_gun'] ?? 0)),2);
        $start = $this->date((string)($data['baslangic_tarihi'] ?? ''));
        $end = $this->date((string)($data['bitis_tarihi'] ?? ''));
        if (!$this->personExists($personId) || $type === '') throw new RuntimeException('Personel ve izin türü zorunludur.');
        if ($days < 0 || ($start && $end && $end < $start)) throw new RuntimeException('İzin gün veya tarih aralığı geçersiz.');
        $params = [$personId,$type,mb_substr(trim((string)($data['birim'] ?? '')),0,180) ?: null,$days,$start,$end,mb_substr(trim((string)($data['aciklama'] ?? '')),0,500) ?: null,$this->userId];
        if ($id > 0) {
            $params[] = $id;
            $this->db->prepare('UPDATE ik_izin_kayitlari SET personel_id=?,izin_turu=?,birim=?,sure_gun=?,baslangic_tarihi=?,bitis_tarihi=?,aciklama=?,updated_by=? WHERE id=? AND aktif=1')->execute($params);
        } else {
            $this->db->prepare('INSERT INTO ik_izin_kayitlari(personel_id,izin_turu,birim,sure_gun,baslangic_tarihi,bitis_tarihi,aciklama,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?)')->execute(array_merge(array_slice($params,0,7),[$this->userId,$this->userId]));
            $id = (int)$this->db->lastInsertId();
        }
        $this->log('ik_izin_kaydet','ik_izin_kayitlari',$id);
        return $id;
    }

    public function archiveLeave(int $id): void
    {
        $this->db->prepare('UPDATE ik_izin_kayitlari SET aktif=0,updated_by=? WHERE id=? AND aktif=1')->execute([$this->userId,$id]);
        $this->log('ik_izin_arsivle','ik_izin_kayitlari',$id);
    }

    public function importLeaves(string $file): array
    {
        $sheet = IOFactory::load($file)->getActiveSheet();
        $headerRow = 1;
        $headers = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1',null,true,true,false)[0] ?? [];
        if (!$this->looksLikeLeaveHeader($headers)) {
            $headerRow = 2;
            $headers = $sheet->rangeToArray('A2:'.$sheet->getHighestColumn().'2',null,true,true,false)[0] ?? [];
        }
        if (!$this->looksLikeLeaveHeader($headers)) throw new RuntimeException('Excel başlıkları tanınmadı. Sicil No/Ad Soyad, İzin Türü ve Gün sütunlarını kullanın.');
        $map = [];
        foreach ($headers as $index=>$label) $map[$this->headerKey((string)$label)] = $index;
        $rows = $sheet->rangeToArray('A'.($headerRow+1).':'.$sheet->getHighestColumn().$sheet->getHighestRow(),null,true,true,false);
        $inserted = 0; $unmatched = [];
        $this->db->beginTransaction();
        try {
            foreach ($rows as $offset=>$row) {
                $code = trim((string)($row[$map['sicilno'] ?? -1] ?? ''));
                $name = trim((string)($row[$map['adsoyad'] ?? -1] ?? ''));
                if ($code === '' && $name === '') continue;
                $personId = $this->findPerson($code,$name);
                if (!$personId) {$unmatched[]=['satir'=>$headerRow+2+$offset,'sicil'=>$code,'ad_soyad'=>$name];continue;}
                $this->saveLeave([
                    'personel_id'=>$personId,
                    'izin_turu'=>$row[$map['izinturu'] ?? -1] ?? 'Belirtilmemiş',
                    'birim'=>$row[$map['birim'] ?? -1] ?? null,
                    'sure_gun'=>$row[$map['gun'] ?? -1] ?? 0,
                    'baslangic_tarihi'=>$this->excelDate($row[$map['baslangic'] ?? -1] ?? null),
                    'bitis_tarihi'=>$this->excelDate($row[$map['bitis'] ?? -1] ?? null),
                    'aciklama'=>'Excel satırı '.($headerRow+2+$offset),
                ]);
                $inserted++;
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
        return ['inserted'=>$inserted,'unmatched'=>$unmatched];
    }

    public function importPersonnelData(string $file): array
    {
        $sheet=IOFactory::load($file)->getActiveSheet();$headerRow=0;$headers=[];
        for($row=1;$row<=min(15,$sheet->getHighestRow());$row++){$candidate=$sheet->rangeToArray('A'.$row.':'.$sheet->getHighestColumn().$row,null,true,true,false)[0]??[];$keys=array_map(fn($value)=>$this->headerKey((string)$value),$candidate);if(in_array('personeladisoyadi',$keys,true)&&in_array('tckimlikno',$keys,true)){$headerRow=$row;$headers=$candidate;break;}}
        if($headerRow<1)throw new RuntimeException('Personel raporu başlıkları tanınmadı. PERSONEL ADI SOYADI ve TC KIMLIK NO sütunları zorunludur.');
        $map=[];foreach($headers as$index=>$label)$map[$this->headerKey((string)$label)]=$index;$rows=$sheet->rangeToArray('A'.($headerRow+1).':'.$sheet->getHighestColumn().$sheet->getHighestRow(),null,true,true,false);
        $profiles=$this->privateProfiles();$created=0;$updated=0;$skipped=[];$seen=[];
        $find=$this->db->prepare('SELECT id FROM personeller WHERE tc_kimlik_no=? LIMIT 1');
        $insertPerson=$this->db->prepare("INSERT INTO personeller(ad_soyad,tc_kimlik_no,ise_giris_tarihi,aktif,aciklama) VALUES(?,?,?,1,'İK personel veri raporundan oluşturuldu.')");
        $updatePerson=$this->db->prepare('UPDATE personeller SET ad_soyad=?,ise_giris_tarihi=COALESCE(?,ise_giris_tarihi) WHERE id=?');
        $saveProfile=$this->db->prepare('INSERT INTO ik_personel_profilleri(personel_id,unvan,isyeri_adi,cinsiyet,medeni_hali,ogrenim_durumu,hesaplama_sekli,ucret_tutari,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE unvan=VALUES(unvan),isyeri_adi=VALUES(isyeri_adi),cinsiyet=VALUES(cinsiyet),medeni_hali=VALUES(medeni_hali),ogrenim_durumu=VALUES(ogrenim_durumu),hesaplama_sekli=VALUES(hesaplama_sekli),ucret_tutari=VALUES(ucret_tutari),updated_by=VALUES(updated_by)');
        $this->db->beginTransaction();
        try{foreach($rows as$offset=>$row){$line=$headerRow+1+$offset;$tc=preg_replace('/\D/','',(string)($row[$map['tckimlikno']??-1]??''));$name=trim((string)($row[$map['personeladisoyadi']??-1]??''));if($tc===''&&$name==='')continue;if(strlen($tc)!==11||$name===''){$skipped[]=['satir'=>$line,'neden'=>'T.C. kimlik numarası veya ad soyad geçersiz'];continue;}if(isset($seen[$tc])){$skipped[]=['satir'=>$line,'neden'=>'Dosyada mükerrer T.C. kimlik numarası'];continue;}$seen[$tc]=true;
            $entry=$this->excelDate($row[$map['isegiristarihi']??-1]??null);$find->execute([$tc]);$personId=(int)$find->fetchColumn();if($personId){$updatePerson->execute([$name,$entry,$personId]);$updated++;}else{if(!$entry){$skipped[]=['satir'=>$line,'neden'=>'Yeni personel için işe giriş tarihi eksik'];continue;}$insertPerson->execute([$name,$tc,$entry]);$personId=(int)$this->db->lastInsertId();$created++;}
            $minimum=trim((string)($row[$map['asgariucretli']??-1]??''));$other=trim((string)($row[$map['digerucretli']??-1]??''));$wage=$this->moneyValue($other);$calculation=mb_strtolower($minimum,'UTF-8')==='evet'?'Asgari Ücret':($other!==''?(preg_match('/\sB$/u',$other)?'Diğer Ücret (Brüt)':(preg_match('/\sN$/u',$other)?'Diğer Ücret (Net)':'Diğer Ücret')):'Belirtilmemiş');
            $value=fn(string$key):string=>trim((string)($row[$map[$key]??-1]??''));$saveProfile->execute([$personId,$value('unvani')?:null,$value('isyeriadi')?:null,$value('personelcinsiyet')?:null,$value('medenidurumu')?:null,$value('egitimdurumu')?:null,$calculation,$wage,$this->userId,$this->userId]);
            $profiles[(string)$personId]=['proje_bolge'=>$value('projebolge'),'dogum_tarihi'=>$this->excelDate($row[$map['dogumtarihi']??-1]??null),'calisma_suresi'=>$value('calistigizamansuresi'),'asgari_ucretli'=>$minimum,'diger_ucretli'=>$other,'banka_hesap_no'=>$value('bankahesapno'),'iban'=>$value('bankaibanno'),'mezuniyet_bolumu'=>$value('mezuniyetbolumu'),'mezuniyet_tarihi'=>$this->excelDate($row[$map['mezuniyettarihi']??-1]??null),'cocuk_sayisi'=>$value('evliysecocuksayisi'),'normal'=>$value('normal'),'emekli'=>$value('emekli'),'engellilik_orani'=>$value('engellicalisaniseorani'),'uyruk'=>$value('yabancicalisaniseuyrugu'),'kan_grubu'=>$value('kangrubu'),'tesvik_maddesi'=>$value('tabioldugutesvikmaddesi'),'adres'=>$value('ikametgahadresi'),'il_ilce'=>$value('ililce'),'telefon'=>$value('telefonnumarasi'),'eposta'=>$value('emailadresi'),'kaynak_dosya'=>basename($file),'guncelleme_tarihi'=>date('c')];
        }$this->savePrivateProfiles($profiles);$this->db->commit();$this->privateProfiles=$profiles;}catch(Throwable$exception){if($this->db->inTransaction())$this->db->rollBack();throw$exception;}
        $this->log('ik_personel_veri_aktar','personeller',0);return['read'=>count($seen),'created'=>$created,'updated'=>$updated,'skipped'=>$skipped];
    }

    public function reports(?int $year = null): array
    {
        $employees=array_map(fn($row)=>array_merge($row,array_intersect_key($this->privateProfile((int)$row['id']),array_flip(['kan_grubu','emekli']))),$this->employees(['durum'=>'active']));return ['summary'=>$this->summary(),'distributions'=>$this->distributions(),'employees'=>$employees,'earnings'=>$this->monthlyEarnings($year)];
    }

    public function excel(string $mode = 'employees'): string
    {
        $rows = $this->employees(['durum'=>'all']);if($mode==='report')$rows=array_map(fn($row)=>array_merge($row,$this->privateProfile((int)$row['id'])),$rows);
        $headers = $mode === 'report'
            ? ['Sicil No','Adı','Soyadı','Unvan','İşyeri Adı','Cinsiyet','Medeni Hali','Öğrenim Durumu','Hesaplama Şekli','Ücret Tutarı','Doğum Tarihi','Kan Grubu','Emekli','Engellilik Oranı','Uyruğu','Çocuk Sayısı','Telefon','E-posta','İl/İlçe','Adres']
            : ['Sicil No','Adı','Soyadı','Unvan','İşyeri Adı','Durum','İzin/Rapor','Toplam Gün'];
        $data = array_map(static fn(array $r): array => $mode === 'report'
            ? [$r['personel_kodu'],$r['adi'],$r['soyadi'],$r['unvan'],$r['isyeri_adi'],$r['cinsiyet'],$r['medeni_hali'],$r['ogrenim_durumu'],$r['hesaplama_sekli'],$r['ucret_tutari'],$r['dogum_tarihi']??'',$r['kan_grubu']??'',$r['emekli']??'',$r['engellilik_orani']??'',$r['uyruk']??'',$r['cocuk_sayisi']??'',$r['telefon']??'',$r['eposta']??'',$r['il_ilce']??'',$r['adres']??'']
            : [$r['personel_kodu'],$r['adi'],$r['soyadi'],$r['unvan'],$r['isyeri_adi'],$r['aktif']?'Aktif':'Pasif',$r['izin_sayisi'],$r['izin_gun']], $rows);
        $book = new Spreadsheet(); $sheet = $book->getActiveSheet();
        $sheet->setTitle($mode === 'report' ? 'İK Raporu' : 'Personel Listesi');
        $sheet->fromArray($headers,null,'A1'); if ($data) $sheet->fromArray($data,null,'A2');
        $sheet->freezePane('A2'); $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);
        foreach (range(1, count($headers)) as $column) $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        $path = tempnam(sys_get_temp_dir(),'zirve-ik-').'.xlsx'; (new Xlsx($book))->save($path); return $path;
    }

    public function pdf(string $type): string
    {
        $titles=['gender'=>'Cinsiyete Göre Dağılım','workplace'=>'İşyerine Göre Dağılım','title'=>'Unvana Göre Dağılım','marital'=>'Medeni Hale Göre Dağılım','education'=>'Öğrenim Durumuna Göre Dağılım','blood'=>'Kan Grubuna Göre Dağılım','retirement'=>'Emeklilik Durumuna Göre Dağılım','calculation'=>'Hesaplama Şekline Göre Dağılım','transfer'=>'Taşeron Nakil Personeli'];
        if (!isset($titles[$type])) $type='gender';
        $rows=$this->distributions()[$type]??[];$maximum=max(array_column($rows,'total')?:[1]);$body='';
        foreach($rows as$row){$label=htmlspecialchars((string)$row['label'],ENT_QUOTES,'UTF-8');$total=(int)$row['total'];$width=max(2,$total/$maximum*100);$body.='<tr><td>'.$label.'</td><td><div class="track"><span style="width:'.$width.'%"></span></div></td><td>'.$total.'</td></tr>';}
        $html='<!doctype html><html lang="tr"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans;color:#173f6c;padding:24px}h1{font-size:22px}table{width:100%;border-collapse:collapse}th{background:#173f6c;color:white;padding:10px;text-align:left}td{padding:11px;border-bottom:1px solid #dde7f0}.track{height:13px;background:#eaf0f6;border-radius:8px}.track span{display:block;height:100%;background:#1c8f89;border-radius:8px}</style></head><body><h1>Zirve · İnsan Kaynakları</h1><p>'.$titles[$type].' · '.date('d.m.Y H:i').'</p><table><tr><th>Dağılım</th><th>Grafik</th><th>Personel</th></tr>'.$body.'</table></body></html>';
        $options=new Options();$options->set('defaultFont','DejaVu Sans');$pdf=new Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4');$pdf->render();$path=tempnam(sys_get_temp_dir(),'zirve-ik-').'.pdf';file_put_contents($path,$pdf->output());return $path;
    }

    private function employeeSelect(): string
    {
        return "SELECT p.id,p.ad_soyad,p.tc_kimlik_no,p.sgk_sicil_no,p.ise_giris_tarihi,p.isten_cikis_tarihi,p.aktif,
            COALESCE(NULLIF(pr.personel_kodu,''),NULLIF(p.sgk_sicil_no,''),CONCAT('P-',LPAD(p.id,5,'0'))) personel_kodu,
            COALESCE(NULLIF(pr.unvan,''),(SELECT i.ad FROM personel_is_gecmisi g JOIN is_tanimlari i ON i.id=g.is_id WHERE g.personel_id=p.id AND g.aktif=1 ORDER BY g.baslangic_tarihi DESC,g.id DESC LIMIT 1),'Personel') unvan,
            COALESCE(NULLIF(pr.isyeri_adi,''),(SELECT s.ad FROM personel_is_gecmisi g JOIN sozlesmeler s ON s.id=g.sozlesme_id WHERE g.personel_id=p.id AND g.aktif=1 ORDER BY g.baslangic_tarihi DESC,g.id DESC LIMIT 1),'Belirtilmemiş') isyeri_adi,
            pr.cinsiyet,pr.medeni_hali,pr.ogrenim_durumu,pr.hesaplama_sekli,pr.meslek_kodu,pr.meslek_kodu_tanimi,pr.ucret_tutari,COALESCE(pr.taseron_nakil,0) taseron_nakil,
            COUNT(iz.id) izin_sayisi,COALESCE(SUM(iz.sure_gun),0) izin_gun
            FROM personeller p LEFT JOIN ik_personel_profilleri pr ON pr.personel_id=p.id LEFT JOIN ik_izin_kayitlari iz ON iz.personel_id=p.id AND iz.aktif=1";
    }

    private function normalizeEmployee(array $row): array
    {
        $parts=preg_split('/\s+/',trim((string)$row['ad_soyad']))?:[];$row['adi']=$parts[0]??'';$row['soyadi']=count($parts)>1?array_pop($parts):'';
        foreach(['cinsiyet','medeni_hali','ogrenim_durumu','hesaplama_sekli','meslek_kodu','meslek_kodu_tanimi'] as$field)$row[$field]=$row[$field]??'';
        return $row;
    }

    private function summary(): array
    {
        $people=$this->db->query('SELECT COUNT(*) toplam_personel,COALESCE(SUM(aktif=1),0) aktif_personel FROM personeller')->fetch()?:[];
        $leave=$this->db->query('SELECT COUNT(*) izin_kaydi,COALESCE(SUM(sure_gun),0) toplam_gun FROM ik_izin_kayitlari WHERE aktif=1')->fetch()?:[];
        return $people+$leave;
    }

    private function distributions(): array
    {
        $rows=$this->employees(['durum'=>'active']);$definitions=['gender'=>'cinsiyet','workplace'=>'isyeri_adi','title'=>'unvan','marital'=>'medeni_hali','education'=>'ogrenim_durumu','calculation'=>'hesaplama_sekli'];$result=[];
        foreach($definitions as$key=>$field){$counts=[];foreach($rows as$row){$label=trim((string)($row[$field]??''))?:'Belirtilmemiş';$counts[$label]=($counts[$label]??0)+1;}arsort($counts);$result[$key]=array_map(static fn($label,$total)=>['label'=>$label,'total'=>$total],array_keys($counts),array_values($counts));}
        $transfer=count(array_filter($rows,static fn($r)=>(int)$r['taseron_nakil']===1));$result['transfer']=[['label'=>'Taşeron Nakil (X)','total'=>$transfer]];$activeIds=array_fill_keys(array_map(static fn($row)=>(string)$row['id'],$rows),true);foreach(['blood'=>'kan_grubu','retirement'=>'emekli']as$key=>$field){$counts=[];foreach($this->privateProfiles()as$id=>$profile)if(isset($activeIds[(string)$id])){$label=trim((string)($profile[$field]??''))?:'Belirtilmemiş';$counts[$label]=($counts[$label]??0)+1;}arsort($counts);$result[$key]=array_map(static fn($label,$total)=>['label'=>$label,'total'=>$total],array_keys($counts),array_values($counts));}return$result;
    }

    private function monthlyEarnings(?int $year): array
    {
        $years=array_map('intval',$this->db->query('SELECT DISTINCT yil FROM bordrolar WHERE aktif=1 ORDER BY yil DESC')->fetchAll(PDO::FETCH_COLUMN));
        $selected=$year && in_array($year,$years,true)?$year:($years[0]??(int)date('Y'));
        $statement=$this->db->prepare("SELECT p.id,p.ad_soyad,COALESCE(NULLIF(pr.personel_kodu,''),NULLIF(p.sgk_sicil_no,''),CONCAT('P-',LPAD(p.id,5,'0'))) personel_kodu,b.ay,ROUND(SUM(bk.tutar),2) toplam_kazanc
            FROM bordrolar b
            JOIN bordro_personelleri bp ON bp.bordro_id=b.id AND bp.personel_id IS NOT NULL
            JOIN bordro_kalemleri bk ON bk.bordro_personel_id=bp.id AND UPPER(TRIM(bk.kalem_adi))='TOPLAM KAZANÇ'
            JOIN personeller p ON p.id=bp.personel_id
            LEFT JOIN ik_personel_profilleri pr ON pr.personel_id=p.id
            WHERE b.aktif=1 AND b.yil=?
            GROUP BY p.id,p.ad_soyad,pr.personel_kodu,p.sgk_sicil_no,b.ay
            ORDER BY p.ad_soyad,b.ay");
        $statement->execute([$selected]);$people=[];$monthTotals=array_fill(1,12,0.0);
        foreach($statement->fetchAll()as$row){$id=(int)$row['id'];if(!isset($people[$id]))$people[$id]=['personel_id'=>$id,'personel_kodu'=>$row['personel_kodu'],'ad_soyad'=>$row['ad_soyad'],'months'=>array_fill(1,12,0.0),'total'=>0.0];$month=(int)$row['ay'];$amount=round((float)$row['toplam_kazanc'],2);if($month>=1&&$month<=12){$people[$id]['months'][$month]=$amount;$people[$id]['total']=round($people[$id]['total']+$amount,2);$monthTotals[$month]=round($monthTotals[$month]+$amount,2);}}
        return['year'=>$selected,'years'=>$years?:[$selected],'rows'=>array_values($people),'month_totals'=>$monthTotals,'grand_total'=>round(array_sum($monthTotals),2)];
    }

    private function privateProfile(int $personId): array {return $this->privateProfiles()[(string)$personId]??[];}
    private function privateProfiles(): array
    {
        if($this->privateProfiles!==null)return$this->privateProfiles;$path=$this->privateProfilePath();if(!is_file($path))return$this->privateProfiles=[];$decoded=json_decode((string)file_get_contents($path),true);if(!is_array($decoded))throw new RuntimeException('Özel İK veri dosyası okunamadı.');if(($decoded['format']??'')==='zirve-hr-encrypted-v1'){$iv=base64_decode((string)($decoded['iv']??''),true);$tag=base64_decode((string)($decoded['tag']??''),true);$ciphertext=base64_decode((string)($decoded['data']??''),true);if($iv===false||$tag===false||$ciphertext===false)throw new RuntimeException('Şifreli İK veri dosyası bozuk.');$plain=openssl_decrypt($ciphertext,'aes-256-gcm',$this->privateEncryptionKey(),OPENSSL_RAW_DATA,$iv,$tag);if($plain===false)throw new RuntimeException('Şifreli İK verileri çözülemedi. Anahtar dosyasını kontrol edin.');$profiles=json_decode($plain,true);if(!is_array($profiles))throw new RuntimeException('Şifreli İK verisinin içeriği geçersiz.');return$this->privateProfiles=$profiles;}$this->savePrivateProfiles($decoded);return$this->privateProfiles=$decoded;
    }
    private function savePrivateProfiles(array $profiles): void
    {
        $path=$this->privateProfilePath();$directory=dirname($path);$this->ensurePrivateDirectory($directory);$temporary=tempnam($directory,'hr-data-');if($temporary===false)throw new RuntimeException('Özel İK veri dosyası hazırlanamadı.');$plain=json_encode($profiles,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);$iv=random_bytes(12);$tag='';$ciphertext=$plain===false?false:openssl_encrypt($plain,'aes-256-gcm',$this->privateEncryptionKey(),OPENSSL_RAW_DATA,$iv,$tag);$envelope=$ciphertext===false?false:json_encode(['format'=>'zirve-hr-encrypted-v1','cipher'=>'AES-256-GCM','iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'data'=>base64_encode($ciphertext)],JSON_PRETTY_PRINT);if($envelope===false||file_put_contents($temporary,$envelope,LOCK_EX)===false){@unlink($temporary);throw new RuntimeException('Özel İK verileri şifrelenemedi.');}if(!@rename($temporary,$path)){@unlink($temporary);throw new RuntimeException('Özel İK veri dosyası güncellenemedi.');}@chmod($path,0600);
    }
    private function privateProfilePath(): string {return dirname(__DIR__).'/storage/private/hr-personnel-data.json';}
    private function privateEncryptionKey(): string
    {
        $directory=dirname($this->privateProfilePath());$this->ensurePrivateDirectory($directory);$path=$directory.'/hr-personnel.key';if(is_file($path)){$key=base64_decode(trim((string)file_get_contents($path)),true);if($key!==false&&strlen($key)===32)return$key;throw new RuntimeException('Özel İK şifreleme anahtarı geçersiz.');}$key=random_bytes(32);if(file_put_contents($path,base64_encode($key),LOCK_EX)===false)throw new RuntimeException('Özel İK şifreleme anahtarı oluşturulamadı.');@chmod($path,0600);return$key;
    }
    private function ensurePrivateDirectory(string $directory): void
    {
        if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Özel İK veri klasörü oluşturulamadı.');$protection="Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";if(file_put_contents($directory.'/.htaccess',$protection,LOCK_EX)===false)throw new RuntimeException('Özel İK veri koruması yazılamadı.');
    }
    private function moneyValue(mixed $value): ?float
    {
        $text=trim((string)$value);if($text==='')return null;$text=preg_replace('/[^0-9,.-]/u','',$text)??'';if($text==='')return null;if(str_contains($text,',')&&str_contains($text,'.'))$text=str_replace(['.',','],['','.'],$text);elseif(str_contains($text,','))$text=str_replace(',','.',$text);return round((float)$text,2);
    }

    private function personExists(int $id): bool {$s=$this->db->prepare('SELECT 1 FROM personeller WHERE id=?');$s->execute([$id]);return(bool)$s->fetchColumn();}
    private function findPerson(string $code,string $name): int {$s=$this->db->prepare('SELECT p.id FROM personeller p LEFT JOIN ik_personel_profilleri pr ON pr.personel_id=p.id WHERE (?<>\'\' AND (p.sgk_sicil_no=? OR pr.personel_kodu=?)) OR (?<>\'\' AND UPPER(TRIM(p.ad_soyad))=UPPER(TRIM(?))) LIMIT 1');$s->execute([$code,$code,$code,$name,$name]);return(int)($s->fetchColumn()?:0);}
    private function date(string $value): ?string {if($value==='')return null;$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new RuntimeException('Tarih biçimi geçersiz.');return$value;}
    private function excelDate(mixed $value): ?string {if($value===null||$value==='')return null;if(is_numeric($value))return\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value)->format('Y-m-d');foreach(['Y-m-d','d.m.Y','d/m/Y']as$f){$d=DateTimeImmutable::createFromFormat('!'.$f,trim((string)$value));if($d)return$d->format('Y-m-d');}return null;}
    private function headerKey(string $value): string {$value=mb_strtolower(trim($value),'UTF-8');$value=strtr($value,['ı'=>'i','ş'=>'s','ğ'=>'g','ü'=>'u','ö'=>'o','ç'=>'c']);return preg_replace('/[^a-z0-9]/','',$value)??'';}
    private function looksLikeLeaveHeader(array $headers): bool {$keys=array_map([$this,'headerKey'],$headers);return(bool)array_intersect($keys,['sicilno','adsoyad'])&&in_array('izinturu',$keys,true);}
    private function log(string $action,string $table,int $id): void {try{$s=$this->db->prepare('INSERT INTO islem_loglari(kullanici_id,islem,tablo_adi,kayit_id,ip_adresi) VALUES(?,?,?,?,?)');$s->execute([$this->userId,$action,$table,$id,$this->ip]);}catch(Throwable){}}
}
