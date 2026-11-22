<?php
/**
 * Autenticación: login, logout, me, require_auth
 * Endpoints:
 *   POST ?action=login   {username, password}
 *   POST ?action=logout
 *   GET  ?action=me
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

handle_preflight();

/**
 * Obtiene el token del header Authorization: Bearer xxx o X-Auth-Token
 */
function get_bearer_token(): ?string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if ($auth && preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        return $m[1];
    }
    $xt = $headers['X-Auth-Token'] ?? $headers['x-auth-token'] ?? '';
    if ($xt !== '') {
        return $xt;
    }
    if (!empty($_GET['token'])) {
        return (string)$_GET['token'];
    }
    return null;
}

/**
 * Requiere sesión válida. Devuelve fila de usuario + token.
 */
function require_auth(?array $roles = null): array
{
    $token = get_bearer_token();
    if (!$token) {
        json_error('No autenticado', 401);
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT s.token, s.expires_at, u.id, u.username, u.full_name, u.email, u.role, u.active
         FROM sessions s
         INNER JOIN users u ON u.id = s.user_id
         WHERE s.token = :t LIMIT 1"
    );
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch();

    if (!$row) {
        json_error('Sesión inválida', 401);
    }
    if (strtotime($row['expires_at']) < time()) {
        $pdo->prepare('DELETE FROM sessions WHERE token = :t')->execute([':t' => $token]);
        json_error('Sesión expirada', 401);
    }
    if (!(int)$row['active']) {
        json_error('Usuario desactivado', 403);
    }
    if ($roles !== null && !in_array($row['role'], $roles, true)) {
        json_error('No tiene permisos para esta acción', 403);
    }

    return $row;
}

function require_admin(): array
{
    return require_auth(['ADMIN']);
}

// --- Router del endpoint auth ---
$action = $_GET['action'] ?? '';

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = read_json_body();
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');

    if ($username === '' || $password === '') {
        json_error('Usuario y contraseña son obligatorios');
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT id, username, password_hash, full_name, email, role, active FROM users WHERE username = :u LIMIT 1'
    );
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if (!$user || !(int)$user['active'] || !password_verify($password, $user['password_hash'])) {
        json_error('Credenciales incorrectas', 401);
    }

    $token = bin2hex(random_bytes(32));
    $expires = (new DateTimeImmutable('+' . SESSION_HOURS . ' hours'))->format('Y-m-d H:i:s');

    $pdo->prepare('DELETE FROM sessions WHERE user_id = :id')->execute([':id' => $user['id']]);
    $ins = $pdo->prepare(
        'INSERT INTO sessions (user_id, token, expires_at) VALUES (:uid, :tok, :exp)'
    );
    $ins->execute([':uid' => $user['id'], ':tok' => $token, ':exp' => $expires]);

    json_response([
        'ok'    => true,
        'token' => $token,
        'expires_at' => $expires,
        'user'  => [
            'id'        => (int)$user['id'],
            'username'  => $user['username'],
            'full_name' => $user['full_name'],
            'email'     => $user['email'],
            'role'      => $user['role'],
        ],
    ]);
}

if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = get_bearer_token();
    if ($token) {
        db()->prepare('DELETE FROM sessions WHERE token = :t')->execute([':t' => $token]);
    }
    json_response(['ok' => true]);
}

if ($action === 'me' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = require_auth();
    json_response([
        'ok'   => true,
        'user' => [
            'id'        => (int)$user['id'],
            'username'  => $user['username'],
            'full_name' => $user['full_name'],
            'email'     => $user['email'],
            'role'      => $user['role'],
        ],
    ]);
}

// Si se incluye como librería (require), no fallar
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'auth.php') {
    json_error('Acción no válida. Use ?action=login|logout|me', 404);
}
