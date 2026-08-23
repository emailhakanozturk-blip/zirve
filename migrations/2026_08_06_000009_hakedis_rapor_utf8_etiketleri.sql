UPDATE hakedis_genel_kalemleri
SET kalem_adi = 'SÖZLEŞME GENEL GİDERLERİ'
WHERE sira = 5 AND oran = 4.00;

UPDATE hakedis_genel_kalemleri
SET kalem_adi = 'YÜKLENİCİ KARI'
WHERE sira = 6 AND oran = 7.00;

UPDATE hakedis_genel_kalemleri
SET kalem_adi = 'GENEL TOPLAM (KDV HARİÇ)'
WHERE sira = 7 AND oran IS NULL;
