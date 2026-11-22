<?php
/**
 * Auditorías: listado, detalle, creación (validar y enviar), reintento SAP
 *
 * GET  ?action=list&warehouse=&verdict=&sap_status=&from=&to=
 * GET  ?action=get&id=
 * POST ?action=submit   (multipart o JSON con base64)
 * POST ?action=retry_sap  {id}
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sap_service.php';
require_once __DIR__ . '/generate_pdf.php';

handle_preflight();
ensure_upload_dirs();

$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];

function save_data_url_image(string $dataUrl, string $dir, string $prefix): string
{
    if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/i', $dataUrl, $m)) {
        throw new InvalidArgumentException('Imagen en formato data URL inválido');
    }
    $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
    $bin = base64_decode($m[2], true);
    if ($bin === false) {
        throw new InvalidArgumentException('No se pudo decodificar la imagen');
    }
    $name = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $abs  = $dir . DIRECTORY_SEPARATOR . $name;
    if (file_put_contents($abs, $bin) === false) {
        throw new RuntimeException('No se pudo guardar la imagen');
    }
    $folder = (strpos($dir, 'actas') !== false) ? 'actas' : 'fotos';
    return 'uploads/' . $folder . '/' . $name;
}

if ($action === 'list' && $method === 'GET') {
    require_auth();
    $sql = 'SELECT a.id, a.audit_code, a.warehouse_code, a.warehouse_name, a.counting_doc_entry, a.counting_doc_num,
                   a.counting_date, a.verdict, a.sap_status, a.auditor_name, a.created_at, a.pdf_path,
                   (SELECT COUNT(*) FROM audit_items ai WHERE ai.audit_id = a.id) AS item_count,
                   (SELECT COUNT(*) FROM audit_items ai WHERE ai.audit_id = a.id AND ai.status = \'DIFERENCIA\') AS diff_count
            FROM audits a WHERE 1=1';
    $params = [];

    if (!empty($_GET['warehouse'])) {
        $sql .= ' AND (a.warehouse_code LIKE :whs OR a.warehouse_name LIKE :whs2)';
        $params[':whs'] = '%' . $_GET['warehouse'] . '%';
        $params[':whs2'] = '%' . $_GET['warehouse'] . '%';
    }
    if (!empty($_GET['verdict'])) {
        $sql .= ' AND a.verdict = :v';
        $params[':v'] = $_GET['verdict'];
    }
    if (!empty($_GET['sap_status'])) {
        $sql .= ' AND a.sap_status = :s';
        $params[':s'] = $_GET['sap_status'];
    }
    if (!empty($_GET['from'])) {
        $sql .= ' AND DATE(a.created_at) >= :from';
        $params[':from'] = $_GET['from'];
    }
    if (!empty($_GET['to'])) {
        $sql .= ' AND DATE(a.created_at) <= :to';
        $params[':to'] = $_GET['to'];
    }
    if (!empty($_GET['q'])) {
        $sql .= ' AND (a.audit_code LIKE :q OR a.auditor_name LIKE :q2 OR a.warehouse_name LIKE :q3)';
        $like = '%' . $_GET['q'] . '%';
        $params[':q'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
    }

    $sql .= ' ORDER BY a.created_at DESC LIMIT 500';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['counting_doc_entry'] = (int)$r['counting_doc_entry'];
        $r['counting_doc_num'] = (int)$r['counting_doc_num'];
        $r['item_count'] = (int)$r['item_count'];
        $r['diff_count'] = (int)$r['diff_count'];
    }
    json_response(['ok' => true, 'data' => $rows]);
}

if ($action === 'get' && $method === 'GET') {
    require_auth();
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM audits WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $audit = $stmt->fetch();
    if (!$audit) {
        json_error('Auditoría no encontrada', 404);
    }
    $items = db()->prepare('SELECT * FROM audit_items WHERE audit_id = :id ORDER BY line_num');
    $items->execute([':id' => $id]);
    $audit['items'] = $items->fetchAll();
    $chk = db()->prepare('SELECT * FROM audit_checklist WHERE audit_id = :id ORDER BY id');
    $chk->execute([':id' => $id]);
    $audit['checklist'] = $chk->fetchAll();
    $audit['id'] = (int)$audit['id'];
    json_response(['ok' => true, 'data' => $audit]);
}

if ($action === 'submit' && $method === 'POST') {
    $user = require_auth(['ADMIN', 'AUDITOR', 'SUPERVISOR']);
    $b = read_json_body();

    $warehouseCode = trim((string)($b['warehouse_code'] ?? ''));
    $warehouseName = trim((string)($b['warehouse_name'] ?? ''));
    // Evitar nombres con '?' por mala codificación en el cliente
    if ($warehouseName === '' || str_contains($warehouseName, '?')) {
        $known = [
            '01' => 'Almacén General',
            '02' => 'Almacén Materias Primas',
            '03' => 'Almacén Producto Terminado',
        ];
        if (isset($known[$warehouseCode])) {
            $warehouseName = $known[$warehouseCode];
        } elseif ($warehouseName === '') {
            $warehouseName = $warehouseCode;
        }
    }
    $docEntry      = (int)($b['counting_doc_entry'] ?? 0);
    $docNum        = (int)($b['counting_doc_num'] ?? 0);
    $countingDate  = trim((string)($b['counting_date'] ?? ''));
    $verdict       = strtoupper(trim((string)($b['verdict'] ?? '')));
    $note          = trim((string)($b['observation_note'] ?? ''));
    $items         = $b['items'] ?? [];
    $checklist     = $b['checklist'] ?? [];
    $photo         = (string)($b['photo_data'] ?? '');
    $photoAnn      = (string)($b['photo_annotated_data'] ?? '');
    $signature     = (string)($b['signature_data'] ?? '');

    if ($warehouseCode === '') {
        json_error('El almacén es obligatorio');
    }
    if (!in_array($verdict, ['APROBADO', 'CON_OBSERVACION', 'RECHAZADO'], true)) {
        json_error('Dictamen inválido');
    }
    if (!is_array($items) || count($items) === 0) {
        json_error('Debe incluir al menos un ítem validado');
    }
    if ($signature === '') {
        json_error('La firma es obligatoria');
    }
    // Checklist requerido: preguntas marcadas required deben responderse SI/NO
    if (is_array($checklist)) {
        foreach ($checklist as $cItem) {
            if (!empty($cItem['required']) && empty($cItem['answer'])) {
                json_error('Complete el checklist de bodega antes de firmar');
            }
            if (!empty($cItem['required']) && ($cItem['answer'] ?? '') === 'NA') {
                json_error('Hay preguntas obligatorias del checklist sin responder (SI/NO)');
            }
        }
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $auditCode = generate_audit_code($pdo);

        $photoPath = null;
        $photoAnnPath = null;
        $sigPath = null;

        if ($photo !== '') {
            $photoPath = save_data_url_image($photo, UPLOAD_FOTOS, 'foto_' . $auditCode);
        }
        if ($photoAnn !== '') {
            $photoAnnPath = save_data_url_image($photoAnn, UPLOAD_FOTOS, 'anot_' . $auditCode);
        }
        $sigPath = save_data_url_image($signature, UPLOAD_FOTOS, 'firma_' . $auditCode);

        // Auditor → pendiente de supervisor; ADMIN/SUPERVISOR auto-aprueban
        $needsApproval = ($user['role'] === 'AUDITOR');
        $approvalStatus = $needsApproval ? 'PENDIENTE' : 'APROBADA';

        if ($docEntry <= 0) {
            $sapStatus = is_simulation_mode() ? 'SIMULADO' : 'NO_APLICA';
        } else {
            $sapStatus = is_simulation_mode() ? 'SIMULADO' : 'PENDIENTE_SAP';
        }

        $ins = $pdo->prepare(
            'INSERT INTO audits (
                audit_code, warehouse_code, warehouse_name, counting_doc_entry, counting_doc_num,
                counting_date, verdict, observation_note, photo_path, photo_annotated_path,
                signature_path, sap_status, approval_status, auditor_id, auditor_name,
                supervisor_id, supervisor_name, supervised_at
             ) VALUES (
                :code, :whs, :whn, :de, :dn, :cd, :v, :note, :ph, :pha, :sig, :ss, :ap,
                :aid, :an, :sid, :sn, :sat
             )'
        );
        $ins->execute([
            ':code' => $auditCode,
            ':whs'  => $warehouseCode,
            ':whn'  => $warehouseName,
            ':de'   => $docEntry,
            ':dn'   => $docNum,
            ':cd'   => $countingDate !== '' ? $countingDate : null,
            ':v'    => $verdict,
            ':note' => $note !== '' ? $note : null,
            ':ph'   => $photoPath,
            ':pha'  => $photoAnnPath,
            ':sig'  => $sigPath,
            ':ss'   => $sapStatus,
            ':ap'   => $approvalStatus,
            ':aid'  => $user['id'],
            ':an'   => $user['full_name'],
            ':sid'  => $needsApproval ? null : $user['id'],
            ':sn'   => $needsApproval ? null : $user['full_name'],
            ':sat'  => $needsApproval ? null : date('Y-m-d H:i:s'),
        ]);
        $auditId = (int)$pdo->lastInsertId();

        if (is_array($checklist) && $checklist) {
            $insChk = $pdo->prepare(
                'INSERT INTO audit_checklist (audit_id, template_item_id, question, answer, comment)
                 VALUES (:a,:t,:q,:ans,:c)'
            );
            foreach ($checklist as $cItem) {
                $ans = strtoupper((string)($cItem['answer'] ?? 'NA'));
                if (!in_array($ans, ['SI', 'NO', 'NA'], true)) {
                    $ans = 'NA';
                }
                $insChk->execute([
                    ':a' => $auditId,
                    ':t' => !empty($cItem['id']) ? (int)$cItem['id'] : null,
                    ':q' => (string)($cItem['question'] ?? ''),
                    ':ans' => $ans,
                    ':c' => isset($cItem['comment']) ? (string)$cItem['comment'] : null,
                ]);
            }
        }

        $insItem = $pdo->prepare(
            'INSERT INTO audit_items (
                audit_id, line_num, item_code, item_name, barcode,
                system_qty, counted_qty, difference_qty, unit_cost, merma_qty, merma_value,
                status, notes, photo_path
             ) VALUES (
                :aid, :ln, :ic, :in, :bc, :sq, :cq, :dq, :uc, :mq, :mv, :st, :nt, :ph
             )'
        );

        foreach ($items as $idx => $it) {
            $sys = (float)($it['system_qty'] ?? 0);
            $cnt = (float)($it['counted_qty'] ?? 0);
            $dif = isset($it['difference_qty']) ? (float)$it['difference_qty'] : ($cnt - $sys);
            $unitCost = (float)($it['unit_cost'] ?? 0);
            // Merma = faltante (sistema > contado)
            $mermaQty = $dif < 0 ? abs($dif) : 0.0;
            $mermaVal = round($mermaQty * $unitCost, 4);
            $st  = strtoupper((string)($it['status'] ?? (abs($dif) < 0.0001 ? 'OK' : 'DIFERENCIA')));
            if (!in_array($st, ['OK', 'DIFERENCIA'], true)) {
                $st = abs($dif) < 0.0001 ? 'OK' : 'DIFERENCIA';
            }
            $itemPhoto = null;
            $photoData = (string)($it['photo_data'] ?? '');
            if ($photoData !== '') {
                $itemPhoto = save_data_url_image(
                    $photoData,
                    UPLOAD_FOTOS,
                    'item_' . $auditCode . '_' . $idx
                );
            }
            $notes = trim((string)($it['notes'] ?? ''));
            $barcode = trim((string)($it['barcode'] ?? ''));
            $insItem->execute([
                ':aid' => $auditId,
                ':ln'  => (int)($it['line_num'] ?? $idx),
                ':ic'  => (string)($it['item_code'] ?? ''),
                ':in'  => (string)($it['item_name'] ?? ''),
                ':bc'  => $barcode !== '' ? $barcode : null,
                ':sq'  => $sys,
                ':cq'  => $cnt,
                ':dq'  => $dif,
                ':uc'  => $unitCost,
                ':mq'  => $mermaQty,
                ':mv'  => $mermaVal,
                ':st'  => $st,
                ':nt'  => $notes !== '' ? $notes : null,
                ':ph'  => $itemPhoto,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('Error al guardar auditoría: ' . $e->getMessage(), 500);
    }

    // PDF
    try {
        $pdfAbs = generate_audit_pdf($auditId);
    } catch (Throwable $e) {
        json_response([
            'ok' => true,
            'warning' => 'Auditoría guardada pero falló el PDF: ' . $e->getMessage(),
            'id' => $auditId,
            'audit_code' => $auditCode,
            'sap_status' => $sapStatus,
        ], 201);
    }

    // SAP solo si ya está aprobada (ADMIN/SUPERVISOR) y hay documento
    $sapResult = null;
    if ($approvalStatus === 'APROBADA' && !is_simulation_mode() && $docEntry > 0) {
        $stmt = $pdo->prepare('SELECT * FROM audits WHERE id = :id');
        $stmt->execute([':id' => $auditId]);
        $auditRow = $stmt->fetch();
        $sapResult = sap_send_audit_result($auditRow, $pdfAbs);
        $sapStatus = sap_persist_send_result($pdo, $auditId, $sapResult);
    } else {
        $pdo->prepare(
            'UPDATE audits SET sap_response = :r WHERE id = :id'
        )->execute([
            ':r'  => json_encode([
                'ok' => true,
                'awaiting_approval' => $approvalStatus === 'PENDIENTE',
                'simulated' => is_simulation_mode(),
                'skipped_sap_doc' => $docEntry <= 0,
            ], JSON_UNESCAPED_UNICODE),
            ':id' => $auditId,
        ]);
    }

    $pdfRel = $pdo->query('SELECT pdf_path FROM audits WHERE id = ' . (int)$auditId)->fetchColumn();

    $sapWarning = null;
    if (is_array($sapResult) && empty($sapResult['ok']) && empty($sapResult['skipped']) && empty($sapResult['simulated'])) {
        $sapWarning = $sapResult['error'] ?? 'Envío a SAP incompleto (revise PDF vinculado)';
    }
    json_response([
        'ok' => true,
        'id' => $auditId,
        'audit_code' => $auditCode,
        'sap_status' => $sapStatus,
        'approval_status' => $approvalStatus,
        'pdf_path' => $pdfRel,
        'sap_result' => $sapResult,
        'warning' => $sapWarning,
        'message' => $approvalStatus === 'PENDIENTE'
            ? 'Auditoría enviada a supervisión. Un supervisor debe aprobar el acta.'
            : ($sapWarning
                ? ('Acta guardada. Atención SAP: ' . $sapWarning)
                : 'Auditoría validada y guardada correctamente'),
    ], 201);
}

if ($action === 'pending_approval' && $method === 'GET') {
    require_auth(['ADMIN', 'SUPERVISOR']);
    $rows = db()->query(
        "SELECT id, audit_code, warehouse_code, warehouse_name, verdict, auditor_name, created_at, sap_status, approval_status
         FROM audits WHERE approval_status = 'PENDIENTE' ORDER BY created_at ASC LIMIT 200"
    )->fetchAll();
    json_response(['ok' => true, 'data' => $rows]);
}

if ($action === 'approve' && $method === 'POST') {
    $sup = require_auth(['ADMIN', 'SUPERVISOR']);
    $b = read_json_body();
    $id = (int)($b['id'] ?? 0);
    $note = trim((string)($b['note'] ?? ''));
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM audits WHERE id = ?');
    $st->execute([$id]);
    $audit = $st->fetch();
    if (!$audit) {
        json_error('Auditoría no encontrada', 404);
    }
    if ($audit['approval_status'] !== 'PENDIENTE') {
        json_error('Esta auditoría ya fue supervisada');
    }
    $pdo->prepare(
        'UPDATE audits SET approval_status=?, supervisor_id=?, supervisor_name=?, supervisor_note=?, supervised_at=NOW() WHERE id=?'
    )->execute(['APROBADA', $sup['id'], $sup['full_name'], $note !== '' ? $note : null, $id]);

    // Tras aprobar: intentar SAP
    $st->execute([$id]);
    $audit = $st->fetch();
    $sapResult = null;
    $sapStatus = $audit['sap_status'];
    if (!is_simulation_mode() && (int)$audit['counting_doc_entry'] > 0) {
        ensure_upload_dirs();
        $pdfAbs = APP_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$audit['pdf_path']);
        if (empty($audit['pdf_path']) || !is_file($pdfAbs)) {
            $pdfAbs = generate_audit_pdf($id);
        }
        $sapResult = sap_send_audit_result($audit, $pdfAbs);
        $sapStatus = sap_persist_send_result($pdo, $id, $sapResult);
    }
    json_response([
        'ok' => true,
        'approval_status' => 'APROBADA',
        'sap_status' => $sapStatus,
        'sap_result' => $sapResult,
        'warning' => (!empty($sapResult) && empty($sapResult['ok']))
            ? ($sapResult['error'] ?? 'Envío a SAP incompleto')
            : null,
    ]);
}

if ($action === 'reject' && $method === 'POST') {
    $sup = require_auth(['ADMIN', 'SUPERVISOR']);
    $b = read_json_body();
    $id = (int)($b['id'] ?? 0);
    $note = trim((string)($b['note'] ?? ''));
    if ($note === '') {
        json_error('Indique el motivo del rechazo');
    }
    $st = db()->prepare('SELECT id, approval_status FROM audits WHERE id = ?');
    $st->execute([$id]);
    $audit = $st->fetch();
    if (!$audit) {
        json_error('Auditoría no encontrada', 404);
    }
    if ($audit['approval_status'] !== 'PENDIENTE') {
        json_error('Esta auditoría ya fue supervisada');
    }
    db()->prepare(
        'UPDATE audits SET approval_status=?, supervisor_id=?, supervisor_name=?, supervisor_note=?, supervised_at=NOW() WHERE id=?'
    )->execute(['RECHAZADA', $sup['id'], $sup['full_name'], $note, $id]);
    json_response(['ok' => true, 'approval_status' => 'RECHAZADA']);
}

if ($action === 'item_trend' && $method === 'GET') {
    require_auth();
    $item = trim((string)($_GET['item_code'] ?? ''));
    $whs = trim((string)($_GET['warehouse'] ?? ''));
    if ($item === '') {
        json_error('item_code es obligatorio');
    }
    $sql = "SELECT a.audit_code, a.warehouse_code, a.created_at, a.verdict, a.auditor_name,
                   i.item_code, i.item_name, i.system_qty, i.counted_qty, i.difference_qty, i.status
            FROM audit_items i
            INNER JOIN audits a ON a.id = i.audit_id
            WHERE i.item_code = :ic AND a.approval_status <> 'RECHAZADA'";
    $params = [':ic' => $item];
    if ($whs !== '') {
        $sql .= ' AND a.warehouse_code = :w';
        $params[':w'] = $whs;
    }
    $sql .= ' ORDER BY a.created_at DESC LIMIT 30';
    $st = db()->prepare($sql);
    $st->execute($params);
    json_response(['ok' => true, 'data' => $st->fetchAll()]);
}

if ($action === 'retry_sap' && $method === 'POST') {
    $user = require_auth(['ADMIN', 'AUDITOR']);
    $b = read_json_body();
    $id = (int)($b['id'] ?? 0);

    $stmt = db()->prepare('SELECT * FROM audits WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $audit = $stmt->fetch();
    if (!$audit) {
        json_error('Auditoría no encontrada', 404);
    }
    if (is_simulation_mode()) {
        json_error('Modo simulación activo: no se puede enviar a SAP');
    }
    if (empty($audit['pdf_path'])) {
        try {
            $pdfAbs = generate_audit_pdf($id);
        } catch (Throwable $e) {
            json_error('No hay PDF y no se pudo regenerar: ' . $e->getMessage());
        }
    } else {
        $pdfAbs = APP_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $audit['pdf_path']);
        if (!is_file($pdfAbs)) {
            $pdfAbs = generate_audit_pdf($id);
        }
    }

    $sapResult = sap_send_audit_result($audit, $pdfAbs);
    $sapStatus = sap_persist_send_result(db(), $id, $sapResult);
    if (!empty($sapResult['ok'])) {
        json_response([
            'ok' => true,
            'sap_status' => $sapStatus,
            'sap_result' => $sapResult,
            'message' => $sapResult['message'] ?? 'Enviado a SAP',
        ]);
    }
    json_response([
        'ok' => false,
        'error' => $sapResult['error'] ?? 'Fallo al enviar a SAP',
        'sap_status' => $sapStatus,
        'sap_result' => $sapResult,
        'warning' => $sapResult['error'] ?? 'Envío incompleto (revise PDF vinculado)',
        'AbsoluteEntry' => $sapResult['AbsoluteEntry'] ?? null,
        'lines_ok' => $sapResult['lines_ok'] ?? false,
        'attachment_linked' => $sapResult['attachment_linked'] ?? false,
    ], 400);
}

json_error('Acción no válida', 404);
