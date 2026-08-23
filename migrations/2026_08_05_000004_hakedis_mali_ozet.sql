SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS hakedis_mali_ozetleri (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 hakedis_id BIGINT UNSIGNED NOT NULL,
 hak_edis_no VARCHAR(30) NULL,
 rapor_tarihi DATE NOT NULL,
 sozlesme_fiyatlariyla_hizmet DECIMAL(18,2) NOT NULL DEFAULT 0,
 fiyat_farki DECIMAL(18,2) NOT NULL DEFAULT 0,
 onceki_hakedis_toplami DECIMAL(18,2) NOT NULL DEFAULT 0,
 bu_hakedis_tutari DECIMAL(18,2) NOT NULL DEFAULT 0,
 kdv_orani DECIMAL(8,4) NOT NULL DEFAULT 0,
 kdv_tutari DECIMAL(18,2) NOT NULL DEFAULT 0,
 tahakkuk_tutari DECIMAL(18,2) NOT NULL DEFAULT 0,
 damga_vergisi DECIMAL(18,2) NOT NULL DEFAULT 0,
 kesinti_toplami DECIMAL(18,2) NOT NULL DEFAULT 0,
 odenecek_tutar DECIMAL(18,2) NOT NULL DEFAULT 0,
 kaynak_dosya VARCHAR(255) NULL,
 dosya_hash CHAR(64) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_hmo_hakedis (hakedis_id),
 CONSTRAINT fk_hmo_hakedis FOREIGN KEY (hakedis_id) REFERENCES hakedisler(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS hakedis_genel_kalemleri (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 hakedis_id BIGINT UNSIGNED NOT NULL,
 kalem_adi VARCHAR(200) NOT NULL,
 oran DECIMAL(8,4) NULL,
 onceki_tutar DECIMAL(18,2) NOT NULL DEFAULT 0,
 bu_hakedis_tutari DECIMAL(18,2) NOT NULL DEFAULT 0,
 kumulatif_tutar DECIMAL(18,2) NOT NULL DEFAULT 0,
 sira SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_hgk_hakedis (hakedis_id,sira),
 CONSTRAINT fk_hgk_hakedis FOREIGN KEY (hakedis_id) REFERENCES hakedisler(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
