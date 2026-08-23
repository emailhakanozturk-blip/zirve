<?php
declare(strict_types=1);

namespace PersonelHakedis;

use PDO;
use RuntimeException;

final class ReportService
{
    public function __construct(private PDO $db) {}

    public function rows(string $type, array $filters): array
    {
        $queries = [
            'aylik_puantaj' => "SELECT p.yil,p.ay,p.sozlesme_id,p.is_id,p.personel_id,NULL sendikal_hak_id,x.ad_soyad,s.ad sozlesme,i.ad is_adi,p.normal_gun,p.hafta_tatili,p.fazla_mesai,p.rapor,p.ucretli_izin,p.ucretsiz_izin,p.resmi_tatil,p.yol_gunu,p.yemek_gunu,p.prim_gunu,p.durum FROM puantajlar p JOIN bordrolar b ON b.id=p.bordro_id AND b.aktif=1 JOIN personeller x ON x.id=p.personel_id JOIN sozlesmeler s ON s.id=p.sozlesme_id JOIN is_tanimlari i ON i.id=p.is_id",
            'puantaj_ozet' => "SELECT p.yil,p.ay,p.sozlesme_id,p.is_id,NULL personel_id,NULL sendikal_hak_id,s.ad sozlesme,i.ad is_adi,COUNT(*) personel_sayisi,SUM(p.normal_gun) normal_gun,SUM(p.hafta_tatili) hafta_tatili,SUM(p.fazla_mesai) fazla_mesai,SUM(p.rapor) rapor,SUM(p.prim_gunu) prim_gunu FROM puantajlar p JOIN bordrolar b ON b.id=p.bordro_id AND b.aktif=1 JOIN sozlesmeler s ON s.id=p.sozlesme_id JOIN is_tanimlari i ON i.id=p.is_id GROUP BY p.yil,p.ay,p.sozlesme_id,p.is_id,s.ad,i.ad",
            'puantaj_karsilastirma' => "SELECT p.yil,p.ay,p.sozlesme_id,p.is_id,k.personel_id,NULL sendikal_hak_id,x.ad_soyad,s.ad sozlesme,i.ad is_adi,k.alan_adi,k.sistem_degeri,k.excel_degeri,k.fark,k.son_islem FROM puantaj_karsilastirmalari k JOIN puantajlar p ON p.id=k.puantaj_id JOIN personeller x ON x.id=k.personel_id JOIN sozlesmeler s ON s.id=p.sozlesme_id JOIN is_tanimlari i ON i.id=p.is_id",
            'aylik_hakedis' => $this->progressBase(),
            'kumulatif_hakedis' => $this->progressBase(),
            'is_hakedis' => "SELECT h.yil,h.ay,h.sozlesme_id,d.is_id,NULL personel_id,NULL sendikal_hak_id,s.ad sozlesme,i.ad is_adi,SUM(d.bu_ayki_kazanc) bu_ayki_kazanc,SUM(d.onceki_ay_toplami) onceki_ay_toplami,SUM(d.kumulatif_toplam) kumulatif_toplam FROM hakedis_detaylari d JOIN hakedisler h ON h.id=d.hakedis_id JOIN sozlesmeler s ON s.id=h.sozlesme_id JOIN is_tanimlari i ON i.id=d.is_id WHERE h.durum<>'iptal' AND h.aktif=1 GROUP BY h.yil,h.ay,h.sozlesme_id,d.is_id,s.ad,i.ad",
            'sozlesme_hakedis' => "SELECT h.yil,h.ay,h.sozlesme_id,NULL is_id,NULL personel_id,NULL sendikal_hak_id,s.ad sozlesme,SUM(d.bu_ayki_kazanc) bu_ayki_kazanc,SUM(d.onceki_ay_toplami) onceki_ay_toplami,SUM(d.kumulatif_toplam) kumulatif_toplam FROM hakedis_detaylari d JOIN hakedisler h ON h.id=d.hakedis_id JOIN sozlesmeler s ON s.id=h.sozlesme_id WHERE h.durum<>'iptal' AND h.aktif=1 GROUP BY h.yil,h.ay,h.sozlesme_id,s.ad",
            'personel_hakedis' => "SELECT h.yil,h.ay,h.sozlesme_id,NULL is_id,d.personel_id,NULL sendikal_hak_id,s.ad sozlesme,p.ad_soyad,SUM(d.bu_ayki_kazanc) bu_ayki_kazanc,SUM(d.onceki_ay_toplami) onceki_ay_toplami,SUM(d.kumulatif_toplam) kumulatif_toplam FROM hakedis_detaylari d JOIN hakedisler h ON h.id=d.hakedis_id JOIN sozlesmeler s ON s.id=h.sozlesme_id JOIN personeller p ON p.id=d.personel_id WHERE h.durum<>'iptal' AND h.aktif=1 GROUP BY h.yil,h.ay,h.sozlesme_id,d.personel_id,s.ad,p.ad_soyad",
            'hak_hakedis' => "SELECT h.yil,h.ay,h.sozlesme_id,NULL is_id,NULL personel_id,d.sendikal_hak_id,s.ad sozlesme,d.hak_kalemi,SUM(d.bu_ayki_kazanc) bu_ayki_kazanc,SUM(d.onceki_ay_toplami) onceki_ay_toplami,SUM(d.kumulatif_toplam) kumulatif_toplam FROM hakedis_detaylari d JOIN hakedisler h ON h.id=d.hakedis_id JOIN sozlesmeler s ON s.id=h.sozlesme_id WHERE h.durum<>'iptal' AND h.aktif=1 GROUP BY h.yil,h.ay,h.sozlesme_id,d.sendikal_hak_id,s.ad,d.hak_kalemi",
            'bordro_hakedis' => "SELECT b.yil,b.ay,b.sozlesme_id,b.is_id,bp.personel_id,NULL sendikal_hak_id,s.ad sozlesme,i.ad is_adi,p.ad_soyad,SUM(bk.tutar) bordro_toplami,COALESCE((SELECT SUM(d.bu_ayki_kazanc) FROM hakedis_detaylari d JOIN hakedisler h ON h.id=d.hakedis_id WHERE h.yil=b.yil AND h.ay=b.ay AND h.sozlesme_id=b.sozlesme_id AND d.is_id=b.is_id AND d.personel_id=bp.personel_id AND h.durum<>'iptal'),0) hakedis_toplami,SUM(bk.tutar)-COALESCE((SELECT SUM(d.bu_ayki_kazanc) FROM hakedis_detaylari d JOIN hakedisler h ON h.id=d.hakedis_id WHERE h.yil=b.yil AND h.ay=b.ay AND h.sozlesme_id=b.sozlesme_id AND d.is_id=b.is_id AND d.personel_id=bp.personel_id AND h.durum<>'iptal'),0) fark FROM bordrolar b JOIN bordro_personelleri bp ON bp.bordro_id=b.id AND bp.personel_id IS NOT NULL JOIN bordro_kalemleri bk ON bk.bordro_personel_id=bp.id JOIN sozlesmeler s ON s.id=b.sozlesme_id JOIN is_tanimlari i ON i.id=b.is_id JOIN personeller p ON p.id=bp.personel_id WHERE b.aktif=1 GROUP BY b.yil,b.ay,b.sozlesme_id,b.is_id,bp.personel_id,s.ad,i.ad,p.ad_soyad",
        ];
        $base = $queries[$type] ?? $queries['aylik_hakedis'];
        $where = ' WHERE 1=1'; $args = [];
        foreach (['yil','ay','sozlesme_id','is_id','personel_id','sendikal_hak_id'] as $key) {
            if (($filters[$key] ?? '') !== '') { $where .= " AND q.{$key}=:{$key}"; $args[$key]=$filters[$key]; }
        }
        $stmt=$this->db->prepare("SELECT q.* FROM ({$base}) q{$where} ORDER BY q.yil DESC,q.ay DESC");
        $stmt->execute($args); return $stmt->fetchAll();
    }

    public function jobMonthlySummary(int $year, int $month, int $jobId = 0): array
    {
        if ($year < 2020 || $year > 2100) throw new RuntimeException('Rapor yılı geçersiz.');
        if ($month < 1 || $month > 12) throw new RuntimeException('Rapor ayı geçersiz.');

        $currentColumns = [
            'toplam_kazanc' => 'toplam_kazanc',
            'ssk_prim_isveren' => 'ssk_prim_isveren',
            'issizlik_prim_isveren' => 'issizlik_prim_isveren',
            'baz_toplam' => 'hizmet_tutari',
            'genel_gider_tutari' => 'genel_gider',
            'kar_tutari' => 'yuklenici_kari',
            'toplam_hakedis_tutari' => 'genel_toplam',
        ];
        $selects = [];
        foreach ($currentColumns as $column => $alias) {
            $selects[] = "ROUND(SUM(CASE WHEN b.ay=f.selected_month THEN c.{$column} ELSE 0 END),2) mevcut_{$alias}";
            $selects[] = "ROUND(SUM(c.{$column}),2) kumulatif_{$alias}";
        }

        $sql = "SELECT b.is_id,i.ad is_adi,MIN(b.sozlesme_id) sozlesme_id,GROUP_CONCAT(DISTINCT s.ad ORDER BY s.ad SEPARATOR ', ') sozlesme_adi,".implode(',', $selects)."
                FROM bordrolar b
                JOIN bordro_maliyet_ozetleri c ON c.bordro_id=b.id
                JOIN is_tanimlari i ON i.id=b.is_id
                JOIN sozlesmeler s ON s.id=b.sozlesme_id
                CROSS JOIN (SELECT :selected_month selected_month) f
                WHERE b.yil=:report_year AND b.ay<=f.selected_month AND b.aktif=1 AND b.durum='tamamlandi'";
        $params = ['selected_month'=>$month,'report_year'=>$year];
        if ($jobId > 0) { $sql .= ' AND b.is_id=:selected_job'; $params['selected_job']=$jobId; }
        $sql .= ' GROUP BY b.is_id,i.ad ORDER BY i.ad';
        $stmt=$this->db->prepare($sql);$stmt->execute($params);$jobs=$stmt->fetchAll();

        $numericFields=[];
        foreach ($currentColumns as $alias) { $numericFields[]='mevcut_'.$alias; $numericFields[]='kumulatif_'.$alias; }
        foreach ($jobs as &$job) foreach ($numericFields as $field) $job[$field]=round((float)$job[$field],2);
        unset($job);

        $timelineSql="SELECT b.ay,ROUND(SUM(c.toplam_hakedis_tutari),2) mevcut_toplam
                      FROM bordrolar b JOIN bordro_maliyet_ozetleri c ON c.bordro_id=b.id
                      WHERE b.yil=:timeline_year AND b.aktif=1 AND b.durum='tamamlandi'";
        $timelineParams=['timeline_year'=>$year];
        if($jobId>0){$timelineSql.=' AND b.is_id=:timeline_job';$timelineParams['timeline_job']=$jobId;}
        $timelineSql.=' GROUP BY b.ay ORDER BY b.ay';
        $timelineStmt=$this->db->prepare($timelineSql);$timelineStmt->execute($timelineParams);$indexed=[];
        foreach($timelineStmt->fetchAll()as$row)$indexed[(int)$row['ay']]=(float)$row['mevcut_toplam'];
        $months=[];$running=0.0;
        for($number=1;$number<=12;$number++){$current=round($indexed[$number]??0.0,2);$running=round($running+$current,2);$months[]=['ay'=>$number,'mevcut_toplam'=>$current,'kumulatif_toplam'=>$running,'veri_var'=>$current!=0.0];}

        $summary=['is_sayisi'=>count($jobs),'mevcut_genel_toplam'=>0.0,'kumulatif_genel_toplam'=>0.0,'mevcut_hizmet_tutari'=>0.0,'kumulatif_hizmet_tutari'=>0.0];
        foreach($jobs as$job){foreach(['mevcut_genel_toplam','kumulatif_genel_toplam','mevcut_hizmet_tutari','kumulatif_hizmet_tutari']as$field)$summary[$field]=round($summary[$field]+(float)$job[$field],2);}
        return['yil'=>$year,'ay'=>$month,'is_id'=>$jobId,'jobs'=>$jobs,'months'=>$months,'summary'=>$summary];
    }

    public function excel(string $type,array $filters):string
    {
        if(!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class))throw new RuntimeException('PhpSpreadsheet kurulu değil.');
        $rows=$this->rows($type,$filters);$book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$sheet=$book->getActiveSheet();
        if($rows){$sheet->fromArray(array_keys($rows[0]),null,'A1');$sheet->fromArray(array_map('array_values',$rows),null,'A2');$sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);$sheet->setAutoFilter($sheet->calculateWorksheetDimension());$sheet->freezePane('A2');}
        $path=tempnam(sys_get_temp_dir(),'ph_').'.xlsx';(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);return$path;
    }

    public function payrollMovementsExcel(int$id):string
    {
        if(!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class))throw new RuntimeException('PhpSpreadsheet kurulu değil.');
        $data=(new ModuleService($this->db,null,null))->payrollMovements($id);$rows=$data['rows']??[];$payroll=$data['payroll']??[];$rights=$data['rights']??[];if(!$rows)throw new RuntimeException('Bordro hareketi bulunamadı.');
        $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$sheet=$book->getActiveSheet();$sheet->setTitle('Bordro Hareketleri');$sheet->setShowGridlines(false);
        $sheet->mergeCells('A1:H1');$sheet->setCellValue('A1','BORDRO HAREKETLERİ '.($payroll['yil']??'').'/'.str_pad((string)($payroll['ay']??''),2,'0',STR_PAD_LEFT));
        $sheet->mergeCells('A2:H2');$sheet->setCellValue('A2',($payroll['sozlesme_adi']??'').' / '.($payroll['is_adi']??''));
        $headers=['SIRA','AD SOYAD','NORMAL KAZANÇ','DİĞER KAZANÇ','TOPLAM KAZANÇ','SSK PRİM İŞVEREN','İŞSİZLİK PRİM İŞVEREN','PRİMLER DAHİL HAKEDİŞ'];$sheet->fromArray($headers,null,'A4');$first=5;
        foreach($rows as$index=>$row){$excelRow=$first+$index;$sheet->fromArray([$index+1,$row['ad_soyad'],(float)$row['normal_kazanc'],(float)$row['diger_kazanc'],null,(float)$row['ssk_prim_isveren'],(float)$row['issizlik_prim_isveren'],null],null,'A'.$excelRow);$sheet->setCellValue('E'.$excelRow,'=ROUND(C'.$excelRow.'+D'.$excelRow.',2)');$sheet->setCellValue('H'.$excelRow,'=ROUND(E'.$excelRow.'+F'.$excelRow.'+G'.$excelRow.',2)');}
        $totalRow=$first+count($rows);$sheet->mergeCells('A'.$totalRow.':B'.$totalRow);$sheet->setCellValue('A'.$totalRow,'GENEL TOPLAM');foreach(['C','D','F','G']as$column)$sheet->setCellValue($column.$totalRow,'=ROUND(SUM('.$column.$first.':'.$column.($totalRow-1).'),2)');$sheet->setCellValue('E'.$totalRow,'=ROUND(C'.$totalRow.'+D'.$totalRow.',2)');$sheet->setCellValue('H'.$totalRow,'=ROUND(E'.$totalRow.'+F'.$totalRow.'+G'.$totalRow.',2)');
        $summaryStart=$totalRow+3;$sheet->mergeCells('A'.$summaryStart.':G'.$summaryStart);$sheet->setCellValue('A'.$summaryStart,'SÖZLEŞME FİYATLARI İLE YAPILAN HİZMET TUTARI');$sheet->setCellValue('H'.$summaryStart,'=H'.$totalRow);$sheet->mergeCells('A'.($summaryStart+1).':G'.($summaryStart+1));$sheet->setCellValue('A'.($summaryStart+1),'SÖZLEŞME GENEL GİDERLERİ (%4)');$sheet->setCellValue('H'.($summaryStart+1),'=ROUND(H'.$summaryStart.'*4%,2)');$sheet->mergeCells('A'.($summaryStart+2).':G'.($summaryStart+2));$sheet->setCellValue('A'.($summaryStart+2),'YÜKLENİCİ KARI (%7)');$sheet->setCellValue('H'.($summaryStart+2),'=ROUND(H'.$summaryStart.'*7%,2)');$sheet->mergeCells('A'.($summaryStart+3).':G'.($summaryStart+3));$sheet->setCellValue('A'.($summaryStart+3),'GENEL TOPLAM (KDV HARİÇ)');$sheet->setCellValue('H'.($summaryStart+3),'=ROUND(SUM(H'.$summaryStart.':H'.($summaryStart+2).'),2)');
        $moneyFormat='#,##0.00';$sheet->getStyle('C5:H'.($summaryStart+3))->getNumberFormat()->setFormatCode($moneyFormat);$sheet->getStyle('A1:H1')->getFont()->setBold(true)->setSize(14);$sheet->getStyle('A1:H2')->getAlignment()->setHorizontal('center');$sheet->getStyle('A4:H4')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');$sheet->getStyle('A4:H4')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF1F4E78');$sheet->getStyle('A4:H'.$totalRow)->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setARGB('FFB7C9D6');$sheet->getStyle('A'.$totalRow.':H'.$totalRow)->getFont()->setBold(true);$sheet->getStyle('A'.$totalRow.':H'.$totalRow)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFD9EAF7');$sheet->getStyle('A'.$summaryStart.':H'.($summaryStart+3))->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setARGB('FF1F1F1F');$sheet->getStyle('A'.($summaryStart+3).':H'.($summaryStart+3))->getFont()->setBold(true);$sheet->getStyle('A'.($summaryStart+3).':H'.($summaryStart+3))->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE2F0D9');$sheet->freezePane('C5');$sheet->setAutoFilter('A4:H'.($totalRow-1));$sheet->getColumnDimension('A')->setWidth(8);$sheet->getColumnDimension('B')->setWidth(28);foreach(range('C','H')as$column)$sheet->getColumnDimension($column)->setWidth(20);$sheet->getStyle('A4:H4')->getAlignment()->setWrapText(true)->setHorizontal('center');$sheet->getRowDimension(4)->setRowHeight(34);
        $rightsSheet=$book->createSheet();$rightsSheet->setTitle('Hak Dağılımı');$rightsSheet->setShowGridlines(false);$rightHeaders=['SIRA','AD SOYAD','NORMAL KAZANÇ'];foreach($rights as$right)$rightHeaders[]=$right['label'];$rightHeaders[]='HAK DAĞILIMI TOPLAMI';$rightHeaders[]='BORDRO TOPLAM KAZANÇ';$rightHeaders[]='FARK';$lastColumn=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($rightHeaders));$rightsSheet->mergeCells('A1:'.$lastColumn.'1');$rightsSheet->setCellValue('A1','BORDRO / HAK DAĞILIMI KARŞILAŞTIRMASI');$rightsSheet->fromArray($rightHeaders,null,'A3');$rightFirst=4;$rightValueStart=3;$rightValueEnd=3+count($rights);$distributionColumn=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($rightValueEnd+1);$payrollTotalColumn=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($rightValueEnd+2);$differenceColumn=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($rightValueEnd+3);
        foreach($rows as$index=>$row){$excelRow=$rightFirst+$index;$values=[$index+1,$row['ad_soyad'],(float)$row['hakedis_normal_kazanc']];foreach($rights as$right)$values[]=(float)($row['haklar'][$right['key']]??0);$values[] = null;$values[]=(float)$row['toplam_kazanc'];$values[]=null;$rightsSheet->fromArray($values,null,'A'.$excelRow);$startColumn=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($rightValueStart);$endColumn=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($rightValueEnd);$rightsSheet->setCellValue($distributionColumn.$excelRow,'=ROUND(SUM('.$startColumn.$excelRow.':'.$endColumn.$excelRow.'),2)');$rightsSheet->setCellValue($differenceColumn.$excelRow,'=ROUND('.$payrollTotalColumn.$excelRow.'-'.$distributionColumn.$excelRow.',2)');}
        $rightTotalRow=$rightFirst+count($rows);$rightsSheet->mergeCells('A'.$rightTotalRow.':B'.$rightTotalRow);$rightsSheet->setCellValue('A'.$rightTotalRow,'GENEL TOPLAM');for($column=$rightValueStart;$column<=$rightValueEnd+3;$column++){$letter=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column);if($letter===$distributionColumn)$rightsSheet->setCellValue($letter.$rightTotalRow,'=ROUND(SUM('.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($rightValueStart).$rightTotalRow.':'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($rightValueEnd).$rightTotalRow.'),2)');elseif($letter===$differenceColumn)$rightsSheet->setCellValue($letter.$rightTotalRow,'=ROUND('.$payrollTotalColumn.$rightTotalRow.'-'.$distributionColumn.$rightTotalRow.',2)');else$rightsSheet->setCellValue($letter.$rightTotalRow,'=ROUND(SUM('.$letter.$rightFirst.':'.$letter.($rightTotalRow-1).'),2)');}
        $rightsSheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true)->setSize(14);$rightsSheet->getStyle('A1:'.$lastColumn.'1')->getAlignment()->setHorizontal('center');$rightsSheet->getStyle('A3:'.$lastColumn.'3')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');$rightsSheet->getStyle('A3:'.$lastColumn.'3')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF1F4E78');$rightsSheet->getStyle('A3:'.$lastColumn.$rightTotalRow)->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setARGB('FFB7C9D6');$rightsSheet->getStyle('C4:'.$lastColumn.$rightTotalRow)->getNumberFormat()->setFormatCode($moneyFormat);$rightsSheet->getStyle('A'.$rightTotalRow.':'.$lastColumn.$rightTotalRow)->getFont()->setBold(true);$rightsSheet->getStyle('A'.$rightTotalRow.':'.$lastColumn.$rightTotalRow)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFD9EAF7');$rightsSheet->freezePane('C4');$rightsSheet->setAutoFilter('A3:'.$lastColumn.($rightTotalRow-1));$rightsSheet->getColumnDimension('A')->setWidth(8);$rightsSheet->getColumnDimension('B')->setWidth(28);for($column=3;$column<=count($rightHeaders);$column++)$rightsSheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column))->setWidth(18);$rightsSheet->getStyle('A3:'.$lastColumn.'3')->getAlignment()->setWrapText(true)->setHorizontal('center');$rightsSheet->getRowDimension(3)->setRowHeight(42);
        $book->setActiveSheetIndex(0);$path=tempnam(sys_get_temp_dir(),'bordro_hareketleri_').'.xlsx';$writer=new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);$writer->setPreCalculateFormulas(true);$writer->save($path);return$path;
    }

    public function payrollTimesheetExcel(int $id):string
    {
        if(!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class))throw new RuntimeException('PhpSpreadsheet kurulu değil.');
        $data=(new ModuleService($this->db,null,null))->payrollTimesheet($id);$payroll=$data['payroll'];$rows=$data['rows'];$columns=$data['columns'];$days=(int)$data['days'];$book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$sheet=$book->getActiveSheet();$sheet->setTitle('Puantaj');$sheet->setShowGridlines(false);
        $monthNames=['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];$weekdayNames=[1=>'Pt',2=>'Sa',3=>'Ça',4=>'Pe',5=>'Cu',6=>'Ct',7=>'Pz'];$dayStart=6;$dayEnd=5+$days;$workedIndex=$dayEnd+1;$sskIndex=$dayEnd+2;$leaveIndex=$dayEnd+3;$totalIndex=$dayEnd+4;$missingIndex=$dayEnd+5;$reasonIndex=$dayEnd+6;$detailStart=$dayEnd+7;$signatureIndex=$detailStart+count($columns);$lastColumn=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($signatureIndex);$dayStartColumn=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($dayStart);$dayEndColumn=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($dayEnd);
        $sheet->mergeCells('A1:'.$lastColumn.'1');$sheet->setCellValue('A1','İşyeri: '.($payroll['is_adi']??'').'    Dönem: '.($monthNames[(int)$payroll['ay']]??'').' '.($payroll['yil']??'').'    Kaynak Bordro: #'.$payroll['id'].' / '.($payroll['dosya_adi']??''));
        $headers=['Sıra No','Ad Soyad','T.C. Kimlik No','Giriş Tarihi','Çıkış Tarihi'];for($day=1;$day<=$days;$day++)$headers[]=$day;$headers=array_merge($headers,['Çalışılan','SSK','İzin','Toplam','Eksik','Eksik Gün Neden']);foreach($columns as$column)$headers[]=$column['label'];$headers[]='İMZA';$sheet->fromArray($headers,null,'A3');
        $subHeaders=['','','','',''];for($day=1;$day<=$days;$day++)$subHeaders[]=$weekdayNames[(int)date('N',strtotime(sprintf('%04d-%02d-%02d',(int)$payroll['yil'],(int)$payroll['ay'],$day)))];$subHeaders=array_merge($subHeaders,['Gün','Gün','Gün','Gün','Gün','']);foreach($columns as$column)$subHeaders[]=$column['unit'];$subHeaders[]='';$sheet->fromArray($subHeaders,null,'A4');
        $firstRow=5;foreach($rows as$index=>$row){$excelRow=$firstRow+$index;$sheet->setCellValue('A'.$excelRow,$index+1);$sheet->setCellValue('B'.$excelRow,$row['ad_soyad']);$sheet->setCellValueExplicit('C'.$excelRow,(string)$row['tc_kimlik_no'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);foreach(['D'=>$row['ise_giris_tarihi'],'E'=>$row['isten_cikis_tarihi']]as$column=>$date){if($date)$sheet->setCellValue($column.$excelRow,\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTimeImmutable((string)$date)));}for($day=1;$day<=$days;$day++)$sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($dayStart+$day-1).$excelRow,$row['gunler'][$day]??'');
            $workedColumn=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($workedIndex);$sskColumn=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($sskIndex);$leaveColumn=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($leaveIndex);$totalColumn=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($totalIndex);$missingColumn=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($missingIndex);$reasonColumn=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($reasonIndex);$range=$dayStartColumn.$excelRow.':'.$dayEndColumn.$excelRow;
            $sheet->setCellValue($workedColumn.$excelRow,'=COUNTIF('.$range.',"N")+COUNTIF('.$range.',"O")+COUNTIF('.$range.',"G")+COUNTIF('.$range.',"T")+COUNTIF('.$range.',"Y")/2');$sheet->setCellValue($sskColumn.$excelRow,(float)$row['ssk_gun']);$sheet->setCellValue($leaveColumn.$excelRow,'=COUNTIF('.$range.',"İ")+COUNTIF('.$range.',"S")+COUNTIF('.$range.',"R")+COUNTIF('.$range.',"E")');$sheet->setCellValue($totalColumn.$excelRow,'=ROUND('.$workedColumn.$excelRow.'+COUNTIF('.$range.',"H")+'.$leaveColumn.$excelRow.',2)');$sheet->setCellValue($missingColumn.$excelRow,'=MAX(0,COUNTA('.$dayStartColumn.'$3:'.$dayEndColumn.'$3)-'.$sskColumn.$excelRow.')');$sheet->setCellValue($reasonColumn.$excelRow,$row['eksik_gun']>0?'Eksik gün':'');foreach($columns as$columnIndex=>$column)$sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($detailStart+$columnIndex).$excelRow,(float)($row['detaylar'][$column['key']]??0));
        }
        $totalRow=$firstRow+count($rows);$sheet->mergeCells('A'.$totalRow.':E'.$totalRow);$sheet->setCellValue('A'.$totalRow,'GENEL TOPLAM');foreach([$workedIndex,$sskIndex,$leaveIndex,$totalIndex,$missingIndex]as$index){$column=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);$sheet->setCellValue($column.$totalRow,'=ROUND(SUM('.$column.$firstRow.':'.$column.($totalRow-1).'),2)');}for($index=$detailStart;$index<$signatureIndex;$index++){$column=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);$sheet->setCellValue($column.$totalRow,'=ROUND(SUM('.$column.$firstRow.':'.$column.($totalRow-1).'),2)');}
        $headerRange='A3:'.$lastColumn.'4';$dataRange='A3:'.$lastColumn.$totalRow;$sheet->getStyle($headerRange)->getFont()->setBold(true);$sheet->getStyle($headerRange)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFECE9DC');$sheet->getStyle($dataRange)->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setARGB('FF7F7F7F');$sheet->getStyle($headerRange)->getAlignment()->setHorizontal('center')->setVertical('center')->setWrapText(true);$sheet->getStyle('A'.$totalRow.':'.$lastColumn.$totalRow)->getFont()->setBold(true);$sheet->getStyle('A'.$totalRow.':'.$lastColumn.$totalRow)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFD9EAF7');$sheet->getStyle('D5:E'.($totalRow-1))->getNumberFormat()->setFormatCode('dd/mm/yyyy');$sheet->getStyle(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($workedIndex).'5:'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($signatureIndex-1).$totalRow)->getNumberFormat()->setFormatCode('#,##0.00');$sheet->getStyle($dayStartColumn.'5:'.$dayEndColumn.($totalRow-1))->getAlignment()->setHorizontal('center');$sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true)->setSize(12);
        $sheet->getColumnDimension('A')->setWidth(8);$sheet->getColumnDimension('B')->setWidth(25);$sheet->getColumnDimension('C')->setWidth(15);$sheet->getColumnDimension('D')->setWidth(13);$sheet->getColumnDimension('E')->setWidth(13);for($index=$dayStart;$index<=$dayEnd;$index++)$sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index))->setWidth(4);for($index=$workedIndex;$index<=$missingIndex;$index++)$sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index))->setWidth(9);$sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($reasonIndex))->setWidth(18);for($index=$detailStart;$index<$signatureIndex;$index++)$sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index))->setWidth(16);$sheet->getColumnDimension($lastColumn)->setWidth(12);$sheet->getRowDimension(3)->setRowHeight(42);$sheet->getRowDimension(4)->setRowHeight(22);$sheet->freezePane('F5');$sheet->setAutoFilter('A3:'.$lastColumn.($totalRow-1));
        $legendStart=$totalRow+3;$legend=[['Kod Açıklamaları',''],['N','Normal'],['O','Gündüz Mesaisi'],['G','Gece Mesaisi'],['Y','Yarım Gün'],['H','Hafta Tatili'],['T','Resmi Tatil'],['K','Yarım Gün Resmi Tatil'],['C','Yarım Gün Hafta Tatili'],['İ','İzinli'],['S','Yıllık İzin'],['R','Raporlu'],['E','Eksik Gün']];$sheet->fromArray($legend,null,'A'.$legendStart);$sheet->getStyle('A'.$legendStart.':B'.($legendStart+count($legend)-1))->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setARGB('FFB7B7B7');$sheet->getStyle('A'.$legendStart.':B'.$legendStart)->getFont()->setBold(true);$sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);$sheet->getPageMargins()->setTop(.25)->setRight(.25)->setBottom(.25)->setLeft(.25);
        $path=tempnam(sys_get_temp_dir(),'puantaj_').'.xlsx';$writer=new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);$writer->setPreCalculateFormulas(true);$writer->save($path);return$path;
    }

    public function pdf(string $type,array $filters):string
    {
        if(!class_exists(\Dompdf\Dompdf::class))throw new RuntimeException('Dompdf kurulu değil.');$rows=$this->rows($type,$filters);
        $html='<meta charset="utf-8"><style>body{font-family:DejaVu Sans;font-size:8px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #bbb;padding:3px}th{background:#eee}</style><h2>Personel Hakediş Raporu</h2><table>';
        if($rows){$html.='<tr>';foreach(array_keys($rows[0])as$h)$html.='<th>'.htmlspecialchars($h).'</th>';$html.='</tr>';foreach($rows as$r){$html.='<tr>';foreach($r as$v)$html.='<td>'.htmlspecialchars((string)$v).'</td>';$html.='</tr>';}}$html.='</table>';
        $pdf=new \Dompdf\Dompdf();$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4','landscape');$pdf->render();$path=tempnam(sys_get_temp_dir(),'ph_').'.pdf';file_put_contents($path,$pdf->output());return$path;
    }

    private function progressBase():string
    {
        return "SELECT h.yil,h.ay,h.sozlesme_id,d.is_id,d.personel_id,d.sendikal_hak_id,s.ad sozlesme,i.ad is_adi,p.ad_soyad,d.hak_kalemi,d.bu_ayki_kazanc,d.onceki_ay_toplami,d.kumulatif_toplam,d.aciklama FROM hakedis_detaylari d JOIN hakedisler h ON h.id=d.hakedis_id JOIN sozlesmeler s ON s.id=h.sozlesme_id JOIN is_tanimlari i ON i.id=d.is_id JOIN personeller p ON p.id=d.personel_id WHERE h.durum<>'iptal' AND h.aktif=1";
    }
}
