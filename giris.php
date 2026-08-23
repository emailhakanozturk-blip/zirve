<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/personel-hakedis-bootstrap.php';

if (phRefreshAuthSession($pdo)) { header('Location: personel-hakedis.php'); exit; }
$error = '';
$email = mb_strtolower(trim((string)($_POST['eposta'] ?? '')));
$needsFirstAdmin = false;
try { $needsFirstAdmin = phAuthUserCount($pdo) === 0; }
catch (Throwable) { $error = 'Kullanıcı tabloları bulunamadı. Önce kullanıcı yönetimi migration dosyasını çalıştırın.'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    try {
        phAssertCsrf();
        $attempts = $_SESSION['ph_login_attempts'] ?? ['count'=>0,'since'=>time()];
        if (time() - (int)$attempts['since'] > 300) $attempts = ['count'=>0,'since'=>time()];
        if ((int)$attempts['count'] >= 5) throw new RuntimeException('Çok fazla başarısız deneme yapıldı. Beş dakika sonra yeniden deneyin.');

        if ($needsFirstAdmin) {
            $password = (string)($_POST['parola'] ?? '');
            if ($password !== (string)($_POST['parola_tekrar'] ?? '')) throw new RuntimeException('Parola ve tekrarı aynı olmalıdır.');
            phCreateFirstAdmin($pdo, (string)($_POST['ad_soyad'] ?? ''), $email, $password);
        } elseif (!phAttemptLogin($pdo, $email, (string)($_POST['parola'] ?? ''))) {
            $attempts['count']++;
            $_SESSION['ph_login_attempts'] = $attempts;
            throw new RuntimeException('E-posta adresi veya parola hatalı.');
        }
        unset($_SESSION['ph_login_attempts']);
        header('Location: personel-hakedis.php'); exit;
    } catch (Throwable $exception) { $error = $exception->getMessage(); }
}
$csrf = phCsrfToken();
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $needsFirstAdmin ? 'İlk Yönetici Kurulumu' : 'Giriş' ?> · Zirve</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/personel-hakedis.css?v=<?= (int)filemtime(__DIR__.'/assets/personel-hakedis.css') ?>" rel="stylesheet"></head>
<body class="ph-auth-page"><main class="ph-auth-shell">
 <section class="ph-auth-form-side"><div class="ph-auth-inner">
  <a class="ph-auth-brand" href="giris.php"><span class="ph-logo">Z</span><span><strong>zirve.</strong><small>Yönetim Platformu</small></span></a>
  <div class="ph-auth-heading"><span class="system-eyebrow"><?= $needsFirstAdmin ? 'GÜVENLİ BAŞLANGIÇ' : 'GÜVENLİ ERİŞİM' ?></span><h1><?= $needsFirstAdmin ? 'İlk yöneticiyi oluşturun' : 'Tekrar hoş geldiniz' ?></h1><p><?= $needsFirstAdmin ? 'Hazır hesap oluşturulmaz. Sistemi yönetecek ilk hesabı siz belirleyin.' : 'Çalışma alanınıza devam etmek için giriş yapın.' ?></p></div>
  <?php if ($error !== ''): ?><div class="alert alert-danger d-flex gap-2"><i class="bi bi-exclamation-circle-fill"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>
  <form method="post" autocomplete="on"><input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
   <?php if ($needsFirstAdmin): ?><label class="ph-auth-field"><span>Ad soyad</span><div><i class="bi bi-person"></i><input name="ad_soyad" value="<?= htmlspecialchars((string)($_POST['ad_soyad'] ?? '')) ?>" maxlength="150" required autofocus autocomplete="name" placeholder="Adınız Soyadınız"></div></label><?php endif; ?>
   <label class="ph-auth-field"><span>E-posta adresi</span><div><i class="bi bi-envelope"></i><input name="eposta" type="email" value="<?= htmlspecialchars($email) ?>" maxlength="190" required <?= $needsFirstAdmin ? '' : 'autofocus' ?> autocomplete="username" placeholder="ornek@sirket.com"></div></label>
   <label class="ph-auth-field"><span>Parola</span><div><i class="bi bi-lock"></i><input id="loginPassword" name="parola" type="password" minlength="8" required autocomplete="<?= $needsFirstAdmin ? 'new-password' : 'current-password' ?>" placeholder="En az 8 karakter"><button class="ph-password-toggle" type="button" data-password-toggle="loginPassword" aria-label="Parolayı göster"><i class="bi bi-eye"></i></button></div></label>
   <?php if ($needsFirstAdmin): ?><label class="ph-auth-field"><span>Parola tekrarı</span><div><i class="bi bi-shield-lock"></i><input id="loginPasswordAgain" name="parola_tekrar" type="password" minlength="8" required autocomplete="new-password" placeholder="Parolayı yeniden yazın"><button class="ph-password-toggle" type="button" data-password-toggle="loginPasswordAgain" aria-label="Parolayı göster"><i class="bi bi-eye"></i></button></div></label><?php endif; ?>
   <button class="ph-auth-submit" type="submit"><?= $needsFirstAdmin ? 'Yönetici hesabını oluştur' : 'Giriş yap' ?><i class="bi bi-arrow-right"></i></button>
  </form><p class="ph-auth-footer">© <?= date('Y') ?> Zirve · Yönetim Platformu</p>
 </div></section>
 <section class="ph-auth-visual"><div class="ph-auth-grid"></div><div class="ph-auth-copy"><span>SÜREÇLERİNİZ TEK MERKEZDE</span><h2>Personelden hakedişe,<br>kontrollü ve izlenebilir.</h2><p><i class="bi bi-check2"></i> Rol bazlı güvenli erişim</p><p><i class="bi bi-check2"></i> Bordro ve puantaj yönetimi</p><p><i class="bi bi-check2"></i> Kayıt altına alınan işlemler</p></div><strong class="ph-auth-watermark">Z</strong></section>
</main><script>document.querySelectorAll('[data-password-toggle]').forEach(function(button){button.addEventListener('click',function(){var input=document.getElementById(button.dataset.passwordToggle);var show=input.type==='password';input.type=show?'text':'password';button.innerHTML='<i class="bi bi-eye'+(show?'-slash':'')+'"></i>';});});</script></body></html>
