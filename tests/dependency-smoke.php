<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
$spreadsheet->getActiveSheet()->fromArray([
    ['Ad Soyad', 'Normal Gün'],
    ['Test Personel', 20],
]);
$xlsx = tempnam(sys_get_temp_dir(), 'ph_xlsx_') . '.xlsx';
(new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($xlsx);
$value = PhpOffice\PhpSpreadsheet\IOFactory::load($xlsx)->getActiveSheet()->getCell('B2')->getValue();
@unlink($xlsx);
if ((int) $value !== 20) {
    throw new RuntimeException('XLSX okuma/yazma testi başarısız.');
}

$pdf = new Dompdf\Dompdf();
$pdf->loadHtml('<meta charset="utf-8"><h1>Hakediş raporu</h1>', 'UTF-8');
$pdf->render();
if (strlen($pdf->output()) < 100) {
    throw new RuntimeException('PDF üretme testi başarısız.');
}

echo "XLSX ve PDF bağımlılık testleri başarılı.\n";
