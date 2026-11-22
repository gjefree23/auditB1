-- =============================================================================
-- Portal de Auditoría y Validación de Conteo de Almacén - SAP Business One
-- MySQL 5.7+ / 8.x  |  Charset: utf8mb4
-- =============================================================================

CREATE DATABASE IF NOT EXISTS audit_b1
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE audit_b1;

-- -----------------------------------------------------------------------------
-- Usuarios locales del portal
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(150) NOT NULL,
  email         VARCHAR(150) NULL,
  role          ENUM('ADMIN','AUDITOR') NOT NULL DEFAULT 'AUDITOR',
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Configuración SAP Business One Service Layer (una sola fila activa)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sap_config (
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
-- Auditorías de conteo
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audits (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  audit_code        VARCHAR(40)  NOT NULL UNIQUE,
  warehouse_code    VARCHAR(20)  NOT NULL,
  warehouse_name    VARCHAR(100) NOT NULL DEFAULT '',
  counting_doc_entry INT         NOT NULL,
  counting_doc_num  INT          NOT NULL DEFAULT 0,
  counting_date     DATE         NULL,
  verdict           ENUM('APROBADO','CON_OBSERVACION','RECHAZADO') NOT NULL,
  observation_note  TEXT         NULL,
  photo_path        VARCHAR(255) NULL,
  photo_annotated_path VARCHAR(255) NULL,
  signature_path    VARCHAR(255) NULL,
  pdf_path          VARCHAR(255) NULL,
  sap_status        ENUM('PENDIENTE_SAP','ENVIADO_SAP','SIMULADO','NO_APLICA') NOT NULL DEFAULT 'PENDIENTE_SAP',
  sap_attachment_entry INT       NULL,
  sap_response      TEXT         NULL,
  sap_sent_at       DATETIME     NULL,
  auditor_id        INT UNSIGNED NOT NULL,
  auditor_name      VARCHAR(150) NOT NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_audits_user FOREIGN KEY (auditor_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE INDEX idx_audits_warehouse ON audits (warehouse_code);
CREATE INDEX idx_audits_verdict   ON audits (verdict);
CREATE INDEX idx_audits_sap       ON audits (sap_status);
CREATE INDEX idx_audits_created   ON audits (created_at);

-- -----------------------------------------------------------------------------
-- Líneas / ítems validados en cada auditoría
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_items (
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
-- Sesiones simples (token Bearer)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
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
-- SEED / DATOS DE PRUEBA
-- =============================================================================
-- Contraseña para ambos usuarios: Admin123!
-- Hash bcrypt generado con password_hash('Admin123!', PASSWORD_DEFAULT)

-- Contraseña para ambos: Admin123!
INSERT INTO users (username, password_hash, full_name, email, role, active) VALUES
('admin',   '$2y$10$qv3xjuqXrDNoZKmRZn.MneWbNBrklaRJFYWWshZSCxhXaoWWeepm6', 'Administrador Sistema', 'admin@audit.local',   'ADMIN',   1),
('auditor', '$2y$10$qv3xjuqXrDNoZKmRZn.MneWbNBrklaRJFYWWshZSCxhXaoWWeepm6', 'Juan Auditor Demo',     'auditor@audit.local', 'AUDITOR', 1);

-- Configuración SAP en modo simulación por defecto
INSERT INTO sap_config (sl_url, company_db, sl_user, sl_password, simulation_mode, updated_by)
VALUES (
  'https://servidor:50000/b1s/v1',
  'SBODEMOCL',
  'manager',
  '',
  1,
  1
);

-- Auditoría de ejemplo (historial)
INSERT INTO audits (
  audit_code, warehouse_code, warehouse_name, counting_doc_entry, counting_doc_num,
  counting_date, verdict, observation_note, sap_status, auditor_id, auditor_name
) VALUES (
  'AUD-2026-0001', '01', 'Almacén General', 1001, 15,
  '2026-03-15', 'APROBADO', 'Conteo verificado sin observaciones.', 'SIMULADO', 2, 'Juan Auditor Demo'
);

INSERT INTO audit_items (audit_id, line_num, item_code, item_name, system_qty, counted_qty, difference_qty, status) VALUES
(1, 0, 'A00001', 'Mouse Óptico USB',     100.0000, 100.0000, 0.0000, 'OK'),
(1, 1, 'A00002', 'Teclado Mecánico',      50.0000,  48.0000, -2.0000, 'DIFERENCIA'),
(1, 2, 'B00010', 'Cable HDMI 2m',        200.0000, 200.0000, 0.0000, 'OK');
