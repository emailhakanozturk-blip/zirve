SET NAMES utf8mb4;

-- İş bazlı hakediş kodu hakedisler.is_id alanını kullanır. Bu migration,
-- daha önceki şema güncellemesi uygulanmamış kurulumları güvenle tamamlar.

SET @hakedis_is_id_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'hakedisler'
      AND COLUMN_NAME = 'is_id'
);
SET @hakedis_is_id_sql = IF(
    @hakedis_is_id_exists = 0,
    'ALTER TABLE hakedisler ADD COLUMN is_id BIGINT UNSIGNED NULL AFTER sozlesme_id',
    'SELECT 1'
);
PREPARE hakedis_is_id_statement FROM @hakedis_is_id_sql;
EXECUTE hakedis_is_id_statement;
DEALLOCATE PREPARE hakedis_is_id_statement;

SET @hakedis_is_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'hakedisler'
      AND INDEX_NAME = 'ix_hakedis_is_donem'
);
SET @hakedis_is_index_sql = IF(
    @hakedis_is_index_exists = 0,
    'ALTER TABLE hakedisler ADD INDEX ix_hakedis_is_donem (is_id, yil, ay, aktif)',
    'SELECT 1'
);
PREPARE hakedis_is_index_statement FROM @hakedis_is_index_sql;
EXECUTE hakedis_is_index_statement;
DEALLOCATE PREPARE hakedis_is_index_statement;

-- Yalnızca tek bir işe ait olduğu kesin olan eski hakedişler doldurulur.
-- Birden fazla işe ait eski kayıtlar NULL bırakılarak yanlış eşleştirme önlenir.
UPDATE hakedisler h
JOIN (
    SELECT hakedis_id, MIN(is_id) AS is_id
    FROM hakedis_detaylari
    GROUP BY hakedis_id
    HAVING COUNT(DISTINCT is_id) = 1
) d ON d.hakedis_id = h.id
SET h.is_id = d.is_id
WHERE h.is_id IS NULL;

SET @hakedis_is_fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'hakedisler'
      AND CONSTRAINT_NAME = 'fk_h_is'
);
SET @hakedis_is_fk_sql = IF(
    @hakedis_is_fk_exists = 0,
    'ALTER TABLE hakedisler ADD CONSTRAINT fk_h_is FOREIGN KEY (is_id) REFERENCES is_tanimlari(id)',
    'SELECT 1'
);
PREPARE hakedis_is_fk_statement FROM @hakedis_is_fk_sql;
EXECUTE hakedis_is_fk_statement;
DEALLOCATE PREPARE hakedis_is_fk_statement;

-- ROLLBACK AÇIKLAMASI
-- Migration veri silmez. Uygulama iş bazlı hakedişlerde is_id alanına bağlı olduğu için
-- sütun, indeks ve dış anahtarın geri alınması önerilmez. Kod eski sürüme döndürülse bile
-- bu yapı yerinde bırakılabilir; mevcut kayıtların çalışmasını etkilemez.
