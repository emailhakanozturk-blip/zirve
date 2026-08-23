<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PersonelHakedis\SpreadsheetService;

$file = $argv[1] ?? '';
if (!is_file($file)) {
    throw new RuntimeException('Test bordro dosyası bulunamadı.');
}
$config = require dirname(__DIR__) . '/config/personel-hakedis.php';
$pdo = new PDO($config['dsn'], $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$service = new SpreadsheetService($pdo, null);
$analysis = $service->analyzePayrollFile($file);
if (($analysis['format'] ?? '') !== 'zirve_detayli_bordro' || ($analysis['personel_sayisi'] ?? 0) !== 45) {
    $reflection = new ReflectionClass(SpreadsheetService::class);
    $read = $reflection->getMethod('read');
    $normalize = $reflection->getMethod('normalize');
    $rows = $read->invoke($service, $file);
    fwrite(STDERR, json_encode([
        'header' => $rows[9] ?? [],
        'normalized_a10' => $normalize->invoke($service, $rows[9][0] ?? ''),
        'normalized_d10' => $normalize->invoke($service, $rows[9][3] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
    throw new RuntimeException('Detaylı bordro biçimi beklenen şekilde çözümlenemedi: ' . json_encode($analysis, JSON_UNESCAPED_UNICODE));
}
$read = (new ReflectionClass(SpreadsheetService::class))->getMethod('read');
$recordsMethod = (new ReflectionClass(SpreadsheetService::class))->getMethod('detailedPayrollRecords');
$records = $recordsMethod->invoke($service, $read->invoke($service, $file));
$first = $records[0];
$item = static function(string $name) use ($first): array {
    foreach ($first['kalemler'] as $row) if ($row['ad'] === $name) return $row;
    throw new RuntimeException("Beklenen bordro kalemi bulunamadı: {$name}");
};
$checks = [
    ['Prim Günü', 30.0, 0.0], ['Normal Gün', 26.0, 34623.43], ['Hafta Tatili', 3.0, 3995.01],
    ['Fazla Mesai', 23.0, 6534.062435555556], ['Tatil Mesaisi', 1.0, 2663.34], ['NET İSTİHKAK', 0.0, 64291.34],
];
foreach ($checks as [$name,$amount,$money]) {
    $actual=$item($name);if(abs($actual['miktar']-$amount)>.001||abs($actual['tutar']-$money)>.001)throw new RuntimeException("{$name} değeri hatalı çözümlendi.");
}
echo json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
