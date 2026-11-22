<?php
/**
 * Exportación Excel (CSV UTF-8 BOM, abre en Excel)
 * GET ?action=history&...filtros
 * GET ?action=detail&id=
 */
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
handle_preflight();
$user = require_auth();

function csv_out(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers, ';');
    foreach ($rows as $r) {
        fputcsv($out, $r, ';');
    }
    fclose($out);
    exit;
}

$action = $_GET['action'] ?? 'history';

if ($action === 'history') {
    $sql = 'SELECT a.audit_code, a.warehouse_code, a.warehouse_name, a.counting_doc_entry, a.counting_doc_num,
                   a.verdict, a.sap_status, a.approval_status, a.auditor_name, a.supervisor_name,
                   a.created_at, a.supervised_at,
                   (SELECT COUNT(*) FROM audit_items i WHERE i.audit_id=a.id) AS items,
                   (SELECT COUNT(*) FROM audit_items i WHERE i.audit_id=a.id AND i.status=\'DIFERENCIA\') AS diffs
            FROM audits a WHERE 1=1';
    $params = [];
    if (!empty($_GET['warehouse'])) {
        $sql .= ' AND a.warehouse_code LIKE :w';
        $params[':w'] = '%' . $_GET['warehouse'] . '%';
    }
    if (!empty($_GET['verdict'])) {
        $sql .= ' AND a.verdict = :v';
        $params[':v'] = $_GET['verdict'];
    }
    if (!empty($_GET['from'])) {
        $sql .= ' AND DATE(a.created_at) >= :f';
        $params[':f'] = $_GET['from'];
    }
    if (!empty($_GET['to'])) {
        $sql .= ' AND DATE(a.created_at) <= :t';
        $params[':t'] = $_GET['to'];
    }
    $sql .= ' ORDER BY a.created_at DESC LIMIT 2000';
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            $r['audit_code'], $r['warehouse_code'], $r['warehouse_name'],
            $r['counting_doc_entry'], $r['counting_doc_num'],
            $r['verdict'], $r['sap_status'], $r['approval_status'],
            $r['auditor_name'], $r['supervisor_name'],
            $r['items'], $r['diffs'], $r['created_at'], $r['supervised_at'],
        ];
    }
    csv_out('historial_auditorias.csv', [
        'Código', 'Almacén', 'Nombre almacén', 'DocEntry', 'DocNum',
        'Dictamen', 'SAP', 'Aprobación', 'Auditor', 'Supervisor',
        'Ítems', 'Diferencias', 'Creado', 'Supervisado',
    ], $rows);
}

if ($action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    $a = db()->prepare('SELECT * FROM audits WHERE id = ?');
    $a->execute([$id]);
    $audit = $a->fetch();
    if (!$audit) {
        json_error('No encontrada', 404);
    }
    $items = db()->prepare('SELECT * FROM audit_items WHERE audit_id = ? ORDER BY line_num');
    $items->execute([$id]);
    $rows = [];
    foreach ($items->fetchAll() as $i) {
        $rows[] = [
            $audit['audit_code'], $audit['warehouse_code'], $audit['warehouse_name'],
            $i['item_code'], $i['item_name'], $i['barcode'],
            $i['system_qty'], $i['counted_qty'], $i['difference_qty'],
            $i['unit_cost'] ?? 0, $i['merma_qty'] ?? 0, $i['merma_value'] ?? 0,
            $i['status'], $i['notes'], $audit['verdict'], $audit['auditor_name'], $audit['created_at'],
        ];
    }
    csv_out('auditoria_' . $audit['audit_code'] . '.csv', [
        'Acta', 'Almacén', 'Nombre almacén', 'Código ítem', 'Nombre ítem', 'Barcode',
        'Sistema', 'Contado', 'Diferencia', 'Costo unit.', 'Merma qty', 'Merma valor',
        'Estado', 'Comentario', 'Dictamen', 'Auditor', 'Fecha',
    ], $rows);
}

json_error('Acción no válida', 404);
