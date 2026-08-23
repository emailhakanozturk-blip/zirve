<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/personel-hakedis-bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: personel-hakedis.php'); exit; }
phAssertCsrf();
phLogout($pdo);
header('Location: giris.php');
exit;
