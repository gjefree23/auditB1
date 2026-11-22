<?php
/**
 * Configuración SAP B1 Service Layer
 * GET  ?action=get
 * POST ?action=save
 * POST ?action=test
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sap_service.php';

handle_preflight();

$action = $_GET['action'] ?? 'get';
$method = $_SERVER['REQUEST_METHOD'];

function get_sap_config_row(): ?array
{
    $row = db()->query('SELECT * FROM sap_config ORDER BY id ASC LIMIT 1')->fetch();
    return $row ?: null;
}

if ($action === 'get' && $method === 'GET') {
    require_admin();
    $row = get_sap_config_row();
    if (!$row) {
        json_response([
            'ok' => true,
            'data' => [
                'sl_url' => '',
                'company_db' => '',
                'sl_user' => '',
                'has_password' => false,
                'simulation_mode' => 1,
            ],
        ]);
    }
    json_response([
        'ok' => true,
        'data' => [
            'id' => (int)$row['id'],
            'sl_url' => $row['sl_url'],
            'company_db' => $row['company_db'],
            'sl_user' => $row['sl_user'],
            'has_password' => $row['sl_password'] !== '',
            'simulation_mode' => (int)$row['simulation_mode'],
            'updated_at' => $row['updated_at'],
        ],
    ]);
}

if ($action === 'save' && $method === 'POST') {
    $user = require_admin();
    $b = read_json_body();

    $slUrl    = sap_normalize_base_url((string)($b['sl_url'] ?? ''));
    $company  = trim((string)($b['company_db'] ?? ''));
    $slUser   = trim((string)($b['sl_user'] ?? ''));
    $slPass   = (string)($b['sl_password'] ?? '');
    $simMode  = isset($b['simulation_mode']) ? (int)(bool)$b['simulation_mode'] : 1;

    if ($slUrl !== '' && sap_validate_url($slUrl) !== null) {
        json_error('URL del Service Layer inválida. Ejemplo: https://servidor:50000/b1s/v1');
    }

    $row = get_sap_config_row();

    if ($row) {
        if ($slPass !== '') {
            $enc = encrypt_secret($slPass);
            $sql = 'UPDATE sap_config SET sl_url=:u, company_db=:c, sl_user=:su, sl_password=:p,
                    simulation_mode=:m, updated_by=:ub WHERE id=:id';
            $params = [
                ':u' => $slUrl, ':c' => $company, ':su' => $slUser, ':p' => $enc,
                ':m' => $simMode, ':ub' => $user['id'], ':id' => $row['id'],
            ];
        } else {
            $sql = 'UPDATE sap_config SET sl_url=:u, company_db=:c, sl_user=:su,
                    simulation_mode=:m, updated_by=:ub WHERE id=:id';
            $params = [
                ':u' => $slUrl, ':c' => $company, ':su' => $slUser,
                ':m' => $simMode, ':ub' => $user['id'], ':id' => $row['id'],
            ];
        }
        db()->prepare($sql)->execute($params);
    } else {
        $enc = $slPass !== '' ? encrypt_secret($slPass) : '';
        db()->prepare(
            'INSERT INTO sap_config (sl_url, company_db, sl_user, sl_password, simulation_mode, updated_by)
             VALUES (:u,:c,:su,:p,:m,:ub)'
        )->execute([
            ':u' => $slUrl, ':c' => $company, ':su' => $slUser, ':p' => $enc,
            ':m' => $simMode, ':ub' => $user['id'],
        ]);
    }

    json_response(['ok' => true, 'message' => 'Configuración guardada']);
}

if ($action === 'test' && $method === 'POST') {
    require_admin();
    $b = read_json_body();

    // Permite probar con valores del formulario sin guardar aún
    $cfg = get_sap_config_row() ?: [];
    $slUrl   = sap_normalize_base_url((string)($b['sl_url'] ?? $cfg['sl_url'] ?? ''));
    $company = trim((string)($b['company_db'] ?? $cfg['company_db'] ?? ''));
    $slUser  = trim((string)($b['sl_user'] ?? $cfg['sl_user'] ?? ''));
    $slPass  = (string)($b['sl_password'] ?? '');

    if ($slPass === '' && !empty($cfg['sl_password'])) {
        $slPass = decrypt_secret($cfg['sl_password']);
    }

    if ($slUrl === '' || $company === '' || $slUser === '' || $slPass === '') {
        json_error('Complete URL, Company DB, usuario y contraseña para probar');
    }

    $invalid = sap_validate_url($slUrl);
    if ($invalid !== null) {
        json_error($invalid);
    }

    $result = sap_login($slUrl, $company, $slUser, $slPass);
    if (!$result['ok']) {
        json_response([
            'ok' => false,
            'error' => $result['error'] ?? 'Fallo de conexión',
            'http_code' => $result['http_code'] ?? 0,
            'raw' => $result['raw'] ?? null,
        ], 400);
    }

    // Logout limpio
    if (!empty($result['session_id']) && !empty($result['route_id'])) {
        sap_logout($slUrl, $result['session_id'], $result['route_id']);
    }

    json_response([
        'ok' => true,
        'message' => 'Conexión exitosa con SAP Business One Service Layer',
        'session_id' => substr((string)$result['session_id'], 0, 8) . '…',
    ]);
}

json_error('Acción no válida', 404);
