<?php
declare(strict_types=1);

use PersonelHakedis\ProgressReportImportService;

$source=$argv[1]??'';if(!is_file($source))throw new RuntimeException('Hakediş dosyası bulunamadı.');
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';$config=require $root.'/config/personel-hakedis.php';$db=new PDO($config['dsn'],$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$contract=(int)$db->query("SELECT id FROM sozlesmeler WHERE numara='TEMZLK-3-2026' LIMIT 1")->fetchColumn();if(!$contract)throw new RuntimeException('TEMZLK-3-2026 sözleşmesi bulunamadı.');
$result=(new ProgressReportImportService($db,null))->import($source,2026,6,$contract);echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),PHP_EOL;
