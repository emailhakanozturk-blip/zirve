SET NAMES utf8mb4;

ALTER TABLE sendikal_haklar
 ADD COLUMN hak_grup_kodu CHAR(36) NULL AFTER tis_donem_id,
 ADD COLUMN surum SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER hak_grup_kodu,
 ADD COLUMN onceki_hak_id BIGINT UNSIGNED NULL AFTER surum,
 ADD KEY ix_hak_grup_surum (hak_grup_kodu, surum),
 ADD KEY ix_hak_gecerlilik (tis_donem_id, gecerlilik_baslangic, gecerlilik_bitis),
 ADD CONSTRAINT fk_hak_onceki FOREIGN KEY (onceki_hak_id) REFERENCES sendikal_haklar(id);

UPDATE sendikal_haklar
SET hak_grup_kodu = CONCAT(
 SUBSTRING(MD5(CONCAT('sendikal-hak-', id)),1,8), '-',
 SUBSTRING(MD5(CONCAT('sendikal-hak-', id)),9,4), '-',
 SUBSTRING(MD5(CONCAT('sendikal-hak-', id)),13,4), '-',
 SUBSTRING(MD5(CONCAT('sendikal-hak-', id)),17,4), '-',
 SUBSTRING(MD5(CONCAT('sendikal-hak-', id)),21,12)
)
WHERE hak_grup_kodu IS NULL;

ALTER TABLE sendikal_haklar
 MODIFY hak_grup_kodu CHAR(36) NOT NULL;
