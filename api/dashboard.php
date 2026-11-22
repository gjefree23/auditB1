<?php
/**
 * Dashboard KPIs
 * GET ?action=summary
 */
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
handle_preflight();
require_auth();

$action = $_GET['action'] ?? 'summary';
$pdo = db();

if ($action === 'summary' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $today = date('Y-m-d');

    $todayCount = (int)$pdo->query("SELECT COUNT(*) FROM audits WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM audits')->fetchColumn();
    $approved = (int)$pdo->query("SELECT COUNT(*) FROM audits WHERE verdict = 'APROBADO'")->fetchColumn();
    $pctApproved = $total > 0 ? round(($approved / $total) * 100, 1) : 0;

    $pendingSap = (int)$pdo->query("SELECT COUNT(*) FROM audits WHERE sap_status = 'PENDIENTE_SAP'")->fetchColumn();
    $pendingApproval = (int)$pdo->query("SELECT COUNT(*) FROM audits WHERE approval_status = 'PENDIENTE'")->fetchColumn();
    $withDiff = (int)$pdo->query(
        "SELECT COUNT(DISTINCT a.id) FROM audits a
         INNER JOIN audit_items i ON i.audit_id = a.id AND i.status = 'DIFERENCIA'"
    )->fetchColumn();

    $byVerdict = $pdo->query(
        "SELECT verdict, COUNT(*) AS c FROM audits GROUP BY verdict"
    )->fetchAll();

    $byDay = $pdo->query(
        "SELECT DATE(created_at) AS d, COUNT(*) AS c
         FROM audits
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
         GROUP BY DATE(created_at)
         ORDER BY d ASC"
    )->fetchAll();

    $recent = $pdo->query(
        "SELECT id, audit_code, warehouse_name, verdict, sap_status, approval_status, auditor_name, created_at
         FROM audits ORDER BY created_at DESC LIMIT 8"
    )->fetchAll();

    json_response([
        'ok' => true,
        'data' => [
            'today' => $todayCount,
            'total' => $total,
            'pct_approved' => $pctApproved,
            'pending_sap' => $pendingSap,
            'pending_approval' => $pendingApproval,
            'with_differences' => $withDiff,
            'by_verdict' => $byVerdict,
            'by_day' => $byDay,
            'recent' => $recent,
            'as_of' => date('c'),
        ],
    ]);
}

json_error('Acción no válida', 404);
