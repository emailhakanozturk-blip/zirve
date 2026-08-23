# Personel Puantaj ve Hakediş Modülü

PHP 8, MySQL, Bootstrap 5, PhpSpreadsheet ve Dompdf ile hazırlanmış tek ana sayfalı modüldür. Uygulama verisi JSON dosyalarında tutulmaz; AJAX yanıtları dışında kalıcı veri bütünüyle ilişkisel MySQL tablolarındadır.

## Kurulum

1. `config/personel-hakedis.php` içindeki bağlantı değerlerini düzenleyin veya `DB_DSN`, `DB_USER`, `DB_PASSWORD` ortam değişkenlerini tanımlayın.
2. `migrations/2026_08_05_000001_personel_hakedis.sql` dosyasını hedef veritabanında bir kez çalıştırın.
3. Bağımlılıkları kurun: `php composer.phar install`. XAMPP PHP yapılandırmasında `gd`, `mbstring`, `zip`, `xml` ve `pdo_mysql` uzantılarının açık olması önerilir.
4. `personel-hakedis.php` sayfasını mevcut menüye ekleyin.

Bağımlılıklar bu çalışma kopyasında kurulmuş ve `composer.lock` ile sabitlenmiştir. `vendor` kaynak kontrolüne alınmaz.

## Mevcut sisteme entegrasyon

Mevcut uygulamanın bootstrap dosyasını `includes/personel-hakedis-bootstrap.php` çağrısından önce yükleyin. Bootstrap `$pdo` isimli bir PDO nesnesi sağlarsa modül kendi bağlantısını açmaz. Kullanıcı bilgileri için aşağıdaki oturum alanları desteklenir:

- `$_SESSION['user_id']`
- `$_SESSION['user_name']`
- `$_SESSION['is_admin']`
- `$_SESSION['permissions']` içinde `personel_hakedis.yaz`

Mevcut sistem farklı alan adları kullanıyorsa yalnız bootstrap adaptörünü değiştirmeniz yeterlidir. Sidebar/header HTML'i de bu sayfadaki bağımsız kabuk yerine mevcut layout include'larıyla değiştirilebilir; servis ve API koduna dokunmak gerekmez.

## Excel kolonları

Bordro ve kurum puantajı dosyalarının ilk satırı başlıktır. Personel eşleşmesi önce `T.C. Kimlik No`, ardından `SGK Sicil No` ile yapılır; `Ad Soyad` zorunludur. Otomatik puantajın tanıdığı standart başlıklar:

`Normal Gün`, `Hafta Tatili`, `Fazla Mesai`, `Rapor`, `Ücretli İzin`, `Ücretsiz İzin`, `Resmî Tatil`, `Gece Mesaisi`, `Yol Günü`, `Yemek Günü`, `Prim Günü`.

Diğer tüm kolonlar bordro kalemi olarak saklanır. Sendikal haktaki “Bordrodaki kalem adı” bu Excel başlığıyla eşleşmelidir.

## Ana dosyalar

- `personel-hakedis.php`: tek sayfalı arayüz
- `api/personel-hakedis-api.php`: AJAX ve dışa aktarma uçları
- `src/ModuleService.php`: tanımlar, puantaj ve hakediş iş kuralları
- `src/SpreadsheetService.php`: doğrudan SQL'e Excel aktarımı ve karşılaştırma
- `src/ReportService.php`: Excel/PDF raporlar
- `migrations/2026_08_05_000001_personel_hakedis.sql`: ilişkisel şema

## Güvenlik ve veri geçmişi

PDO prepared statement, CSRF denetimi ve mevcut oturum yetkisi kullanılır. Ana tanımlar fiziksel silinmez; `aktif=0` yapılır. Personel iş değişimleri `personel_is_gecmisi`, puantaj değişiklikleri `islem_loglari`, bordro tekrarları ise sürüm zinciri ile korunur. Hakediş kümülatifi gün/saatten değil, kişi ve hak kalemi bazında önceki kümülatif tutar + bu ayki kazanç üzerinden hesaplanır.
