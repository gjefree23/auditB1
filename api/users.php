<?php
/**
 * CRUD de usuarios locales
 * GET    ?action=list
 * GET    ?action=get&id=
 * POST   ?action=create
 * PUT    ?action=update
 * PATCH  ?action=toggle   {id, active}
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

handle_preflight();

$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'list' && $method === 'GET') {
    require_admin();
    $rows = db()->query(
        'SELECT id, username, full_name, email, role, active, created_at, updated_at
         FROM users ORDER BY id ASC'
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['active'] = (int)$r['active'];
    }
    json_response(['ok' => true, 'data' => $rows]);
}

if ($action === 'get' && $method === 'GET') {
    require_admin();
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare(
        'SELECT id, username, full_name, email, role, active, created_at FROM users WHERE id = :id'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Usuario no encontrado', 404);
    }
    $row['id'] = (int)$row['id'];
    $row['active'] = (int)$row['active'];
    json_response(['ok' => true, 'data' => $row]);
}

if ($action === 'create' && $method === 'POST') {
    require_admin();
    $b = read_json_body();
    $username = trim((string)($b['username'] ?? ''));
    $password = (string)($b['password'] ?? '');
    $fullName = trim((string)($b['full_name'] ?? ''));
    $email    = trim((string)($b['email'] ?? ''));
    $role     = strtoupper(trim((string)($b['role'] ?? 'AUDITOR')));
    $active   = isset($b['active']) ? (int)(bool)$b['active'] : 1;

    if ($username === '' || $password === '' || $fullName === '') {
        json_error('username, password y full_name son obligatorios');
    }
    if (!in_array($role, ['ADMIN', 'AUDITOR', 'SUPERVISOR'], true)) {
        json_error('Rol inválido');
    }
    if (strlen($password) < 6) {
        json_error('La contraseña debe tener al menos 6 caracteres');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $stmt = db()->prepare(
            'INSERT INTO users (username, password_hash, full_name, email, role, active)
             VALUES (:u, :p, :n, :e, :r, :a)'
        );
        $stmt->execute([
            ':u' => $username,
            ':p' => $hash,
            ':n' => $fullName,
            ':e' => $email !== '' ? $email : null,
            ':r' => $role,
            ':a' => $active,
        ]);
        json_response(['ok' => true, 'id' => (int)db()->lastInsertId()], 201);
    } catch (PDOException $e) {
        if ((int)$e->errorInfo[1] === 1062) {
            json_error('El nombre de usuario ya existe', 409);
        }
        json_error('Error al crear usuario: ' . $e->getMessage(), 500);
    }
}

if ($action === 'update' && in_array($method, ['PUT', 'POST'], true)) {
    require_admin();
    $b = read_json_body();
    $id = (int)($b['id'] ?? 0);
    if ($id <= 0) {
        json_error('id es obligatorio');
    }

    $stmt = db()->prepare('SELECT id FROM users WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if (!$stmt->fetch()) {
        json_error('Usuario no encontrado', 404);
    }

    $fullName = trim((string)($b['full_name'] ?? ''));
    $email    = trim((string)($b['email'] ?? ''));
    $role     = strtoupper(trim((string)($b['role'] ?? 'AUDITOR')));
    $active   = isset($b['active']) ? (int)(bool)$b['active'] : 1;
    $password = (string)($b['password'] ?? '');

    if ($fullName === '') {
        json_error('full_name es obligatorio');
    }
    if (!in_array($role, ['ADMIN', 'AUDITOR', 'SUPERVISOR'], true)) {
        json_error('Rol inválido');
    }

    if ($password !== '') {
        if (strlen($password) < 6) {
            json_error('La contraseña debe tener al menos 6 caracteres');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $sql = 'UPDATE users SET full_name=:n, email=:e, role=:r, active=:a, password_hash=:p WHERE id=:id';
        $params = [':n' => $fullName, ':e' => $email !== '' ? $email : null, ':r' => $role, ':a' => $active, ':p' => $hash, ':id' => $id];
    } else {
        $sql = 'UPDATE users SET full_name=:n, email=:e, role=:r, active=:a WHERE id=:id';
        $params = [':n' => $fullName, ':e' => $email !== '' ? $email : null, ':r' => $role, ':a' => $active, ':id' => $id];
    }
    db()->prepare($sql)->execute($params);
    json_response(['ok' => true]);
}

if ($action === 'toggle' && in_array($method, ['PATCH', 'POST'], true)) {
    require_admin();
    $b = read_json_body();
    $id = (int)($b['id'] ?? 0);
    $active = isset($b['active']) ? (int)(bool)$b['active'] : null;
    if ($id <= 0 || $active === null) {
        json_error('id y active son obligatorios');
    }
    $me = require_admin();
    if ((int)$me['id'] === $id && $active === 0) {
        json_error('No puede desactivarse a sí mismo');
    }
    db()->prepare('UPDATE users SET active = :a WHERE id = :id')->execute([':a' => $active, ':id' => $id]);
    json_response(['ok' => true]);
}

json_error('Acción no válida', 404);
