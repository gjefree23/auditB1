# Portal de Auditoría y Validación de Conteo de Almacén — SAP Business One

Sistema web ligero para auditar conteos de inventario en planta, generar un **Acta PDF** con evidencia fotográfica anotada y firma, y enviar **solo el resultado final** a SAP Business One Service Layer.

## Stack

| Capa | Tecnología |
|------|------------|
| Frontend | OpenUI5 (CDN) · tema Horizon · PWA |
| Backend | PHP 8+ puro · PDO · cURL |
| BD | MySQL (`audit_b1`) |
| PDF | FPDF (`lib/fpdf`) |

## Requisitos

- XAMPP / Laragon / Apache + PHP 8+ + MySQL
- Extensiones PHP: `pdo_mysql`, `curl`, `openssl`, `gd`, `mbstring` (recomendado)
- Navegador moderno (Chrome/Edge/Safari) para cámara y canvas

## Instalación rápida (XAMPP Windows)

1. Copie el proyecto en `C:\xampp\htdocs\auditB1`
2. Inicie **Apache** y **MySQL** desde el panel de XAMPP
3. Importe la base de datos de **prueba/demo** (recomendado):
   - Abra http://localhost/phpmyadmin → Importar `database_demo.sql`
   - O por consola:
     ```bat
     C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\auditB1\database_demo.sql
     ```
   - Alternativa mínima (solo esquema base): `database.sql` + luego `database_upgrade_v2.sql` o `api/migrate_v2.php`
4. Revise credenciales MySQL en `api/config.php` (`DB_USER`, `DB_PASS`)
5. Asegúrese de que existan y sean escribibles:
   - `uploads/fotos/`
   - `uploads/actas/`
6. Abra la URL según el puerto de Apache en su XAMPP. En muchas instalaciones locales es:
   - **http://localhost:8092/auditB1/**  
   - o **http://localhost/auditB1/** si Apache escucha en el puerto 80

### Usuarios demo

| Usuario | Contraseña | Rol |
|---------|------------|-----|
| `admin` | `Admin123!` | ADMIN |
| `auditor` | `Admin123!` | AUDITOR |
| `supervisor` | `Admin123!` | SUPERVISOR |

`database_demo.sql` también carga 3 actas de ejemplo y la plantilla de checklist.

### Cron reintentos SAP
```bat
C:\xampp\php\php.exe C:\xampp\htdocs\auditB1\api\cron_retry_sap.php
```
Programar cada 10 minutos en el Programador de tareas de Windows.

> Cambie `APP_SECRET` en `api/config.php` antes de producción (cifra la contraseña SAP).

## Instalación en Linux

```bash
sudo apt install apache2 php php-mysql php-curl php-gd php-mbstring mysql-server
sudo cp -r auditB1 /var/www/html/
sudo chown -R www-data:www-data /var/www/html/auditB1/uploads
mysql -u root -p < /var/www/html/auditB1/database_demo.sql
```

URL típica: `http://servidor/auditB1/`

## FPDF

El proyecto incluye FPDF en `lib/fpdf/`. Alternativa con Composer:

```bash
composer install
```

(`composer.json` requiere `setasign/fpdf`)

## Flujo funcional

1. **Login** local (MySQL) — roles `ADMIN` / `AUDITOR`
2. **Configuración SAP** (solo ADMIN): URL Service Layer, Company DB, usuario/clave, modo simulación + **Probar conexión**
3. **Nueva auditoría**
   - Paso 1: Almacén + documento de conteo (`InventoryCountings` o datos demo)
   - Paso 2: Validar cantidades → `OK` / `DIFERENCIA`
   - Paso 3: Foto + anotación en canvas (rojo) + nota
   - Paso 4: Dictamen + firma
   - Paso 5: Guarda en MySQL, genera PDF; si SAP activo:
     1. `POST /Attachments2` (PDF, se reutiliza en reintentos)
     2. `PATCH /InventoryCountings({DocEntry})` con `CountedQuantity` + `Counted=tYES` (+ `AttachmentEntry` si aplica)
     3. Metadatos de auditoría (`verdict`, `audit_code`, `auditor_name`) quedan **solo en MySQL** (sin UDF en SAP)
4. **Historial**: filtros, detalle, descarga PDF, reintento SAP si quedó `PENDIENTE_SAP`

## Estructura

```
/index.html
/manifest.webmanifest
/sw.js
/database.sql
/README.md
/api/
  config.php, db.php, auth.php, users.php
  sap_config.php, sap_service.php, audits.php, generate_pdf.php
/webapp/
  Component.js, manifest.json
  view/, controller/, model/, css/, i18n/, img/
/uploads/fotos/  /uploads/actas/
/lib/fpdf/
```

## Metadatos de auditoría (MySQL, no UDF en SAP)

No se crean campos de usuario en SAP. Equivalencias locales:

| Dato | Columna MySQL (`audits`) |
|------|--------------------------|
| Estado / dictamen | `verdict` |
| Código de acta | `audit_code` |
| Auditor | `auditor_name` |
| Adjunto PDF en SAP | `sap_attachment_entry` |
| Respuesta / trazas | `sap_response`, `sap_sent_at`, `sap_status` |

El envío a SAP escribe cantidades (`CountedQuantity`, `Counted`) en el conteo existente y, si la compañía lo permite, vincula el PDF con `AttachmentEntry`.

## API REST (resumen)

| Endpoint | Acción |
|----------|--------|
| `POST api/auth.php?action=login` | Login |
| `GET  api/auth.php?action=me` | Sesión actual |
| `GET/POST api/users.php?action=…` | CRUD usuarios (ADMIN) |
| `GET/POST api/sap_config.php?action=get\|save\|test` | Config SAP |
| `GET api/sap_service.php?action=warehouses\|countings\|counting` | Datos SAP/sim |
| `GET/POST api/audits.php?action=list\|get\|submit\|retry_sap` | Auditorías |
| `GET api/generate_pdf.php?id=&download=1` | PDF del acta |

Todas las rutas (salvo login) requieren header:

`Authorization: Bearer <token>`

## Modo simulación

Por defecto viene **activado**. Permite probar el flujo completo sin servidor SAP. Al desactivarlo y guardar credenciales válidas, el envío final usa Service Layer real.

## Seguridad (checklist)

- [ ] Cambiar `APP_SECRET` y contraseñas demo
- [ ] Usar HTTPS en producción (Service Layer y portal)
- [ ] Restringir acceso a `/api` y `/uploads` por red si aplica
- [ ] No versionar contraseñas SAP en texto plano (ya se cifran en BD)

## Licencia

Uso interno / proyecto de demostración. FPDF: licencia FPDF/Setasign según su distribución.
