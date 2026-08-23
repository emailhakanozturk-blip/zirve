<?php
declare(strict_types=1);

use PersonelHakedis\ModuleService;
use PersonelHakedis\UserManagementService;

$moduleRoot = dirname(__DIR__);
$config = require $moduleRoot . '/config/personel-hakedis.php';
date_default_timezone_set($config['timezone']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('zirve_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
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

require_once $moduleRoot . '/includes/personel-hakedis-auth.php';

$currentUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$sessionPermissions = (array)($_SESSION['permissions'] ?? []);
$moduleCanWrite = !empty($_SESSION['is_admin'])
    || count(array_filter($sessionPermissions, static fn($permission) => str_ends_with((string)$permission, '.manage'))) > 0;

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
