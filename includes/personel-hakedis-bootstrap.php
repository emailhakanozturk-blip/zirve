<?php
declare(strict_types=1);

use PersonelHakedis\ModuleService;
use PersonelHakedis\UserManagementService;

$moduleRoot = dirname(__DIR__);
$config = require $moduleRoot . '/config/personel-hakedis.php';
date_default_timezone_set($config['timezone']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$autoload = $moduleRoot . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} else {
    require_once $moduleRoot . '/src/ModuleService.php';
    require_once $moduleRoot . '/src/SpreadsheetService.php';
    require_once $moduleRoot . '/src/ReportService.php';
    require_once $moduleRoot . '/src/UserManagementService.php';
}

if (!class_exists(UserManagementService::class)) {
    require_once $moduleRoot . '/src/UserManagementService.php';
}

// Entegrasyon noktası: mevcut bootstrap bu dosyadan önce yüklenip $pdo sağlayabilir.
if (!isset($pdo) || !$pdo instanceof PDO) {
    $pdo = new PDO($config['dsn'], $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

$currentUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$moduleCanWrite = !isset($_SESSION['permissions'])
    || in_array('personel_hakedis.yaz', (array) $_SESSION['permissions'], true)
    || !empty($_SESSION['is_admin']);

$moduleService = new ModuleService($pdo, $currentUserId, $_SERVER['REMOTE_ADDR'] ?? null);
$userManagementService = new UserManagementService($pdo, $currentUserId, $_SERVER['REMOTE_ADDR'] ?? null);

function phCsrfToken(): string
{
    if (empty($_SESSION['ph_csrf'])) {
        $_SESSION['ph_csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['ph_csrf'];
}

function phAssertCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_token'] ?? '';
    if (!hash_equals($_SESSION['ph_csrf'] ?? '', (string) $token)) {
        throw new RuntimeException('Oturum doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.');
    }
}
