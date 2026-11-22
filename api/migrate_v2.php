<?php
/**
 * Migración v2 — ejecutar una vez: http://localhost:8092/auditB1/api/migrate_v2.php
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$log = [];

function colExists(PDO $pdo, string $table, string $col): bool
{
    $table = preg_replace('/[^a-z0-9_]/i', '', $table);
    $col = preg_replace('/[^a-z0-9_]/i', '', $col);
    $rows = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll();
    foreach ($rows as $r) {
        if (strcasecmp((string)$r['Field'], $col) === 0) {
            return true;
        }
    }
    return false;
}

try {
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('ADMIN','AUDITOR','SUPERVISOR') NOT NULL DEFAULT 'AUDITOR'");
    $log[] = 'OK users.role +SUPERVISOR';

    $cols = [
        'approval_status' => "ENUM('PENDIENTE','APROBADA','RECHAZADA') NOT NULL DEFAULT 'PENDIENTE'",
        'supervisor_id' => 'INT UNSIGNED NULL',
        'supervisor_name' => 'VARCHAR(150) NULL',
        'supervisor_note' => 'TEXT NULL',
        'supervised_at' => 'DATETIME NULL',
    ];
    foreach ($cols as $name => $def) {
        if (!colExists($pdo, 'audits', $name)) {
            $pdo->exec("ALTER TABLE audits ADD COLUMN `$name` $def");
            $log[] = "OK audits.$name";
        } else {
            $log[] = "SKIP audits.$name";
        }
    }

    // Marcar auditorías existentes como ya aprobadas (histórico)
    $pdo->exec("UPDATE audits SET approval_status = 'APROBADA' WHERE approval_status = 'PENDIENTE' AND created_at < NOW()");

    $pdo->exec("CREATE TABLE IF NOT EXISTS checklist_templates (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL,
      warehouse_code VARCHAR(20) NULL,
      warehouse_type VARCHAR(40) NULL DEFAULT 'GENERAL',
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS checklist_template_items (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      template_id INT UNSIGNED NOT NULL,
      sort_order INT NOT NULL DEFAULT 0,
      question VARCHAR(255) NOT NULL,
      required TINYINT(1) NOT NULL DEFAULT 1,
      CONSTRAINT fk_cti_tpl FOREIGN KEY (template_id) REFERENCES checklist_templates(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_checklist (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      audit_id INT UNSIGNED NOT NULL,
      template_item_id INT UNSIGNED NULL,
      question VARCHAR(255) NOT NULL,
      answer ENUM('SI','NO','NA') NOT NULL DEFAULT 'NA',
      comment VARCHAR(255) NULL,
      CONSTRAINT fk_ac_audit FOREIGN KEY (audit_id) REFERENCES audits(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $log[] = 'OK checklist tables';

    $itemCols = [
        'unit_cost' => 'DECIMAL(18,4) NOT NULL DEFAULT 0',
        'merma_qty' => 'DECIMAL(18,4) NOT NULL DEFAULT 0',
        'merma_value' => 'DECIMAL(18,4) NOT NULL DEFAULT 0',
    ];
    foreach ($itemCols as $name => $def) {
        if (!colExists($pdo, 'audit_items', $name)) {
            $pdo->exec("ALTER TABLE audit_items ADD COLUMN `$name` $def");
            $log[] = "OK audit_items.$name";
        } else {
            $log[] = "SKIP audit_items.$name";
        }
    }

    $hash = '$2y$10$qv3xjuqXrDNoZKmRZn.MneWbNBrklaRJFYWWshZSCxhXaoWWeepm6';
    $chk = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $chk->execute(['supervisor']);
    if (!$chk->fetch()) {
        $pdo->prepare('INSERT INTO users (username, password_hash, full_name, email, role, active) VALUES (?,?,?,?,?,1)')
            ->execute(['supervisor', $hash, 'Ana Supervisora', 'supervisor@audit.local', 'SUPERVISOR']);
        $log[] = 'OK user supervisor / Admin123!';
    }

    $tpl = $pdo->query("SELECT id FROM checklist_templates WHERE name = 'Checklist General de Bodega' LIMIT 1")->fetchColumn();
    if (!$tpl) {
        $pdo->exec("INSERT INTO checklist_templates (name, warehouse_code, warehouse_type, active) VALUES ('Checklist General de Bodega', NULL, 'GENERAL', 1)");
        $tpl = (int)$pdo->lastInsertId();
        $qs = [
            [1, '¿Área de conteo señalizada y segura?', 1],
            [2, '¿Se verificó limpieza y orden del pasillo?', 1],
            [3, '¿Equipos de medición/conteo en buen estado?', 1],
            [4, '¿Hay evidencia fotográfica de diferencias?', 0],
            [5, '¿Se contrastó con SAP antes de firmar?', 1],
        ];
        $ins = $pdo->prepare('INSERT INTO checklist_template_items (template_id, sort_order, question, required) VALUES (?,?,?,?)');
        foreach ($qs as $q) {
            $ins->execute([$tpl, $q[0], $q[1], $q[2]]);
        }
        $log[] = 'OK checklist seed';
    }

    echo "Migración v2 completada\n" . implode("\n", $log) . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: ' . $e->getMessage();
}
