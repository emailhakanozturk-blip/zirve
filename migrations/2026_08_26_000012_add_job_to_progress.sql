SET NAMES utf8mb4;

ALTER TABLE hakedisler
 ADD COLUMN IF NOT EXISTS is_id BIGINT UNSIGNED NULL AFTER sozlesme_id;

ALTER TABLE hakedisler
 ADD INDEX IF NOT EXISTS ix_hakedis_is_donem (is_id,yil,ay,aktif);

SET @fk_hakedis_is_exists = (
 SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
 WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='hakedisler' AND CONSTRAINT_NAME='fk_h_is'
);
SET @fk_hakedis_is_sql = IF(
 @fk_hakedis_is_exists=0,
 'ALTER TABLE hakedisler ADD CONSTRAINT fk_h_is FOREIGN KEY (is_id) REFERENCES is_tanimlari(id)',
 'SELECT 1'
);
PREPARE fk_hakedis_is_statement FROM @fk_hakedis_is_sql;
EXECUTE fk_hakedis_is_statement;
DEALLOCATE PREPARE fk_hakedis_is_statement;

UPDATE hakedisler h
JOIN (
 SELECT hakedis_id,MIN(is_id) is_id
 FROM hakedis_detaylari
 GROUP BY hakedis_id
 HAVING COUNT(DISTINCT is_id)=1
) d ON d.hakedis_id=h.id
SET h.is_id=d.is_id
WHERE h.is_id IS NULL;

-- ROLLBACK AÇIKLAMASI
-- Bu migration mevcut hakedişleri silmez. İş bilgisi tek olan eski hakedişleri güvenli
-- biçimde iş tanımıyla ilişkilendirir. Uygulama geri alınacaksa is_id sütunu ve indeksi
-- yerinde bırakılabilir. Fiziksel kaldırma otomatik rollback kapsamında değildir; canlı
-- veriler ve dış anahtar kullanımı doğrulanmadan DROP COLUMN/CONSTRAINT uygulanmamalıdır.
