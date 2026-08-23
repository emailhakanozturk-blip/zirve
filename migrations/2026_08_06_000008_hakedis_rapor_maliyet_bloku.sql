UPDATE hakedisler h
JOIN (
    SELECT hb.hakedis_id,
           ROUND(SUM(c.baz_toplam), 2) AS baz_toplam,
           ROUND(SUM(c.toplam_hakedis_tutari), 2) AS genel_toplam
    FROM hakedis_bordrolari hb
    JOIN bordro_maliyet_ozetleri c ON c.bordro_id = hb.bordro_id
    GROUP BY hb.hakedis_id
) x ON x.hakedis_id = h.id
SET h.toplam_tutar = x.genel_toplam;

UPDATE hakedis_mali_ozetleri m
JOIN (
    SELECT hb.hakedis_id,
           ROUND(SUM(c.baz_toplam), 2) AS baz_toplam,
           ROUND(SUM(c.toplam_hakedis_tutari), 2) AS genel_toplam
    FROM hakedis_bordrolari hb
    JOIN bordro_maliyet_ozetleri c ON c.bordro_id = hb.bordro_id
    GROUP BY hb.hakedis_id
) x ON x.hakedis_id = m.hakedis_id
SET m.sozlesme_fiyatlariyla_hizmet = x.baz_toplam,
    m.bu_hakedis_tutari = x.genel_toplam,
    m.kdv_tutari = ROUND(x.genel_toplam * m.kdv_orani / 100, 2),
    m.tahakkuk_tutari = ROUND(x.genel_toplam + ROUND(x.genel_toplam * m.kdv_orani / 100, 2), 2),
    m.odenecek_tutar = ROUND(x.genel_toplam + ROUND(x.genel_toplam * m.kdv_orani / 100, 2) - m.kesinti_toplami, 2);

INSERT INTO hakedis_genel_kalemleri
    (hakedis_id, kalem_adi, oran, onceki_tutar, bu_hakedis_tutari, kumulatif_tutar, sira)
SELECT x.hakedis_id, 'SÖZLEŞME GENEL GİDERLERİ', 4.00, 0, x.genel_gider, x.genel_gider, 5
FROM (
    SELECT hb.hakedis_id, ROUND(SUM(c.genel_gider_tutari), 2) AS genel_gider
    FROM hakedis_bordrolari hb
    JOIN bordro_maliyet_ozetleri c ON c.bordro_id = hb.bordro_id
    GROUP BY hb.hakedis_id
) x
WHERE NOT EXISTS (
    SELECT 1 FROM hakedis_genel_kalemleri g
    WHERE g.hakedis_id = x.hakedis_id AND g.kalem_adi = 'SÖZLEŞME GENEL GİDERLERİ'
);

INSERT INTO hakedis_genel_kalemleri
    (hakedis_id, kalem_adi, oran, onceki_tutar, bu_hakedis_tutari, kumulatif_tutar, sira)
SELECT x.hakedis_id, 'YÜKLENİCİ KARI', 7.00, 0, x.kar_tutari, x.kar_tutari, 6
FROM (
    SELECT hb.hakedis_id, ROUND(SUM(c.kar_tutari), 2) AS kar_tutari
    FROM hakedis_bordrolari hb
    JOIN bordro_maliyet_ozetleri c ON c.bordro_id = hb.bordro_id
    GROUP BY hb.hakedis_id
) x
WHERE NOT EXISTS (
    SELECT 1 FROM hakedis_genel_kalemleri g
    WHERE g.hakedis_id = x.hakedis_id AND g.kalem_adi = 'YÜKLENİCİ KARI'
);

INSERT INTO hakedis_genel_kalemleri
    (hakedis_id, kalem_adi, oran, onceki_tutar, bu_hakedis_tutari, kumulatif_tutar, sira)
SELECT x.hakedis_id, 'GENEL TOPLAM (KDV HARİÇ)', NULL, 0, x.genel_toplam, x.genel_toplam, 7
FROM (
    SELECT hb.hakedis_id, ROUND(SUM(c.toplam_hakedis_tutari), 2) AS genel_toplam
    FROM hakedis_bordrolari hb
    JOIN bordro_maliyet_ozetleri c ON c.bordro_id = hb.bordro_id
    GROUP BY hb.hakedis_id
) x
WHERE NOT EXISTS (
    SELECT 1 FROM hakedis_genel_kalemleri g
    WHERE g.hakedis_id = x.hakedis_id AND g.kalem_adi = 'GENEL TOPLAM (KDV HARİÇ)'
);
