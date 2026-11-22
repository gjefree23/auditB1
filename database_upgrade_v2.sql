-- Upgrade v2: stock bodega, aprobación, checklist, dashboard
USE audit_b1;

ALTER TABLE users
  MODIFY role ENUM('ADMIN','AUDITOR','SUPERVISOR') NOT NULL DEFAULT 'AUDITOR';

ALTER TABLE audits
  ADD COLUMN IF NOT EXISTS approval_status ENUM('PENDIENTE','APROBADA','RECHAZADA') NOT NULL DEFAULT 'PENDIENTE' AFTER sap_status,
  ADD COLUMN IF NOT EXISTS supervisor_id INT UNSIGNED NULL AFTER approval_status,
  ADD COLUMN IF NOT EXISTS supervisor_name VARCHAR(150) NULL AFTER supervisor_id,
  ADD COLUMN IF NOT EXISTS supervisor_note TEXT NULL AFTER supervisor_name,
  ADD COLUMN IF NOT EXISTS supervised_at DATETIME NULL AFTER supervisor_note;

-- MySQL 5.7 may not support IF NOT EXISTS on ADD COLUMN — fallback handled by PHP migrate

CREATE TABLE IF NOT EXISTS checklist_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  warehouse_code VARCHAR(20) NULL,
  warehouse_type VARCHAR(40) NULL DEFAULT 'GENERAL',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS checklist_template_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  question VARCHAR(255) NOT NULL,
  required TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_cti_tpl FOREIGN KEY (template_id) REFERENCES checklist_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_checklist (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  audit_id INT UNSIGNED NOT NULL,
  template_item_id INT UNSIGNED NULL,
  question VARCHAR(255) NOT NULL,
  answer ENUM('SI','NO','NA') NOT NULL DEFAULT 'NA',
  comment VARCHAR(255) NULL,
  CONSTRAINT fk_ac_audit FOREIGN KEY (audit_id) REFERENCES audits(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Supervisor demo (password Admin123!)
INSERT INTO users (username, password_hash, full_name, email, role, active)
SELECT 'supervisor', '$2y$10$qv3xjuqXrDNoZKmRZn.MneWbNBrklaRJFYWWshZSCxhXaoWWeepm6',
       'Ana Supervisora', 'supervisor@audit.local', 'SUPERVISOR', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'supervisor');

INSERT INTO checklist_templates (name, warehouse_code, warehouse_type, active)
SELECT 'Checklist General de Bodega', NULL, 'GENERAL', 1
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM checklist_templates WHERE name = 'Checklist General de Bodega');

SET @tpl := (SELECT id FROM checklist_templates WHERE name = 'Checklist General de Bodega' LIMIT 1);

INSERT INTO checklist_template_items (template_id, sort_order, question, required)
SELECT @tpl, 1, '¿Área de conteo señalizada y segura?', 1 FROM DUAL
WHERE @tpl IS NOT NULL AND NOT EXISTS (SELECT 1 FROM checklist_template_items WHERE template_id=@tpl AND sort_order=1);

INSERT INTO checklist_template_items (template_id, sort_order, question, required)
SELECT @tpl, 2, '¿Se verificó limpieza y orden del pasillo?', 1 FROM DUAL
WHERE @tpl IS NOT NULL AND NOT EXISTS (SELECT 1 FROM checklist_template_items WHERE template_id=@tpl AND sort_order=2);

INSERT INTO checklist_template_items (template_id, sort_order, question, required)
SELECT @tpl, 3, '¿Equipos de medición/conteo calibrados o en buen estado?', 1 FROM DUAL
WHERE @tpl IS NOT NULL AND NOT EXISTS (SELECT 1 FROM checklist_template_items WHERE template_id=@tpl AND sort_order=3);

INSERT INTO checklist_template_items (template_id, sort_order, question, required)
SELECT @tpl, 4, '¿Hay evidencia fotográfica de diferencias encontradas?', 0 FROM DUAL
WHERE @tpl IS NOT NULL AND NOT EXISTS (SELECT 1 FROM checklist_template_items WHERE template_id=@tpl AND sort_order=4);

INSERT INTO checklist_template_items (template_id, sort_order, question, required)
SELECT @tpl, 5, '¿Se contrastó con el sistema SAP antes de firmar?', 1 FROM DUAL
WHERE @tpl IS NOT NULL AND NOT EXISTS (SELECT 1 FROM checklist_template_items WHERE template_id=@tpl AND sort_order=5);
