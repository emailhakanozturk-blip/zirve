<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/personel-hakedis-bootstrap.php';
$csrf = phCsrfToken();
?>
<!doctype html>
<html lang="tr">
<head>
 <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
 <title>Personel Puantaj ve Hakediş</title>
 <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
 <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
 <link href="assets/personel-hakedis.css?v=<?= (int) filemtime(__DIR__ . '/assets/personel-hakedis.css') ?>" rel="stylesheet">
</head>
<body>
<div class="ph-shell">
 <aside class="ph-sidebar">
  <div class="ph-brand"><span class="ph-logo">Z</span><div><strong>zirve.</strong><small>Yönetim Platformu</small></div></div>
  <nav id="moduleNav" class="nav nav-pills flex-column gap-1">
   <span class="ph-nav-label">ÇALIŞMA ALANI</span>
   <button class="nav-link active" data-section="dashboard"><i class="bi bi-grid"></i><span>Ana Panel</span></button>
   <div class="ph-nav-group is-open" data-module-group>
    <button class="nav-link ph-nav-parent active-parent" type="button" data-open="module_toggle" aria-expanded="true"><i class="bi bi-people"></i><span>Personel Puantaj</span><i class="bi bi-chevron-down ph-nav-arrow"></i></button>
    <div class="ph-nav-children" id="personelModuleMenu">
     <button class="nav-link" data-section="sozlesmeler"><span>Sözleşmeler</span></button>
     <button class="nav-link" data-section="is_tanimlari"><span>İş Tanımları</span></button>
     <button class="nav-link" data-section="personeller"><span>Personeller</span></button>
     <button class="nav-link" data-section="sendikalar"><span>Sendikalar</span></button>
     <button class="nav-link" data-section="tis_donemleri"><span>TİS Dönemleri</span></button>
     <button class="nav-link" data-section="sendikal_haklar"><span>Sendikal Haklar</span></button>
     <button class="nav-link" data-section="personel_haklari"><span>Personel Hakları</span></button>
     <button class="nav-link" data-section="bordrolar"><span>Bordro Yükleme</span></button>
     <button class="nav-link" data-section="puantajlar"><span>Puantaj</span></button>
     <button class="nav-link" data-section="karsilastirmalar"><span>Karşılaştırma</span></button>
     <button class="nav-link" data-section="hakedisler"><span>Hakediş</span></button>
     <button class="nav-link" data-section="raporlar"><span>Raporlar</span></button>
    </div>
   </div>
   <div class="ph-future-module"><i class="bi bi-plus-circle"></i><span>Yeni modüller buraya eklenir</span></div>
  </nav>
 </aside>
 <main class="ph-main">
  <header class="ph-header"><button class="btn d-lg-none" id="menuToggle">☰</button><div><small class="ph-breadcrumb" id="pageModule">Çalışma Alanı</small><h1 id="pageTitle">Ana Panel</h1><p id="pageSubtitle">Personel, puantaj ve hakediş süreçleri</p></div><div class="ms-auto text-end"><span class="badge text-bg-light">PHP + MySQL</span><small class="d-block text-secondary mt-1"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Aktif kullanıcı') ?></small></div></header>
  <div id="alertHost"></div>
  <section id="dashboard" class="module-section active">
   <div id="summaryCards" class="row g-3 mb-4"></div>
   <div class="row g-3"><div class="col-xl-8"><div class="ph-card"><div class="card-body p-4"><h5>Hızlı işlemler</h5><div class="quick-actions"><button data-open="personeller">Personel ekle</button><button data-open="bordrolar">Bordro yükle</button><button data-open="puantajlar">Puantajları incele</button><button data-open="hakedisler">Hakediş oluştur</button></div></div></div></div><div class="col-xl-4"><div class="ph-card h-100"><div class="card-body p-4"><h5>Dönem akışı</h5><ol class="flow-list"><li>Tanımları tamamlayın</li><li>Bordroyu yükleyin</li><li>Puantajı kontrol edip onaylayın</li><li>Tek sözleşme hakedişini üretin</li></ol></div></div></div></div>
  </section>
  <?php foreach (['sozlesmeler'=>'Sözleşmeler','is_tanimlari'=>'İş Tanımları','personeller'=>'Personeller','sendikalar'=>'Sendikalar','tis_donemleri'=>'Toplu İş Sözleşmesi Dönemleri','sendikal_haklar'=>'Sendikal Haklar'] as $key=>$title): ?>
  <section id="<?= $key ?>" class="module-section"><div class="section-toolbar"><div><h2><?= $title ?></h2><p><?= $key === 'sendikal_haklar' ? 'Hakların eski sürümleri korunur; değişiklikler seçtiğiniz tarihten itibaren uygulanır.' : 'Kayıtları arayın, filtreleyin ve güncelleyin.' ?></p></div><div class="d-flex gap-2"><?php if ($key === 'sendikal_haklar'): ?><button class="btn btn-outline-primary" id="importUnionRightsPreset">Listedeki hakları getir</button><?php endif; ?><button class="btn btn-primary add-record" data-entity="<?= $key ?>">+ Yeni kayıt</button></div></div><div class="ph-card"><div class="card-body"><div class="table-tools"><input class="form-control list-search" placeholder="Ara…" data-entity="<?= $key ?>"></div><div class="table-responsive"><table class="table align-middle module-table" data-entity="<?= $key ?>"><thead></thead><tbody></tbody></table></div><div class="pager" data-entity="<?= $key ?>"></div></div></div></section>
  <?php endforeach; ?>
  <section id="personel_haklari" class="module-section"><div class="section-toolbar"><div><h2>Personel Hakları</h2><p>Bordro dosyasından gelen personel–hak kayıtları dönemsel tarihçesiyle saklanır.</p></div></div><div class="ph-card"><div class="card-body"><div class="table-tools"><input class="form-control list-search" placeholder="Personel veya hak ara…" data-entity="personel_haklari"></div><div class="table-responsive"><table class="table align-middle module-table" data-entity="personel_haklari"><thead></thead><tbody></tbody></table></div><div class="pager" data-entity="personel_haklari"></div></div></div></section>
  <section id="bordrolar" class="module-section"><div class="section-toolbar"><div><h2>Bordro Yükleme</h2><p>Excel doğrudan ilişkisel SQL tablolarına işlenir.</p></div></div><div class="row g-3"><div class="col-xl-5"><div class="ph-card"><div class="card-body p-4"><form id="payrollForm" enctype="multipart/form-data"><h5>Yeni bordro</h5><div class="row g-3 mt-1"><div class="col-6"><label>Yıl</label><input name="yil" type="number" class="form-control" value="<?=date('Y')?>" required></div><div class="col-6"><label>Ay</label><input name="ay" type="number" min="1" max="12" class="form-control" value="<?=date('n')?>" required></div><div class="col-12"><label>Sözleşme</label><select name="sozlesme_id" class="form-select opt-contract" required></select></div><div class="col-12"><label>İş</label><select name="is_id" class="form-select opt-job" required></select></div><div class="col-12"><label>TİS dönemi</label><select name="tis_donem_id" class="form-select opt-period" required></select></div><div class="col-12"><label>Yeniden yükleme davranışı</label><select name="mode" class="form-select"><option value="version">Yeni sürüm oluştur</option><option value="update">Aktif sürümü güncelle</option><option value="cancel">Çakışırsa iptal et</option></select></div><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="auto_personnel" value="1" id="autoPersonnel" checked><label class="form-check-label" for="autoPersonnel">Eksik personelleri dosyadan otomatik oluştur</label></div><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="auto_rights" value="1" id="autoRights" checked><label class="form-check-label" for="autoRights">Personel yan haklarını dosyadan otomatik getir</label></div></div><div class="col-12"><label>Excel dosyası</label><input name="excel" type="file" accept=".xlsx,.xls,.csv" class="form-control" required></div></div><button class="btn btn-primary w-100 mt-3">Yükle, personeli ve hakları oluştur</button></form></div></div></div><div class="col-xl-7"><div class="ph-card"><div class="card-body"><div class="table-responsive"><table class="table module-table" data-entity="bordrolar"><thead></thead><tbody></tbody></table></div><div class="pager" data-entity="bordrolar"></div></div></div></div></div></section>
  <section id="puantajlar" class="module-section"><div class="section-toolbar"><div><h2>Puantaj</h2><p>Puantajlar yüklenen bordro bazında oluşur; personel günleri ve bordro kalemleri tek çizelgede gösterilir.</p></div></div><div class="ph-card"><div class="card-body"><div class="filter-row"><input class="form-control list-search" data-entity="puantajlar" placeholder="Bordro ara…"><select class="form-select filter-status" data-entity="puantajlar"><option value="">Tüm durumlar</option><option>taslak</option><option>farkli</option><option>onayli</option></select></div><div class="table-responsive"><table class="table module-table" data-entity="puantajlar"><thead></thead><tbody></tbody></table></div><div class="pager" data-entity="puantajlar"></div></div></div></section>
  <section id="karsilastirmalar" class="module-section"><div class="section-toolbar"><div><h2>Puantaj Karşılaştırma</h2><p>Kurum Excel’i ile sistem puantajını alan bazında kıyaslayın.</p></div></div><div class="row g-3"><div class="col-xl-4"><div class="ph-card"><div class="card-body p-4"><form id="comparisonForm"><div class="row g-3"><div class="col-6"><label>Yıl</label><input name="yil" type="number" value="<?=date('Y')?>" class="form-control" required></div><div class="col-6"><label>Ay</label><input name="ay" type="number" min="1" max="12" value="<?=date('n')?>" class="form-control" required></div><div class="col-12"><label>Sözleşme</label><select name="sozlesme_id" class="form-select opt-contract" required></select></div><div class="col-12"><label>İş</label><select name="is_id" class="form-select opt-job" required></select></div><div class="col-12"><label>Puantaj Excel</label><input name="excel" type="file" accept=".xlsx,.xls,.csv" class="form-control" required></div></div><button class="btn btn-primary w-100 mt-3">Karşılaştır</button></form></div></div></div><div class="col-xl-8"><div class="ph-card"><div class="card-body"><div class="table-responsive"><table class="table module-table" data-entity="karsilastirmalar"><thead></thead><tbody></tbody></table></div><div class="pager" data-entity="karsilastirmalar"></div></div></div></div></div></section>
  <section id="hakedisler" class="module-section"><div class="section-toolbar"><div><h2>Hakediş</h2><p>Sözleşmeye bağlı tüm işler tek hakedişte birleştirilir.</p></div><button class="btn btn-primary" id="generateProgress">+ Hakediş oluştur</button></div><div class="ph-card"><div class="card-body"><div class="filter-row"><input id="progressYear" type="number" value="<?=date('Y')?>" class="form-control"><input id="progressMonth" type="number" min="1" max="12" value="<?=date('n')?>" class="form-control"><select id="progressContract" class="form-select opt-contract"></select></div><div class="table-responsive"><table class="table module-table" data-entity="hakedisler"><thead></thead><tbody></tbody></table></div><div class="pager" data-entity="hakedisler"></div></div></div></section>
  <section id="raporlar" class="module-section">
   <div class="section-toolbar"><div><h2>İş Bazlı Hakediş Raporları</h2><p>Seçilen ayın tutarlarını ve yılbaşından itibaren kümülatif gerçekleşmeyi birlikte izleyin.</p></div></div>
   <div class="ph-card job-report-shell"><div class="card-body p-4">
    <div class="job-report-filters">
     <div class="report-field"><label for="reportYear">Yıl</label><input id="reportYear" type="number" min="2020" max="2100" value="<?=date('Y')?>" class="form-control"></div>
     <div class="report-field"><label for="reportJob">İş</label><select id="reportJob" class="form-select"><option value="">Tüm işler</option></select></div>
     <div class="report-filter-note"><i class="bi bi-info-circle"></i><span>Ay düğmesine basarak mevcut ve kümülatif rakamları karşılaştırın.</span></div>
    </div>
    <div class="report-months" id="reportMonths" aria-label="Rapor ayı">
     <?php foreach ([1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'] as $monthNumber=>$monthName): ?>
      <button type="button" class="report-month<?= $monthNumber === (int)date('n') ? ' active' : '' ?>" data-month="<?= $monthNumber ?>"><span><?= $monthName ?></span><small></small></button>
     <?php endforeach; ?>
    </div>
    <div id="reportPreview" class="mt-4"></div>
    <details class="classic-report-panel mt-4"><summary>Klasik Hakediş Raporu</summary><div class="classic-report-content"><button class="btn btn-outline-primary mb-3" id="previewReport">Seçili Ayın Klasik Raporunu Göster</button><div id="classicReportPreview" class="table-responsive"></div></div></details>
   </div></div>
  </section>
 </main>
</div>
<div class="modal fade" id="recordModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><form id="recordForm"><div class="modal-header"><h5 class="modal-title">Kayıt</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="id"><div id="formFields" class="row g-3"></div></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Vazgeç</button><button class="btn btn-primary">Kaydet</button></div></form></div></div></div>
<script>window.PH_CONFIG={api:'api/personel-hakedis-api.php',csrf:<?=json_encode($csrf)?>,canWrite:<?=json_encode($moduleCanWrite)?>};</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/personel-hakedis.js?v=<?= (int) filemtime(__DIR__ . '/assets/personel-hakedis.js') ?>"></script>
</body></html>
