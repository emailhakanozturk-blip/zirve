SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS personel_sendikal_hak_tarihcesi (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 personel_id BIGINT UNSIGNED NOT NULL,
 sendikal_hak_id BIGINT UNSIGNED NOT NULL,
 tis_donem_id BIGINT UNSIGNED NOT NULL,
 bordro_id BIGINT UNSIGNED NULL,
 yil SMALLINT UNSIGNED NOT NULL,
 ay TINYINT UNSIGNED NOT NULL,
 birim VARCHAR(20) NOT NULL,
 birim_fiyat DECIMAL(18,2) NOT NULL DEFAULT 0,
 bu_hakedis_miktari DECIMAL(18,2) NOT NULL DEFAULT 0,
 bu_hakedis_tutari DECIMAL(18,2) NOT NULL DEFAULT 0,
 toplam_miktar DECIMAL(18,2) NOT NULL DEFAULT 0,
 toplam_tutar DECIMAL(18,2) NOT NULL DEFAULT 0,
 kaynak_dosya VARCHAR(255) NOT NULL,
 dosya_hash CHAR(64) NOT NULL,
 kaynak_satir INT UNSIGNED NOT NULL,
 gecerlilik_tarihi DATE NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_psht_kaynak (dosya_hash,kaynak_satir),
 KEY ix_psht_personel_donem (personel_id,yil,ay),
 KEY ix_psht_hak_donem (sendikal_hak_id,yil,ay),
 CONSTRAINT fk_psht_personel FOREIGN KEY (personel_id) REFERENCES personeller(id),
 CONSTRAINT fk_psht_hak FOREIGN KEY (sendikal_hak_id) REFERENCES sendikal_haklar(id),
 CONSTRAINT fk_psht_tis FOREIGN KEY (tis_donem_id) REFERENCES tis_donemleri(id),
 CONSTRAINT fk_psht_bordro FOREIGN KEY (bordro_id) REFERENCES bordrolar(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
