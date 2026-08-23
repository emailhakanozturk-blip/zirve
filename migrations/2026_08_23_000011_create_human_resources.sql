SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ik_personel_profilleri (
 personel_id BIGINT UNSIGNED NOT NULL,
 personel_kodu VARCHAR(50) NULL,
 unvan VARCHAR(150) NULL,
 isyeri_adi VARCHAR(200) NULL,
 cinsiyet VARCHAR(40) NULL,
 medeni_hali VARCHAR(60) NULL,
 ogrenim_durumu VARCHAR(120) NULL,
 hesaplama_sekli VARCHAR(120) NULL,
 meslek_kodu VARCHAR(50) NULL,
 meslek_kodu_tanimi VARCHAR(200) NULL,
 ucret_tutari DECIMAL(18,2) NULL,
 taseron_nakil TINYINT(1) NOT NULL DEFAULT 0,
 created_by BIGINT UNSIGNED NULL,
 updated_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (personel_id),
 UNIQUE KEY uq_ik_personel_kodu (personel_kodu),
 KEY ix_ik_profil_isyeri (isyeri_adi),
 KEY ix_ik_profil_unvan (unvan),
 KEY ix_ik_profil_rapor (cinsiyet,medeni_hali,ogrenim_durumu),
 CONSTRAINT fk_ik_profil_personel FOREIGN KEY (personel_id) REFERENCES personeller(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS ik_izin_kayitlari (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 personel_id BIGINT UNSIGNED NOT NULL,
 izin_turu VARCHAR(120) NOT NULL,
 birim VARCHAR(180) NULL,
 sure_gun DECIMAL(8,2) NOT NULL DEFAULT 0,
 baslangic_tarihi DATE NULL,
 bitis_tarihi DATE NULL,
 aciklama VARCHAR(500) NULL,
 aktif TINYINT(1) NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NULL,
 updated_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 KEY ix_ik_izin_personel (personel_id,aktif),
 KEY ix_ik_izin_tarih (baslangic_tarihi,bitis_tarihi),
 KEY ix_ik_izin_tur (izin_turu,aktif),
 CONSTRAINT fk_ik_izin_personel FOREIGN KEY (personel_id) REFERENCES personeller(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

INSERT INTO yetkiler (yetki_anahtari,ad,menu_grubu,menu_adi,yetki_turu,sira) VALUES
 ('hr.view','İnsan kaynakları personel listesini görüntüleme','Personel Puantaj','İnsan Kaynakları','goruntule',45),
 ('hr.manage','İnsan kaynakları profil ve izin işlemleri','Personel Puantaj','İnsan Kaynakları','islem',46),
 ('hr.reports.view','İnsan kaynakları raporlarını görüntüleme','Personel Puantaj','İK Raporları','goruntule',47)
ON DUPLICATE KEY UPDATE ad=VALUES(ad),menu_grubu=VALUES(menu_grubu),menu_adi=VALUES(menu_adi),yetki_turu=VALUES(yetki_turu),sira=VALUES(sira),aktif=1;

INSERT IGNORE INTO rol_yetkileri (rol_id,yetki_id)
SELECT r.id,y.id FROM roller r CROSS JOIN yetkiler y
WHERE r.rol_anahtari='system_admin' AND y.yetki_anahtari LIKE 'hr.%';

INSERT IGNORE INTO rol_yetkileri (rol_id,yetki_id)
SELECT r.id,y.id FROM roller r CROSS JOIN yetkiler y
WHERE r.rol_anahtari='manager' AND y.yetki_anahtari LIKE 'hr.%';

INSERT IGNORE INTO rol_yetkileri (rol_id,yetki_id)
SELECT r.id,y.id FROM roller r CROSS JOIN yetkiler y
WHERE r.rol_anahtari='viewer' AND y.yetki_anahtari IN ('hr.view','hr.reports.view');

-- ROLLBACK AÇIKLAMASI
-- Bu migration mevcut personelleri değiştirmez veya silmez. İK profilleri, izin kayıtları
-- ve rol/yetki tanımları ekler. Güvenli geri dönüş için uygulama önce önceki sürüme alınmalı;
-- tablolar canlı veriler korunarak yerinde bırakılmalıdır. Fiziksel tablo kaldırma otomatik
-- rollback kapsamında değildir ve yalnızca tam yedek ile veri bulunmadığı doğrulandıktan sonra
-- kontrollü bakım işleminde değerlendirilmelidir.
