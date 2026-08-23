<?php
declare(strict_types=1);

use PersonelHakedis\SpreadsheetService;

require dirname(__DIR__) . '/vendor/autoload.php';
$config = require dirname(__DIR__) . '/config/personel-hakedis.php';
if ($argc < 3) {
    fwrite(STDERR, "Kullanım: php backfill-payroll-cost-summary.php <bordro-id> <dosya>\n");
    exit(2);
}
$pdo = new PDO($config['dsn'], $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$result = (new SpreadsheetService($pdo, null))->importPayrollCostSummary($argv[2], (int) $argv[1]);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
