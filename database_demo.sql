-- =============================================================================
-- Audit B1 — Script COMPLETO de base de datos DEMO / PRUEBA
-- MySQL 5.7+ / 8.x  |  Charset: utf8mb4
-- Incluye esquema v2 (aprobaciones + checklist) y datos de prueba.
--
-- Uso (XAMPP):
--   C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\auditB1\database_demo.sql
--
-- Usuarios demo (contraseña: Admin123!):
--   admin / auditor / supervisor
-- =============================================================================

DROP DATABASE IF EXISTS audit_b1;

CREATE DATABASE audit_b1
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE audit_b1;

-- -----------------------------------------------------------------------------
-- Usuarios
-- -----------------------------------------------------------------------------
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(150) NOT NULL,
  email         VARCHAR(150) NULL,
  role          ENUM('ADMIN','AUDITOR','SUPERVISOR') NOT NULL DEFAULT 'AUDITOR',
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Configuración SAP (simulación activa por defecto)
-- -----------------------------------------------------------------------------
CREATE TABLE sap_config (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sl_url          VARCHAR(255) NOT NULL DEFAULT '',
  company_db      VARCHAR(100) NOT NULL DEFAULT '',
  sl_user         VARCHAR(100) NOT NULL DEFAULT '',
  sl_password     TEXT         NOT NULL,
  simulation_mode TINYINT(1)   NOT NULL DEFAULT 1,
  updated_by      INT UNSIGNED NULL,
  updated_at      DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sap_config_user FOREIGN KEY (updated_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Auditorías
-- -----------------------------------------------------------------------------
CREATE TABLE audits (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  audit_code           VARCHAR(40)  NOT NULL UNIQUE,
  warehouse_code       VARCHAR(20)  NOT NULL,
  warehouse_name       VARCHAR(100) NOT NULL DEFAULT '',
  counting_doc_entry   INT          NOT NULL DEFAULT 0,
  counting_doc_num     INT          NOT NULL DEFAULT 0,
  counting_date        DATE         NULL,
  verdict              ENUM('APROBADO','CON_OBSERVACION','RECHAZADO') NOT NULL,
  observation_note     TEXT         NULL,
  photo_path           VARCHAR(255) NULL,
  photo_annotated_path VARCHAR(255) NULL,
  signature_path       VARCHAR(255) NULL,
  pdf_path             VARCHAR(255) NULL,
  sap_status           ENUM('PENDIENTE_SAP','ENVIADO_SAP','SIMULADO','NO_APLICA') NOT NULL DEFAULT 'SIMULADO',
  approval_status      ENUM('PENDIENTE','APROBADA','RECHAZADA') NOT NULL DEFAULT 'APROBADA',
  supervisor_id        INT UNSIGNED NULL,
  supervisor_name      VARCHAR(150) NULL,
  supervisor_note      TEXT         NULL,
  supervised_at        DATETIME     NULL,
  sap_attachment_entry INT          NULL,
  sap_response         TEXT         NULL,
  sap_sent_at          DATETIME     NULL,
  auditor_id           INT UNSIGNED NOT NULL,
  auditor_name         VARCHAR(150) NOT NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_audits_user FOREIGN KEY (auditor_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE INDEX idx_audits_warehouse ON audits (warehouse_code);
CREATE INDEX idx_audits_verdict   ON audits (verdict);
CREATE INDEX idx_audits_sap       ON audits (sap_status);
CREATE INDEX idx_audits_created   ON audits (created_at);
CREATE INDEX idx_audits_approval  ON audits (approval_status);

-- -----------------------------------------------------------------------------
-- Ítems de auditoría
-- -----------------------------------------------------------------------------
CREATE TABLE audit_items (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  audit_id        INT UNSIGNED NOT NULL,
  line_num        INT          NOT NULL DEFAULT 0,
  item_code       VARCHAR(50)  NOT NULL,
  item_name       VARCHAR(200) NOT NULL DEFAULT '',
  barcode         VARCHAR(100) NULL,
  system_qty      DECIMAL(18,4) NOT NULL DEFAULT 0,
  counted_qty     DECIMAL(18,4) NOT NULL DEFAULT 0,
  difference_qty  DECIMAL(18,4) NOT NULL DEFAULT 0,
  unit_cost       DECIMAL(18,4) NOT NULL DEFAULT 0,
  merma_qty       DECIMAL(18,4) NOT NULL DEFAULT 0,
  merma_value     DECIMAL(18,4) NOT NULL DEFAULT 0,
  status          ENUM('OK','DIFERENCIA') NOT NULL DEFAULT 'OK',
  notes           TEXT         NULL,
  photo_path      VARCHAR(255) NULL,
  CONSTRAINT fk_audit_items_audit FOREIGN KEY (audit_id) REFERENCES audits(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_audit_items_audit ON audit_items (audit_id);

-- -----------------------------------------------------------------------------
-- Checklist (plantillas + respuestas)
-- -----------------------------------------------------------------------------
CREATE TABLE checklist_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  warehouse_code VARCHAR(20) NULL,
  warehouse_type VARCHAR(40) NULL DEFAULT 'GENERAL',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE checklist_template_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  question VARCHAR(255) NOT NULL,
  required TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_cti_tpl FOREIGN KEY (template_id) REFERENCES checklist_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE audit_checklist (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  audit_id INT UNSIGNED NOT NULL,
  template_item_id INT UNSIGNED NULL,
  question VARCHAR(255) NOT NULL,
  answer ENUM('SI','NO','NA') NOT NULL DEFAULT 'NA',
  comment VARCHAR(255) NULL,
  CONSTRAINT fk_ac_audit FOREIGN KEY (audit_id) REFERENCES audits(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Sesiones
-- -----------------------------------------------------------------------------
CREATE TABLE sessions (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  token      VARCHAR(64)  NOT NULL UNIQUE,
  expires_at DATETIME     NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_sessions_token ON sessions (token);

-- =============================================================================
-- DATOS DE PRUEBA
-- Contraseña de todos: Admin123!
-- Hash bcrypt: password_hash('Admin123!', PASSWORD_DEFAULT)
-- =============================================================================

INSERT INTO users (username, password_hash, full_name, email, role, active) VALUES
('admin',      '$2y$10$qv3xjuqXrDNoZKmRZn.MneWbNBrklaRJFYWWshZSCxhXaoWWeepm6', 'Administrador Sistema', 'admin@audit.local',      'ADMIN',      1),
('auditor',    '$2y$10$qv3xjuqXrDNoZKmRZn.MneWbNBrklaRJFYWWshZSCxhXaoWWeepm6', 'Juan Auditor Demo',     'auditor@audit.local',    'AUDITOR',    1),
('supervisor', '$2y$10$qv3xjuqXrDNoZKmRZn.MneWbNBrklaRJFYWWshZSCxhXaoWWeepm6', 'Ana Supervisora',      'supervisor@audit.local', 'SUPERVISOR', 1);

INSERT INTO sap_config (sl_url, company_db, sl_user, sl_password, simulation_mode, updated_by)
VALUES (
  'https://servidor:50000/b1s/v1',
  'SBODEMOCL',
  'manager',
  '',
  1,
  1
);

INSERT INTO checklist_templates (name, warehouse_code, warehouse_type, active)
VALUES ('Checklist General de Bodega', NULL, 'GENERAL', 1);

SET @tpl := LAST_INSERT_ID();

INSERT INTO checklist_template_items (template_id, sort_order, question, required) VALUES
(@tpl, 1, '¿Área de conteo señalizada y segura?', 1),
(@tpl, 2, '¿Se verificó limpieza y orden del pasillo?', 1),
(@tpl, 3, '¿Equipos de medición/conteo calibrados o en buen estado?', 1),
(@tpl, 4, '¿Hay evidencia fotográfica de diferencias encontradas?', 0),
(@tpl, 5, '¿Se contrastó con el sistema SAP antes de firmar?', 1);

-- Acta demo 1: aprobada, con diferencia en un ítem
INSERT INTO audits (
  audit_code, warehouse_code, warehouse_name, counting_doc_entry, counting_doc_num,
  counting_date, verdict, observation_note, sap_status, approval_status,
  auditor_id, auditor_name, supervisor_id, supervisor_name, supervised_at
) VALUES (
  'AUD-2026-DEMO-01', '01', 'Almacén General', 1001, 15,
  CURDATE(), 'CON_OBSERVACION', 'Diferencia en teclados. Revisado en simulación.',
  'SIMULADO', 'APROBADA',
  2, 'Juan Auditor Demo', 3, 'Ana Supervisora', NOW()
);

SET @a1 := LAST_INSERT_ID();

INSERT INTO audit_items (
  audit_id, line_num, item_code, item_name, barcode,
  system_qty, counted_qty, difference_qty, unit_cost, merma_qty, merma_value, status, notes
) VALUES
(@a1, 0, 'A00001', 'Mouse Óptico USB',  'A00001', 100.0000, 100.0000,  0.0000, 15.0000, 0.0000, 0.0000, 'OK', NULL),
(@a1, 1, 'A00002', 'Teclado Mecánico',  'A00002',  50.0000,  48.0000, -2.0000, 45.0000, 2.0000, 90.0000, 'DIFERENCIA', 'Faltante físico'),
(@a1, 2, 'B00010', 'Cable HDMI 2m',     'B00010', 200.0000, 200.0000,  0.0000,  8.5000, 0.0000, 0.0000, 'OK', NULL);

INSERT INTO audit_checklist (audit_id, template_item_id, question, answer, comment) VALUES
(@a1, 1, '¿Área de conteo señalizada y segura?', 'SI', NULL),
(@a1, 2, '¿Se verificó limpieza y orden del pasillo?', 'SI', NULL),
(@a1, 3, '¿Equipos de medición/conteo calibrados o en buen estado?', 'SI', NULL),
(@a1, 4, '¿Hay evidencia fotográfica de diferencias encontradas?', 'SI', 'Foto de pasillo B'),
(@a1, 5, '¿Se contrastó con el sistema SAP antes de firmar?', 'SI', NULL);

-- Acta demo 2: pendiente de supervisor
INSERT INTO audits (
  audit_code, warehouse_code, warehouse_name, counting_doc_entry, counting_doc_num,
  counting_date, verdict, observation_note, sap_status, approval_status,
  auditor_id, auditor_name
) VALUES (
  'AUD-2026-DEMO-02', '02', 'Almacén Materias Primas', 1002, 16,
  CURDATE(), 'APROBADO', 'Sin observaciones. Pendiente de aprobación.',
  'SIMULADO', 'PENDIENTE',
  2, 'Juan Auditor Demo'
);

SET @a2 := LAST_INSERT_ID();

INSERT INTO audit_items (
  audit_id, line_num, item_code, item_name,
  system_qty, counted_qty, difference_qty, unit_cost, merma_qty, merma_value, status
) VALUES
(@a2, 0, 'MP-001', 'Resina PVC Granulado', 500.0000, 495.5000, -4.5000, 12.0000, 4.5000, 54.0000, 'DIFERENCIA'),
(@a2, 1, 'MP-002', 'Pigmento Azul Industrial', 25.0000, 25.0000, 0.0000, 80.0000, 0.0000, 0.0000, 'OK');

INSERT INTO audit_checklist (audit_id, question, answer) VALUES
(@a2, '¿Área de conteo señalizada y segura?', 'SI'),
(@a2, '¿Se verificó limpieza y orden del pasillo?', 'SI'),
(@a2, '¿Equipos de medición/conteo calibrados o en buen estado?', 'SI'),
(@a2, '¿Se contrastó con el sistema SAP antes de firmar?', 'SI');

-- Acta demo 3: aprobada OK
INSERT INTO audits (
  audit_code, warehouse_code, warehouse_name, counting_doc_entry, counting_doc_num,
  counting_date, verdict, observation_note, sap_status, approval_status,
  auditor_id, auditor_name, supervisor_id, supervisor_name, supervised_at
) VALUES (
  'AUD-2026-DEMO-03', '01', 'Almacén General', 0, 0,
  CURDATE(), 'APROBADO', 'Conteo libre de bodega — demo.',
  'NO_APLICA', 'APROBADA',
  1, 'Administrador Sistema', 1, 'Administrador Sistema', NOW()
);

SET @a3 := LAST_INSERT_ID();

INSERT INTO audit_items (
  audit_id, line_num, item_code, item_name,
  system_qty, counted_qty, difference_qty, unit_cost, status
) VALUES
(@a3, 0, 'A00001', 'Mouse Óptico USB', 990.0000, 990.0000, 0.0000, 15.0000, 'OK');

SELECT 'OK database_demo.sql cargado' AS resultado;
SELECT username, role, active FROM users;
SELECT audit_code, warehouse_name, verdict, sap_status, approval_status FROM audits;
