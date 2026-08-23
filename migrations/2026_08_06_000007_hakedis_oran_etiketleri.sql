UPDATE bordro_maliyet_ozetleri
SET kar_orani = 7.00,
    kar_tutari = ROUND(baz_toplam * 0.07, 2),
    genel_gider_orani = 4.00,
    genel_gider_tutari = ROUND(baz_toplam * 0.04, 2),
    toplam_hakedis_tutari = ROUND(baz_toplam + ROUND(baz_toplam * 0.04, 2) + ROUND(baz_toplam * 0.07, 2), 2);

UPDATE hakedis_genel_kalemleri
SET kalem_adi = 'SÖZLEŞME GENEL GİDERLERİ', oran = 4.00, sira = 5
WHERE kalem_adi = 'KÂR PAYI' AND oran = 4.00;

UPDATE hakedis_genel_kalemleri
SET kalem_adi = 'YÜKLENİCİ KARI', oran = 7.00, sira = 6
WHERE kalem_adi = 'GENEL GİDER' AND oran = 7.00;

UPDATE hakedis_genel_kalemleri
SET kalem_adi = 'GENEL TOPLAM (KDV HARİÇ)', sira = 7
WHERE kalem_adi = 'TOPLAM HAKEDİŞ TUTARI';
