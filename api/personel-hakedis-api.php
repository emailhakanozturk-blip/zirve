<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/personel-hakedis-bootstrap.php';

use PersonelHakedis\ReportService;
use PersonelHakedis\SpreadsheetService;

try {
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'dashboard');
    if ($action === 'export_payroll_movements') {
        $report = new ReportService($moduleService->db());
        $path = $report->payrollMovementsExcel((int)($_GET['id'] ?? 0));
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="bordro-hareketleri.xlsx"');
        readfile($path); @unlink($path); exit;
    }
    if ($action === 'export_payroll_timesheet') {
        $report = new ReportService($moduleService->db());
        $path = $report->payrollTimesheetExcel((int)($_GET['id'] ?? 0));
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="bordro-puantaji.xlsx"');
        readfile($path); @unlink($path); exit;
    }
    if ($action === 'export') {
        $report = new ReportService($moduleService->db());
        $format = ($_GET['format'] ?? 'excel') === 'pdf' ? 'pdf' : 'excel';
        $path = $format === 'pdf' ? $report->pdf((string)($_GET['type'] ?? 'hakedis'), $_GET) : $report->excel((string)($_GET['type'] ?? 'hakedis'), $_GET);
        header('Content-Type: ' . ($format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        header('Content-Disposition: attachment; filename="personel-raporu.' . ($format === 'pdf' ? 'pdf' : 'xlsx') . '"');
        readfile($path); @unlink($path); exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
    if(isset($_FILES['excel']['name']))$payload['dosya_adi']=(string)$_FILES['excel']['name'];
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        phAssertCsrf();
        if (!$moduleCanWrite) throw new RuntimeException('Bu işlem için yazma yetkiniz yok.');
    }
    $result = match ($action) {
        'dashboard' => $moduleService->dashboard(),
        'options' => $moduleService->options(),
        'list' => $moduleService->list((string)($_GET['entity'] ?? ''), $_GET, (int)($_GET['page'] ?? 1), (int)($_GET['size'] ?? 25)),
        'save' => ['id' => $moduleService->save((string)($payload['entity'] ?? ''), (array)($payload['data'] ?? []))],
        'save_personnel' => ['id' => $moduleService->savePersonnel((array)($payload['data'] ?? []))],
        'seed_union_rights' => ['count' => $moduleService->seedUnionRights((int)($payload['period_id'] ?? 0),(string)($payload['effective_date'] ?? ''))],
        'copy_rights' => ['count' => $moduleService->copyRights((int)$payload['source_id'], (int)$payload['target_id'])],
        'apply_rights' => ['count' => $moduleService->applyRights((int)$payload['period_id'])],
        'update_timesheet' => (function() use ($moduleService,$payload) {$moduleService->updateTimesheet((int)$payload['id'],(array)$payload['values']); return ['updated'=>true];})(),
        'delete_timesheet' => (function()use($moduleService,$payload){$moduleService->deleteTimesheet((int)($payload['id']??0));return['deleted'=>true];})(),
        'timesheet_detail' => ['rows'=>$moduleService->timesheetDetails((int)($_GET['id']??0))],
        'payroll_timesheet' => $moduleService->payrollTimesheet((int)($_GET['id']??0)),
        'delete_payroll_timesheets' => (function()use($moduleService,$payload){$moduleService->deletePayrollTimesheets((int)($payload['id']??0));return['deleted'=>true];})(),
        'progress_detail' => array_merge(['rows'=>$moduleService->progressDetails((int)($_GET['id']??0))],$moduleService->progressFinancial((int)($_GET['id']??0))),
        'payroll_movements' => $moduleService->payrollMovements((int)($_GET['id']??0)),
        'progress_edit_data' => $moduleService->progressEditData((int)($_GET['id']??0)),
        'update_progress' => (function()use($moduleService,$payload){$moduleService->updateProgress((int)($payload['id']??0),(array)($payload['data']??[]));return['updated'=>true];})(),
        'delete_progress' => (function()use($moduleService,$payload){$moduleService->deleteProgress((int)($payload['id']??0));return['deleted'=>true];})(),
        'delete_payroll' => (function()use($moduleService,$payload){$moduleService->deletePayroll((int)($payload['id']??0));return['deleted'=>true];})(),
        'simple_progress_report' => $moduleService->simpleProgressReport((int)($_GET['yil']??date('Y')),(int)($_GET['ay']??date('n'))),
        'job_progress_report' => (new ReportService($moduleService->db()))->jobMonthlySummary((int)($_GET['yil']??date('Y')),(int)($_GET['ay']??date('n')),(int)($_GET['is_id']??0)),
        'generate_progress' => ['id' => $moduleService->generateProgress((int)$payload['yil'],(int)$payload['ay'],(int)$payload['sozlesme_id'])],
        'generate_progress_from_payroll' => ['id' => $moduleService->generateProgressFromPayroll((int)($payload['id']??0))],
        'upload_payroll' => (new SpreadsheetService($moduleService->db(),$currentUserId))->importPayroll(uploadedFile(),$payload,(string)($payload['mode']??'version')),
        'upload_comparison' => (new SpreadsheetService($moduleService->db(),$currentUserId))->importComparison(uploadedFile(),$payload),
        'resolve_comparison' => (function()use($moduleService,$currentUserId,$payload){(new SpreadsheetService($moduleService->db(),$currentUserId))->resolveComparison((int)$payload['id'],(string)$payload['choice'],isset($payload['manual'])?(float)$payload['manual']:null);return['updated'=>true];})(),
        'report' => ['rows'=>(new ReportService($moduleService->db()))->rows((string)($_GET['type']??'hakedis'),$_GET)],
        default => throw new RuntimeException('Bilinmeyen işlem.'),
    };
    echo json_encode(['ok'=>true,'data'=>$result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($e instanceof InvalidArgumentException ? 422 : 400);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function uploadedFile(): string
{
    global $config;
    if (!isset($_FILES['excel']) || $_FILES['excel']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Excel dosyası yüklenemedi.');
    if ((int)$_FILES['excel']['size'] > (int)$config['max_upload_bytes']) throw new RuntimeException('Excel dosyası izin verilen boyutu aşıyor.');
    $ext = strtolower(pathinfo($_FILES['excel']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext,['xlsx','xls','csv'],true)) throw new RuntimeException('Yalnızca XLSX, XLS veya CSV yükleyebilirsiniz.');
    return $_FILES['excel']['tmp_name'];
}
