<?php
declare(strict_types=1);

function phAuthUserCount(PDO $db): int
{
    return (int) $db->query('SELECT COUNT(*) FROM kullanicilar')->fetchColumn();
}

function phAuthUser(PDO $db, int $id): ?array
{
    $statement = $db->prepare('SELECT id,ad_soyad,eposta,aktif,son_giris_at FROM kullanicilar WHERE id=? AND aktif=1 LIMIT 1');
    $statement->execute([$id]);
    $user = $statement->fetch();
    if (!$user) return null;

    $roles = $db->prepare('SELECT r.rol_anahtari FROM roller r JOIN kullanici_rolleri kr ON kr.rol_id=r.id WHERE kr.kullanici_id=? AND r.aktif=1');
    $roles->execute([$id]);
    $user['roles'] = $roles->fetchAll(PDO::FETCH_COLUMN);

    $permissions = $db->prepare("SELECT y.yetki_anahtari
        FROM yetkiler y
        LEFT JOIN kullanici_yetkileri ky ON ky.yetki_id=y.id AND ky.kullanici_id=:user_override
        WHERE y.aktif=1 AND (
            ky.izinli=1 OR (ky.kullanici_id IS NULL AND EXISTS (
                SELECT 1 FROM rol_yetkileri ry
                JOIN kullanici_rolleri kr ON kr.rol_id=ry.rol_id
                JOIN roller r ON r.id=kr.rol_id AND r.aktif=1
                WHERE ry.yetki_id=y.id AND kr.kullanici_id=:user_role
            ))
        )");
    $permissions->execute(['user_override'=>$id,'user_role'=>$id]);
    $user['permissions'] = $permissions->fetchAll(PDO::FETCH_COLUMN);
    return $user;
}

function phRefreshAuthSession(PDO $db): ?array
{
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id < 1) return null;
    $user = phAuthUser($db, $id);
    if (!$user) {
        unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['permissions'], $_SESSION['is_admin']);
        return null;
    }
    $_SESSION['user_name'] = (string) $user['ad_soyad'];
    $_SESSION['permissions'] = $user['permissions'];
    $_SESSION['is_admin'] = in_array('system_admin', $user['roles'], true);
    return $user;
}

function phStartAuthSession(PDO $db, int $id): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;
    if (!phRefreshAuthSession($db)) throw new RuntimeException('Kullanıcı hesabı etkin değil.');
    $_SESSION['ph_csrf'] = bin2hex(random_bytes(24));
    $db->prepare('UPDATE kullanicilar SET son_giris_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id]);
    phAuthLog($db, $id, 'giris', 'Kullanıcı sisteme giriş yaptı.');
}

function phAttemptLogin(PDO $db, string $email, string $password): bool
{
    $email = mb_strtolower(trim($email));
    $statement = $db->prepare('SELECT id,parola_hash FROM kullanicilar WHERE eposta=? AND aktif=1 LIMIT 1');
    $statement->execute([$email]);
    $user = $statement->fetch();
    if (!$user || !password_verify($password, (string)$user['parola_hash'])) return false;
    if (password_needs_rehash((string)$user['parola_hash'], PASSWORD_DEFAULT)) {
        $db->prepare('UPDATE kullanicilar SET parola_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT),(int)$user['id']]);
    }
    phStartAuthSession($db, (int)$user['id']);
    return true;
}

function phCreateFirstAdmin(PDO $db, string $name, string $email, string $password): int
{
    $name = mb_substr(trim($name), 0, 150);
    $email = mb_strtolower(mb_substr(trim($email), 0, 190));
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Ad soyad ve geçerli e-posta zorunludur.');
    if (mb_strlen($password) < 8) throw new RuntimeException('Parola en az 8 karakter olmalıdır.');

    $db->beginTransaction();
    try {
        if ((int)$db->query('SELECT COUNT(*) FROM kullanicilar')->fetchColumn() !== 0) throw new RuntimeException('İlk yönetici daha önce oluşturulmuş.');
        $roleId = (int)$db->query("SELECT id FROM roller WHERE rol_anahtari='system_admin' AND aktif=1 LIMIT 1")->fetchColumn();
        if ($roleId < 1) throw new RuntimeException('Sistem yöneticisi rolü bulunamadı; kullanıcı yönetimi migration dosyasını çalıştırın.');
        $insert = $db->prepare('INSERT INTO kullanicilar(ad_soyad,eposta,parola_hash,aktif) VALUES(?,?,?,1)');
        $insert->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO kullanici_rolleri(kullanici_id,rol_id) VALUES(?,?)')->execute([$id,$roleId]);
        $db->commit();
        phStartAuthSession($db, $id);
        return $id;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}

function phRequireAuth(PDO $db, bool $api = false): void
{
    if (phRefreshAuthSession($db)) return;
    if ($api) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['ok'=>false,'message'=>'Oturumunuz sona erdi. Lütfen yeniden giriş yapın.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: giris.php');
    exit;
}

function phLogout(PDO $db): void
{
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id > 0) phAuthLog($db, $id, 'cikis', 'Kullanıcı sistemden çıkış yaptı.');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

function phAuthLog(PDO $db, ?int $userId, string $action, string $description): void
{
    try {
        $statement = $db->prepare('INSERT INTO islem_loglari(kullanici_id,islem,tablo_adi,alan_adi,yeni_deger,ip_adresi) VALUES(?,?,?,?,?,?)');
        $statement->execute([$userId,$action,'oturum','kimlik_dogrulama',$description,substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45)]);
    } catch (Throwable) {
        // Oturum işlemi, log tablosundaki geçici bir sorundan etkilenmemelidir.
    }
}
