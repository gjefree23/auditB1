<?php
/**
 * Reintentos automáticos de envío a SAP
 * Ejecutar por Task Scheduler / cron cada 5–15 min:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\auditB1\api\cron_retry_sap.php
 *
 * También: GET ?key=AuditB1CronKey (opcional vía web)
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sap_service.php';
require_once __DIR__ . '/generate_pdf.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $key = $_GET['key'] ?? '';
    if ($key !== 'AuditB1CronKey') {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

ensure_upload_dirs();
$pdo = db();

if (is_simulation_mode()) {
    $msg = date('c') . " SKIP: modo simulación activo\n";
    if ($isCli) {
        echo $msg;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'message' => 'Simulación activa, sin reintentos']);
    }
    exit;
}

$rows = $pdo->query(
    "SELECT * FROM audits
     WHERE sap_status = 'PENDIENTE_SAP'
       AND approval_status = 'APROBADA'
       AND counting_doc_entry > 0
     ORDER BY id ASC
     LIMIT 10"
)->fetchAll();

$results = [];
foreach ($rows as $audit) {
    $id = (int)$audit['id'];
    try {
        $pdfAbs = APP_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$audit['pdf_path']);
        if (empty($audit['pdf_path']) || !is_file($pdfAbs)) {
            $pdfAbs = generate_audit_pdf($id);
        }
        $sap = sap_send_audit_result($audit, $pdfAbs);
        $status = sap_persist_send_result($pdo, $id, $sap);
        $results[] = [
            'id' => $id,
            'ok' => !empty($sap['ok']),
            'sap_status' => $status,
            'error' => $sap['ok'] ? null : ($sap['error'] ?? 'fail'),
            'AbsoluteEntry' => $sap['AbsoluteEntry'] ?? null,
            'code' => $audit['audit_code'],
        ];
    } catch (Throwable $e) {
        $results[] = ['id' => $id, 'ok' => false, 'error' => $e->getMessage(), 'code' => $audit['audit_code']];
    }
}

$out = ['ok' => true, 'processed' => count($results), 'results' => $results, 'at' => date('c')];
if ($isCli) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
}
