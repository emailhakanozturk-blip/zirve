<?php
declare(strict_types=1);

namespace PersonelHakedis;

use PDO;
use RuntimeException;
use Throwable;

final class ProgressReportImportService
{
    public function __construct(private PDO $db, private ?int $userId) {}

    public function import(string $file, int $year, int $month, int $contractId): array
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new RuntimeException('PhpSpreadsheet kurulu değil.');
        }

        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
        $report = $book->getSheetByName('HAKEDİŞ RAPORU');
        $detail = $book->getSheetByName('BİRİM FİYAT');
        if (!$report || !$detail) {
            throw new RuntimeException('HAKEDİŞ RAPORU veya BİRİM FİYAT sayfası bulunamadı.');
        }

        $payrollQuery = $this->db->prepare(
            "SELECT id,is_id,tis_donem_id FROM bordrolar
             WHERE yil=? AND ay=? AND sozlesme_id=? AND aktif=1 AND durum='tamamlandi'
             ORDER BY surum DESC LIMIT 1"
        );
        $payrollQuery->execute([$year, $month, $contractId]);
        $payroll = $payrollQuery->fetch();
        if (!$payroll) {
            throw new RuntimeException('Bu dönem için tamamlanmış bordro bulunamadı.');
        }

        $service = (float) $report->getCell('D5')->getCalculatedValue();
        $priceDifference = (float) $report->getCell('D7')->getCalculatedValue();
        $previous = (float) $report->getCell('D10')->getCalculatedValue();
        $current = (float) $report->getCell('D11')->getCalculatedValue();
        $vat = (float) $report->getCell('D12')->getCalculatedValue();
        $accrual = (float) $report->getCell('D13')->getCalculatedValue();
        $stamp = (float) $report->getCell('D15')->getCalculatedValue();
        $deductions = (float) $report->getCell('D24')->getCalculatedValue();
        $payable = (float) $report->getCell('D25')->getCalculatedValue();

        $reportDate = sprintf('%04d-%02d-%02d', $year, $month, (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month))));
        $title = (string) $report->getCell('A4')->getValue();
        if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $title, $match)) {
            $reportDate = $match[3] . '-' . $match[2] . '-' . $match[1];
        }

        $rows = $detail->toArray(null, true, false, false);

        $this->db->beginTransaction();
        try {
            $versionQuery = $this->db->prepare('SELECT COALESCE(MAX(surum),0)+1 FROM hakedisler WHERE yil=? AND ay=? AND sozlesme_id=?');
            $versionQuery->execute([$year, $month, $contractId]);
            $version = (int) $versionQuery->fetchColumn();
            $this->db->prepare('UPDATE hakedisler SET aktif=0 WHERE yil=? AND ay=? AND sozlesme_id=? AND aktif=1')->execute([$year, $month, $contractId]);
            $this->db->prepare("INSERT INTO hakedisler(yil,ay,sozlesme_id,surum,durum,aktif,toplam_tutar,olusturan_kullanici_id) VALUES(?,?,?,?,'taslak',1,?,?)")
                ->execute([$year, $month, $contractId, $version, $current, $this->userId]);
            $id = (int) $this->db->lastInsertId();
            $this->db->prepare('INSERT INTO hakedis_bordrolari(hakedis_id,bordro_id) VALUES(?,?)')->execute([$id, (int) $payroll['id']]);

            $personCount = $this->importDetailRows($rows, $id, (int) $payroll['id'], (int) $payroll['is_id'], (int) $payroll['tis_donem_id'], $reportDate);

            $this->db->prepare(
                'INSERT INTO hakedis_mali_ozetleri(hakedis_id,hak_edis_no,rapor_tarihi,sozlesme_fiyatlariyla_hizmet,fiyat_farki,onceki_hakedis_toplami,bu_hakedis_tutari,kdv_orani,kdv_tutari,tahakkuk_tutari,damga_vergisi,kesinti_toplami,odenecek_tutar,kaynak_dosya,dosya_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $id, str_pad((string) $month, 2, '0', STR_PAD_LEFT), $reportDate, $service, $priceDifference,
                $previous, $current, $current !== 0.0 ? ($vat / $current * 100) : 0, $vat, $accrual, $stamp,
                $deductions, $payable, basename($file), hash_file('sha256', $file),
            ]);

            $general = $this->extractGeneralRows($rows);
            $insertGeneral = $this->db->prepare('INSERT INTO hakedis_genel_kalemleri(hakedis_id,kalem_adi,oran,onceki_tutar,bu_hakedis_tutari,kumulatif_tutar,sira) VALUES(?,?,?,?,?,?,?)');
            foreach ($general as $index => $row) {
                $insertGeneral->execute([$id, $row['name'], $row['rate'], $row['previous'], $row['current'], $row['cumulative'], $index + 1]);
            }

            $sum = $this->db->prepare('SELECT COALESCE((SELECT SUM(bu_ayki_kazanc) FROM hakedis_detaylari WHERE hakedis_id=?),0)+COALESCE((SELECT SUM(bu_hakedis_tutari) FROM hakedis_genel_kalemleri WHERE hakedis_id=?),0)');
            $sum->execute([$id, $id]);
            $rounding = round($current - (float) $sum->fetchColumn(), 2);
            if (abs($rounding) >= 0.005) {
                $insertGeneral->execute([$id, 'Yuvarlama Farkı', null, 0, $rounding, $rounding, count($general) + 1]);
                $general[] = ['name' => 'Yuvarlama Farkı', 'current' => $rounding];
            }

            $this->db->prepare("UPDATE puantajlar SET durum='onayli' WHERE bordro_id=?")->execute([(int) $payroll['id']]);
            $detailCount = $this->db->prepare('SELECT COUNT(*) FROM hakedis_detaylari WHERE hakedis_id=?');
            $detailCount->execute([$id]);
            $this->db->commit();

            return [
                'hakedis_id' => $id,
                'surum' => $version,
                'personel' => $personCount,
                'detay' => (int) $detailCount->fetchColumn(),
                'genel_kalem' => count($general),
                'hizmet_tutari' => round($current, 2),
                'kdv' => round($vat, 2),
                'kesinti' => round($deductions, 2),
                'odenecek' => round($payable, 2),
            ];
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    private function importDetailRows(array $rows, int $progressId, int $payrollId, int $jobId, int $periodId, string $reportDate): int
    {
        $periodQuery = $this->db->prepare('SELECT sendika_id FROM tis_donemleri WHERE id=?');
        $periodQuery->execute([$periodId]);
        $unionId = (int) $periodQuery->fetchColumn();

        $people = [];
        foreach ($this->db->query('SELECT id,ad_soyad FROM personeller') as $person) {
            $people[$this->key((string) $person['ad_soyad'])] = (int) $person['id'];
        }
        $rights = [];
        $rightQuery = $this->db->prepare('SELECT id,hak_adi FROM sendikal_haklar WHERE tis_donem_id=?');
        $rightQuery->execute([$periodId]);
        foreach ($rightQuery as $right) {
            $rights[$this->key((string) $right['hak_adi'])] = ['id' => (int) $right['id'], 'name' => (string) $right['hak_adi']];
        }

        $insertPerson = $this->db->prepare('INSERT INTO personeller(ad_soyad,ise_giris_tarihi,isten_cikis_tarihi,sendika_id,aktif,aciklama) VALUES(?,?,?,?,0,?)');
        $insertDetail = $this->db->prepare('INSERT INTO hakedis_detaylari(hakedis_id,bordro_id,is_id,personel_id,sendikal_hak_id,hak_kalemi,birim,birim_fiyat,toplam_miktar,onceki_miktar,bu_hakedis_miktari,bu_ayki_kazanc,onceki_ay_toplami,kumulatif_toplam,aciklama) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $usedPeople = [];

        foreach (array_slice($rows, 5) as $rowIndex => $row) {
            $name = trim((string) ($row[1] ?? ''));
            $rightName = trim((string) ($row[2] ?? ''));
            if ($name === '' || $rightName === '') {
                continue;
            }

            $personKey = $this->closestKey($this->key($name), array_keys($people));
            if ($personKey === null) {
                $insertPerson->execute([$name, substr($reportDate, 0, 7) . '-01', $reportDate, $unionId ?: null, 'Hakediş kaynak dosyasından geçmiş personel olarak oluşturuldu.']);
                $personId = (int) $this->db->lastInsertId();
                $personKey = $this->key($name);
                $people[$personKey] = $personId;
            } else {
                $personId = $people[$personKey];
            }

            $rightKey = $this->closestKey($this->key($rightName), array_keys($rights));
            if ($rightKey === null) {
                throw new RuntimeException(sprintf('%d. satırdaki hak kalemi eşleştirilemedi: %s', $rowIndex + 6, $rightName));
            }
            $right = $rights[$rightKey];
            $insertDetail->execute([
                $progressId, $payrollId, $jobId, $personId, $right['id'], $rightName,
                trim((string) ($row[3] ?? '')), round((float) ($row[4] ?? 0), 2), round((float) ($row[6] ?? 0), 2),
                round((float) ($row[7] ?? 0), 2), round((float) ($row[8] ?? 0), 2),
                round((float) ($row[11] ?? 0), 2), round((float) ($row[10] ?? 0), 2),
                round((float) ($row[9] ?? 0), 2), sprintf('%s tarihli hakediş kaynak dosyası, satır %d', $reportDate, $rowIndex + 6),
            ]);
            $usedPeople[$personId] = true;
        }

        return count($usedPeople);
    }

    private function extractGeneralRows(array $rows): array
    {
        $general = [];
        foreach (array_slice($rows, 5) as $row) {
            $name = trim((string) ($row[1] ?? ''));
            $right = trim((string) ($row[2] ?? ''));
            if ($name !== '' && $right === '' && (float) ($row[11] ?? 0) !== 0.0) {
                $general[] = ['name' => $name, 'previous' => (float) ($row[10] ?? 0), 'current' => (float) ($row[11] ?? 0), 'cumulative' => (float) ($row[9] ?? 0), 'rate' => null];
            }
            $label = trim((string) ($row[9] ?? ''));
            if ($label !== '' && (str_contains($label, 'SÖZLEŞME GENEL GİDERLERİ') || str_contains($label, 'YÜKLENİCİ KARI'))) {
                preg_match('/%(\d+(?:[.,]\d+)?)/u', $label, $rate);
                $value = (float) ($row[11] ?? 0);
                $general[] = ['name' => $label, 'previous' => 0, 'current' => $value, 'cumulative' => $value, 'rate' => isset($rate[1]) ? (float) str_replace(',', '.', $rate[1]) : null];
            }
        }
        return $general;
    }

    private function key(string $value): string
    {
        $value = mb_strtoupper(preg_replace('/\s+/u', ' ', trim($value)), 'UTF-8');
        return strtr($value, ['Ç' => 'C', 'Ğ' => 'G', 'İ' => 'I', 'İ' => 'I', 'Ö' => 'O', 'Ş' => 'S', 'Ü' => 'U']);
    }

    private function closestKey(string $wanted, array $available): ?string
    {
        if (in_array($wanted, $available, true)) {
            return $wanted;
        }
        $best = null;
        $distance = PHP_INT_MAX;
        foreach ($available as $candidate) {
            $current = levenshtein($wanted, $candidate);
            if ($current < $distance) {
                $distance = $current;
                $best = $candidate;
            }
        }
        return $distance <= 2 ? $best : null;
    }
}
