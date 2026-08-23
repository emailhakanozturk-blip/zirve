SET NAMES utf8mb4;

ALTER TABLE hakedis_detaylari
 ADD COLUMN bordro_id BIGINT UNSIGNED NULL AFTER hakedis_id,
 ADD COLUMN birim VARCHAR(20) NULL AFTER hak_kalemi,
 ADD COLUMN birim_fiyat DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER birim,
 ADD COLUMN toplam_miktar DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER birim_fiyat,
 ADD COLUMN onceki_miktar DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER toplam_miktar,
 ADD COLUMN bu_hakedis_miktari DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER onceki_miktar,
 ADD KEY ix_hd_bordro (bordro_id),
 ADD CONSTRAINT fk_hd_bordro FOREIGN KEY (bordro_id) REFERENCES bordrolar(id);

CREATE TABLE hakedis_bordrolari (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 hakedis_id BIGINT UNSIGNED NOT NULL,
 bordro_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_hakedis_bordro (hakedis_id,bordro_id),
 CONSTRAINT fk_hb_hakedis FOREIGN KEY (hakedis_id) REFERENCES hakedisler(id),
 CONSTRAINT fk_hb_bordro FOREIGN KEY (bordro_id) REFERENCES bordrolar(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
