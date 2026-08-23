SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sendikalar (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ad VARCHAR(180) NOT NULL, aciklama TEXT NULL,
 aktif TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_sendika_ad (ad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS sozlesmeler (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ad VARCHAR(200) NOT NULL, numara VARCHAR(100) NOT NULL,
 idare VARCHAR(200) NOT NULL, baslangic_tarihi DATE NOT NULL, bitis_tarihi DATE NOT NULL,
 sozlesme_bedeli DECIMAL(18,2) NOT NULL DEFAULT 0, aciklama TEXT NULL, aktif TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_sozlesme_numara (numara), KEY ix_sozlesme_tarih (baslangic_tarihi, bitis_tarihi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS is_tanimlari (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ad VARCHAR(200) NOT NULL, aciklama TEXT NULL,
 aktif TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS sozlesme_isleri (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, sozlesme_id BIGINT UNSIGNED NOT NULL, is_id BIGINT UNSIGNED NOT NULL,
 baslangic_tarihi DATE NULL, bitis_tarihi DATE NULL, aktif TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_sozlesme_is (sozlesme_id,is_id),
 CONSTRAINT fk_si_sozlesme FOREIGN KEY (sozlesme_id) REFERENCES sozlesmeler(id),
 CONSTRAINT fk_si_is FOREIGN KEY (is_id) REFERENCES is_tanimlari(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS tis_donemleri (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ad VARCHAR(180) NOT NULL, sendika_id BIGINT UNSIGNED NOT NULL,
 baslangic_tarihi DATE NOT NULL, bitis_tarihi DATE NOT NULL, aciklama TEXT NULL, aktif TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_tis_sendika FOREIGN KEY (sendika_id) REFERENCES sendikalar(id), KEY ix_tis_tarih (baslangic_tarihi,bitis_tarihi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS personeller (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ad_soyad VARCHAR(200) NOT NULL, tc_kimlik_no CHAR(11) NULL,
 sgk_sicil_no VARCHAR(50) NULL, ise_giris_tarihi DATE NOT NULL, isten_cikis_tarihi DATE NULL,
 sendika_id BIGINT UNSIGNED NULL, aktif TINYINT(1) NOT NULL DEFAULT 1, aciklama TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_personel_tc (tc_kimlik_no), KEY ix_personel_sgk (sgk_sicil_no),
 CONSTRAINT fk_personel_sendika FOREIGN KEY (sendika_id) REFERENCES sendikalar(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS personel_is_gecmisi (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, personel_id BIGINT UNSIGNED NOT NULL, sozlesme_id BIGINT UNSIGNED NOT NULL,
 is_id BIGINT UNSIGNED NOT NULL, tis_donem_id BIGINT UNSIGNED NULL, baslangic_tarihi DATE NOT NULL, bitis_tarihi DATE NULL,
 aktif TINYINT(1) NOT NULL DEFAULT 1, aciklama TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_pig_personel FOREIGN KEY (personel_id) REFERENCES personeller(id),
 CONSTRAINT fk_pig_sozlesme FOREIGN KEY (sozlesme_id) REFERENCES sozlesmeler(id),
 CONSTRAINT fk_pig_is FOREIGN KEY (is_id) REFERENCES is_tanimlari(id),
 CONSTRAINT fk_pig_tis FOREIGN KEY (tis_donem_id) REFERENCES tis_donemleri(id),
 KEY ix_pig_personel_tarih (personel_id,baslangic_tarihi,bitis_tarihi), KEY ix_pig_is (sozlesme_id,is_id,aktif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS sendikal_haklar (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tis_donem_id BIGINT UNSIGNED NOT NULL, hak_adi VARCHAR(180) NOT NULL,
 bordro_kalem_adi VARCHAR(180) NOT NULL, birim ENUM('gun','saat','adet','ay','tutar') NOT NULL,
 birim_fiyat DECIMAL(18,2) NOT NULL DEFAULT 0, hesaplama_sekli ENUM('miktar_x_fiyat','sabit','bordro_tutari') NOT NULL DEFAULT 'miktar_x_fiyat',
 hakedise_dahil TINYINT(1) NOT NULL DEFAULT 1, gecerlilik_baslangic DATE NOT NULL, gecerlilik_bitis DATE NULL,
 aktif TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_hak_tis FOREIGN KEY (tis_donem_id) REFERENCES tis_donemleri(id),
 KEY ix_hak_donem (tis_donem_id,aktif), KEY ix_hak_bordro_adi (bordro_kalem_adi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS personel_donem_haklari (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, personel_id BIGINT UNSIGNED NOT NULL, sendikal_hak_id BIGINT UNSIGNED NOT NULL,
 tis_donem_id BIGINT UNSIGNED NOT NULL, birim VARCHAR(20) NOT NULL, birim_fiyat DECIMAL(18,2) NOT NULL,
 hesaplama_sekli VARCHAR(40) NOT NULL, hakedise_dahil TINYINT(1) NOT NULL,
 gecerlilik_baslangic DATE NOT NULL, gecerlilik_bitis DATE NULL, aktif TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_pdh (personel_id,sendikal_hak_id,tis_donem_id),
 CONSTRAINT fk_pdh_personel FOREIGN KEY (personel_id) REFERENCES personeller(id),
 CONSTRAINT fk_pdh_hak FOREIGN KEY (sendikal_hak_id) REFERENCES sendikal_haklar(id),
 CONSTRAINT fk_pdh_tis FOREIGN KEY (tis_donem_id) REFERENCES tis_donemleri(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS bordrolar (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, yil SMALLINT UNSIGNED NOT NULL, ay TINYINT UNSIGNED NOT NULL,
 sozlesme_id BIGINT UNSIGNED NOT NULL, is_id BIGINT UNSIGNED NOT NULL, tis_donem_id BIGINT UNSIGNED NOT NULL,
 surum SMALLINT UNSIGNED NOT NULL DEFAULT 1, onceki_bordro_id BIGINT UNSIGNED NULL, dosya_adi VARCHAR(255) NOT NULL,
 dosya_hash CHAR(64) NOT NULL, durum ENUM('isleniyor','tamamlandi','hatali','iptal') NOT NULL DEFAULT 'isleniyor',
 aktif TINYINT(1) NOT NULL DEFAULT 1, yukleyen_kullanici_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_bordro_surum (yil,ay,sozlesme_id,is_id,surum), KEY ix_bordro_donem (yil,ay,sozlesme_id,is_id,aktif),
 CONSTRAINT fk_bordro_sozlesme FOREIGN KEY (sozlesme_id) REFERENCES sozlesmeler(id),
 CONSTRAINT fk_bordro_is FOREIGN KEY (is_id) REFERENCES is_tanimlari(id),
 CONSTRAINT fk_bordro_tis FOREIGN KEY (tis_donem_id) REFERENCES tis_donemleri(id),
 CONSTRAINT fk_bordro_onceki FOREIGN KEY (onceki_bordro_id) REFERENCES bordrolar(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS bordro_personelleri (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, bordro_id BIGINT UNSIGNED NOT NULL, personel_id BIGINT UNSIGNED NULL,
 satir_no INT UNSIGNED NOT NULL, tc_kimlik_no VARCHAR(11) NULL, sgk_sicil_no VARCHAR(50) NULL, ad_soyad VARCHAR(200) NOT NULL,
 eslesme_durumu ENUM('eslesti','eslesmedi','coklu') NOT NULL DEFAULT 'eslesmedi', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_bp_satir (bordro_id,satir_no), CONSTRAINT fk_bp_bordro FOREIGN KEY (bordro_id) REFERENCES bordrolar(id),
 CONSTRAINT fk_bp_personel FOREIGN KEY (personel_id) REFERENCES personeller(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS bordro_kalemleri (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, bordro_personel_id BIGINT UNSIGNED NOT NULL, kalem_adi VARCHAR(180) NOT NULL,
 miktar DECIMAL(10,2) NOT NULL DEFAULT 0, tutar DECIMAL(18,2) NOT NULL DEFAULT 0, kaynak_sutun VARCHAR(180) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_bk_bp FOREIGN KEY (bordro_personel_id) REFERENCES bordro_personelleri(id),
 KEY ix_bk_kalem (kalem_adi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS puantajlar (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, bordro_id BIGINT UNSIGNED NOT NULL, personel_id BIGINT UNSIGNED NOT NULL,
 yil SMALLINT UNSIGNED NOT NULL, ay TINYINT UNSIGNED NOT NULL, sozlesme_id BIGINT UNSIGNED NOT NULL, is_id BIGINT UNSIGNED NOT NULL,
 normal_gun DECIMAL(10,2) NOT NULL DEFAULT 0, hafta_tatili DECIMAL(10,2) NOT NULL DEFAULT 0,
 fazla_mesai DECIMAL(10,2) NOT NULL DEFAULT 0, rapor DECIMAL(10,2) NOT NULL DEFAULT 0,
 ucretli_izin DECIMAL(10,2) NOT NULL DEFAULT 0, ucretsiz_izin DECIMAL(10,2) NOT NULL DEFAULT 0,
 resmi_tatil DECIMAL(10,2) NOT NULL DEFAULT 0, gece_mesaisi DECIMAL(10,2) NOT NULL DEFAULT 0,
 yol_gunu DECIMAL(10,2) NOT NULL DEFAULT 0, yemek_gunu DECIMAL(10,2) NOT NULL DEFAULT 0,
 prim_gunu DECIMAL(10,2) NOT NULL DEFAULT 0, durum ENUM('taslak','farkli','onayli') NOT NULL DEFAULT 'taslak',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_puantaj (bordro_id,personel_id), CONSTRAINT fk_p_bordro FOREIGN KEY (bordro_id) REFERENCES bordrolar(id),
 CONSTRAINT fk_p_personel FOREIGN KEY (personel_id) REFERENCES personeller(id),
 CONSTRAINT fk_p_sozlesme FOREIGN KEY (sozlesme_id) REFERENCES sozlesmeler(id), CONSTRAINT fk_p_is FOREIGN KEY (is_id) REFERENCES is_tanimlari(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS puantaj_detaylari (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, puantaj_id BIGINT UNSIGNED NOT NULL, kalem_adi VARCHAR(180) NOT NULL,
 miktar DECIMAL(10,2) NOT NULL DEFAULT 0, tutar DECIMAL(18,2) NOT NULL DEFAULT 0, kaynak ENUM('bordro','excel','manuel') NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_pd_puantaj FOREIGN KEY (puantaj_id) REFERENCES puantajlar(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS puantaj_excel_yuklemeleri (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, yil SMALLINT UNSIGNED NOT NULL, ay TINYINT UNSIGNED NOT NULL,
 sozlesme_id BIGINT UNSIGNED NOT NULL, is_id BIGINT UNSIGNED NOT NULL, dosya_adi VARCHAR(255) NOT NULL, dosya_hash CHAR(64) NOT NULL,
 yukleyen_kullanici_id BIGINT UNSIGNED NULL, durum ENUM('isleniyor','tamamlandi','hatali') NOT NULL DEFAULT 'isleniyor',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_pey_sozlesme FOREIGN KEY (sozlesme_id) REFERENCES sozlesmeler(id),
 CONSTRAINT fk_pey_is FOREIGN KEY (is_id) REFERENCES is_tanimlari(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS puantaj_karsilastirmalari (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, yukleme_id BIGINT UNSIGNED NOT NULL, puantaj_id BIGINT UNSIGNED NOT NULL,
 personel_id BIGINT UNSIGNED NOT NULL, alan_adi VARCHAR(80) NOT NULL, sistem_degeri DECIMAL(10,2) NOT NULL,
 excel_degeri DECIMAL(10,2) NOT NULL, fark DECIMAL(10,2) NOT NULL, son_islem ENUM('bekliyor','sistem','excel','manuel') NOT NULL DEFAULT 'bekliyor',
 islem_kullanici_id BIGINT UNSIGNED NULL, islem_tarihi DATETIME NULL, UNIQUE KEY uq_pk (yukleme_id,puantaj_id,alan_adi),
 CONSTRAINT fk_pk_yukleme FOREIGN KEY (yukleme_id) REFERENCES puantaj_excel_yuklemeleri(id),
 CONSTRAINT fk_pk_puantaj FOREIGN KEY (puantaj_id) REFERENCES puantajlar(id), CONSTRAINT fk_pk_personel FOREIGN KEY (personel_id) REFERENCES personeller(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS hakedisler (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, yil SMALLINT UNSIGNED NOT NULL, ay TINYINT UNSIGNED NOT NULL,
 sozlesme_id BIGINT UNSIGNED NOT NULL, surum SMALLINT UNSIGNED NOT NULL DEFAULT 1, durum ENUM('taslak','onayli','iptal') NOT NULL DEFAULT 'taslak',
 aktif TINYINT(1) NOT NULL DEFAULT 1, toplam_tutar DECIMAL(18,2) NOT NULL DEFAULT 0, olusturan_kullanici_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_hakedis (yil,ay,sozlesme_id,surum), CONSTRAINT fk_h_sozlesme FOREIGN KEY (sozlesme_id) REFERENCES sozlesmeler(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS hakedis_detaylari (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, hakedis_id BIGINT UNSIGNED NOT NULL, is_id BIGINT UNSIGNED NOT NULL,
 personel_id BIGINT UNSIGNED NOT NULL, sendikal_hak_id BIGINT UNSIGNED NOT NULL, hak_kalemi VARCHAR(180) NOT NULL,
 bu_ayki_kazanc DECIMAL(18,2) NOT NULL DEFAULT 0, onceki_ay_toplami DECIMAL(18,2) NOT NULL DEFAULT 0,
 kumulatif_toplam DECIMAL(18,2) NOT NULL DEFAULT 0, aciklama VARCHAR(500) NULL,
 CONSTRAINT fk_hd_hakedis FOREIGN KEY (hakedis_id) REFERENCES hakedisler(id), CONSTRAINT fk_hd_is FOREIGN KEY (is_id) REFERENCES is_tanimlari(id),
 CONSTRAINT fk_hd_personel FOREIGN KEY (personel_id) REFERENCES personeller(id), CONSTRAINT fk_hd_hak FOREIGN KEY (sendikal_hak_id) REFERENCES sendikal_haklar(id),
 KEY ix_hd_kumulatif (personel_id,sendikal_hak_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS islem_loglari (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, kullanici_id BIGINT UNSIGNED NULL, islem VARCHAR(100) NOT NULL,
 tablo_adi VARCHAR(100) NOT NULL, kayit_id BIGINT UNSIGNED NULL, alan_adi VARCHAR(100) NULL,
 eski_deger TEXT NULL, yeni_deger TEXT NULL, ip_adresi VARCHAR(45) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_log_kayit (tablo_adi,kayit_id), KEY ix_log_tarih (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
