SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS roller (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ad VARCHAR(120) NOT NULL,
 rol_anahtari VARCHAR(80) NOT NULL,
 aciklama VARCHAR(255) NULL,
 aktif TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_roller_anahtar (rol_anahtari)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS kullanicilar (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ad_soyad VARCHAR(150) NOT NULL,
 eposta VARCHAR(190) NOT NULL,
 parola_hash VARCHAR(255) NOT NULL,
 aktif TINYINT(1) NOT NULL DEFAULT 1,
 son_giris_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_kullanicilar_eposta (eposta),
 KEY ix_kullanicilar_aktif (aktif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS kullanici_rolleri (
 kullanici_id BIGINT UNSIGNED NOT NULL,
 rol_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (kullanici_id, rol_id),
 CONSTRAINT fk_kr_kullanici FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE CASCADE,
 CONSTRAINT fk_kr_rol FOREIGN KEY (rol_id) REFERENCES roller(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS yetkiler (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 yetki_anahtari VARCHAR(120) NOT NULL,
 ad VARCHAR(160) NOT NULL,
 menu_grubu VARCHAR(120) NOT NULL,
 menu_adi VARCHAR(120) NOT NULL,
 yetki_turu ENUM('goruntule','islem') NOT NULL DEFAULT 'goruntule',
 sira SMALLINT UNSIGNED NOT NULL DEFAULT 100,
 aktif TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_yetkiler_anahtar (yetki_anahtari),
 KEY ix_yetkiler_menu (menu_grubu, sira)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS rol_yetkileri (
 rol_id BIGINT UNSIGNED NOT NULL,
 yetki_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (rol_id, yetki_id),
 CONSTRAINT fk_ry_rol FOREIGN KEY (rol_id) REFERENCES roller(id) ON DELETE CASCADE,
 CONSTRAINT fk_ry_yetki FOREIGN KEY (yetki_id) REFERENCES yetkiler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS kullanici_yetkileri (
 kullanici_id BIGINT UNSIGNED NOT NULL,
 yetki_id BIGINT UNSIGNED NOT NULL,
 izinli TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (kullanici_id, yetki_id),
 CONSTRAINT fk_ky_kullanici FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE CASCADE,
 CONSTRAINT fk_ky_yetki FOREIGN KEY (yetki_id) REFERENCES yetkiler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

INSERT INTO roller (ad, rol_anahtari, aciklama) VALUES
 ('Sistem Yöneticisi','system_admin','Tüm menü ve işlemlere erişir.'),
 ('Yönetici','manager','Personel ve hakediş süreçlerini yönetir.'),
 ('Görüntüleyici','viewer','Yalnızca görüntüleme yetkileriyle çalışır.')
ON DUPLICATE KEY UPDATE ad=VALUES(ad),aciklama=VALUES(aciklama),aktif=1;

INSERT INTO yetkiler (yetki_anahtari,ad,menu_grubu,menu_adi,yetki_turu,sira) VALUES
 ('dashboard.view','Ana paneli görüntüleme','Çalışma Alanı','Ana Panel','goruntule',10),
 ('contracts.view','Sözleşmeleri görüntüleme','Personel Puantaj','Sözleşmeler','goruntule',20),
 ('contracts.manage','Sözleşme işlemleri','Personel Puantaj','Sözleşmeler','islem',21),
 ('jobs.view','İş tanımlarını görüntüleme','Personel Puantaj','İş Tanımları','goruntule',30),
 ('jobs.manage','İş tanımı işlemleri','Personel Puantaj','İş Tanımları','islem',31),
 ('personnel.view','Personelleri görüntüleme','Personel Puantaj','Personeller','goruntule',40),
 ('personnel.manage','Personel işlemleri','Personel Puantaj','Personeller','islem',41),
 ('unions.view','Sendika ve TİS bilgilerini görüntüleme','Personel Puantaj','Sendikalar ve TİS','goruntule',50),
 ('unions.manage','Sendika ve TİS işlemleri','Personel Puantaj','Sendikalar ve TİS','islem',51),
 ('payroll.view','Bordro ve puantajı görüntüleme','Personel Puantaj','Bordro ve Puantaj','goruntule',60),
 ('payroll.manage','Bordro ve puantaj işlemleri','Personel Puantaj','Bordro ve Puantaj','islem',61),
 ('progress.view','Hakediş ve raporları görüntüleme','Personel Puantaj','Hakediş ve Raporlar','goruntule',70),
 ('progress.manage','Hakediş işlemleri','Personel Puantaj','Hakediş ve Raporlar','islem',71),
 ('system.users.manage','Kullanıcı hesaplarını yönetme','Sistem Yönetimi','Kullanıcı Yönetimi','islem',100),
 ('system.permissions.manage','Kullanıcı yetkilerini yönetme','Sistem Yönetimi','Yetkilendirme','islem',110),
 ('system.logs.view','İşlem loglarını görüntüleme','Sistem Yönetimi','İşlem Logları','goruntule',120)
ON DUPLICATE KEY UPDATE ad=VALUES(ad),menu_grubu=VALUES(menu_grubu),menu_adi=VALUES(menu_adi),yetki_turu=VALUES(yetki_turu),sira=VALUES(sira),aktif=1;

INSERT IGNORE INTO rol_yetkileri (rol_id,yetki_id)
SELECT r.id,y.id FROM roller r CROSS JOIN yetkiler y WHERE r.rol_anahtari='system_admin';

INSERT IGNORE INTO rol_yetkileri (rol_id,yetki_id)
SELECT r.id,y.id FROM roller r CROSS JOIN yetkiler y
WHERE r.rol_anahtari='manager' AND y.yetki_anahtari NOT LIKE 'system.%';

INSERT IGNORE INTO rol_yetkileri (rol_id,yetki_id)
SELECT r.id,y.id FROM roller r CROSS JOIN yetkiler y
WHERE r.rol_anahtari='viewer' AND y.yetki_turu='goruntule' AND y.yetki_anahtari NOT LIKE 'system.%';

-- ROLLBACK AÇIKLAMASI
-- Bu migration mevcut uygulama verilerini silmeden yalnızca kullanıcı yönetimi tablolarını
-- ve başlangıç rol/yetki kayıtlarını ekler. Otomatik rollback özellikle tanımlanmamıştır.
-- Geri dönüş gerektiğinde önce bu tablolarda canlı kullanıcı veya yetki kaydı bulunmadığı
-- doğrulanmalı ve tam veritabanı yedeği alınmalıdır. Ardından, dış anahtar sırasına dikkat
-- edilerek yalnızca bu migration'ın oluşturduğu tablolar manuel olarak kaldırılabilir:
-- kullanici_yetkileri, rol_yetkileri, kullanici_rolleri, yetkiler, kullanicilar, roller.
-- Üretim ortamında veri varken DROP TABLE uygulanmamalıdır; kodun önceki sürümüne dönmek
-- tabloları yerinde bırakmak için güvenli ve önerilen geri dönüş yöntemidir.
