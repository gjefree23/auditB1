<?php
/**
 * Configuración global del Portal de Auditoría SAP B1
 */

declare(strict_types=1);

// Evitar acceso directo fuera de API
header('X-Content-Type-Options: nosniff');

define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_FOTOS', APP_ROOT . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'fotos');
define('UPLOAD_ACTAS', APP_ROOT . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'actas');
define('SESSION_HOURS', 12);
define('APP_NAME', 'Audit B1 — Auditoría Empresarial');

// Clave para ofuscar contraseña SAP (cambiar en producción)
define('APP_SECRET', 'AuditB1_ChangeMe_In_Production_2026!');

// Base de datos MySQL (XAMPP por defecto)
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'audit_b1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// CORS / JSON helpers
function json_response(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400, array $extra = []): void
{
    json_response(array_merge(['ok' => false, 'error' => $message], $extra), $status);
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('JSON inválido en el cuerpo de la petición', 400);
    }
    return $data;
}

function handle_preflight(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        http_response_code(204);
        exit;
    }
}

function encrypt_secret(string $plain): string
{
    $key = hash('sha256', APP_SECRET, true);
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

function decrypt_secret(string $encoded): string
{
    if ($encoded === '') {
        return '';
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 17) {
        return '';
    }
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    $key = hash('sha256', APP_SECRET, true);
    $dec = openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $dec === false ? '' : $dec;
}

function ensure_upload_dirs(): void
{
    foreach ([UPLOAD_FOTOS, UPLOAD_ACTAS] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}

function generate_audit_code(PDO $pdo): string
{
    $year = date('Y');
    $prefix = 'AUD-' . $year . '-';
    $stmt = $pdo->prepare(
        "SELECT audit_code FROM audits WHERE audit_code LIKE :p ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([':p' => $prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last && preg_match('/(\d+)$/', (string)$last, $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}
