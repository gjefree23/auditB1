<?php
/**
 * Generación de Acta PDF de Auditoría con FPDF
 * Puede usarse como librería (generate_audit_pdf) o endpoint GET ?id=
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Compatibilidad UTF-8 → Latin1 para FPDF */
function pdf_text(string $text): string
{
    if (function_exists('mb_convert_encoding')) {
        return (string)mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
    }
    $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
    return $converted !== false ? $converted : $text;
}

/**
 * Convierte cualquier imagen a JPEG temporal compatible con FPDF
 * (evita fallos con PNG alpha / filtros).
 * @return array{0:string,1:string}|null [ruta, tipo]
 */
function pdf_prepare_image(string $absPath): ?array
{
    if (!is_file($absPath) || !function_exists('imagecreatefromstring')) {
        return null;
    }
    $bin = file_get_contents($absPath);
    if ($bin === false) {
        return null;
    }
    $src = @imagecreatefromstring($bin);
    if ($src === false) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $dst = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $w, $h, $white);
    imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
    $tmp = UPLOAD_ACTAS . DIRECTORY_SEPARATOR . 'tmp_' . bin2hex(random_bytes(6)) . '.jpg';
    imagejpeg($dst, $tmp, 88);
    imagedestroy($src);
    imagedestroy($dst);
    return [$tmp, 'JPEG'];
}

function pdf_embed_image(FPDF $pdf, string $absPath, float $x, float $y, float $w): void
{
    $prepared = pdf_prepare_image($absPath);
    if (!$prepared) {
        return;
    }
    [$tmp, $type] = $prepared;
    try {
        $pdf->Image($tmp, $x, $y, $w, 0, $type);
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

function fpdf_autoload(): void
{
    $paths = [
        APP_ROOT . '/vendor/setasign/fpdf/fpdf.php',
        APP_ROOT . '/lib/fpdf/fpdf.php',
        APP_ROOT . '/lib/fpdf.php',
    ];
    foreach ($paths as $p) {
        if (is_file($p)) {
            require_once $p;
            return;
        }
    }
    // Descarga mínima embebida si no hay FPDF
    throw new RuntimeException(
        'FPDF no encontrado. Ejecute: composer require setasign/fpdf  o coloque fpdf.php en /lib/fpdf/'
    );
}

/**
 * Genera PDF del acta y retorna ruta absoluta del archivo.
 */
function generate_audit_pdf(int $auditId): string
{
    ensure_upload_dirs();
    fpdf_autoload();

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM audits WHERE id = :id');
    $stmt->execute([':id' => $auditId]);
    $audit = $stmt->fetch();
    if (!$audit) {
        throw new RuntimeException('Auditoría no encontrada');
    }

    $items = $pdo->prepare('SELECT * FROM audit_items WHERE audit_id = :id ORDER BY line_num ASC');
    $items->execute([':id' => $auditId]);
    $lines = $items->fetchAll();

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    // Logo Audit B1
    $logoPath = APP_ROOT . '/webapp/img/logo.jpg';
    if (!is_file($logoPath)) {
        $logoPath = APP_ROOT . '/webapp/img/logo.png';
    }
    if (!is_file($logoPath)) {
        $logoPath = APP_ROOT . '/auditb1.jfif';
    }
    if (is_file($logoPath)) {
        pdf_embed_image($pdf, $logoPath, 55, 12, 100);
        $pdf->SetY(48);
    }

    // Encabezado
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->SetTextColor(0, 51, 102);
    $pdf->Cell(0, 10, pdf_text('ACTA DE AUDITORÍA DE CONTEO DE ALMACÉN'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->Cell(0, 6, pdf_text('Audit B1 — Auditoría Empresarial · SAP Business One'), 0, 1, 'C');
    $pdf->Ln(4);
    $pdf->SetDrawColor(0, 115, 207);
    $pdf->SetLineWidth(0.6);
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(6);

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 7, pdf_text('Datos generales'), 0, 1);
    $pdf->SetFont('Arial', '', 10);

    $rows = [
        ['Código de auditoría', $audit['audit_code']],
        ['Almacén', $audit['warehouse_code'] . ' — ' . $audit['warehouse_name']],
        ['Doc. Conteo', ((int)$audit['counting_doc_entry'] > 0)
            ? ($audit['counting_doc_entry'] . ' / ' . $audit['counting_doc_num'])
            : 'Sin documento (conteo libre)'],
        ['Fecha conteo', (string)($audit['counting_date'] ?? '-')],
        ['Dictamen', $audit['verdict']],
        ['Auditor', $audit['auditor_name']],
        ['Fecha auditoría', $audit['created_at']],
        ['Estado SAP', $audit['sap_status']],
    ];

    foreach ($rows as [$label, $val]) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(55, 6, pdf_text($label . ':'), 0, 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 6, pdf_text((string)$val), 0, 1);
    }

    $pdf->Ln(3);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 7, pdf_text('Observación'), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 5, pdf_text((string)($audit['observation_note'] ?? 'Sin observaciones.')));

    // Tabla de ítems
    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 7, pdf_text('Detalle de ítems validados'), 0, 1);

    $pdf->SetFillColor(0, 112, 192);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 7);
    $w = [10, 22, 40, 16, 16, 14, 16, 14, 18, 18];
    $headers = ['#', 'Código', 'Descripción', 'Sist.', 'Cont.', 'Dif.', 'Costo', 'Merma', '$Merma', 'Estado'];
    foreach ($headers as $i => $h) {
        $pdf->Cell($w[$i], 7, pdf_text($h), 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 7);
    $fill = false;
    $totalMerma = 0.0;
    foreach ($lines as $line) {
        if ($pdf->GetY() > 250) {
            $pdf->AddPage();
        }
        $mermaQty = (float)($line['merma_qty'] ?? 0);
        $mermaVal = (float)($line['merma_value'] ?? 0);
        if ($mermaQty <= 0) {
            $dif = (float)($line['difference_qty'] ?? 0);
            $cost = (float)($line['unit_cost'] ?? 0);
            if ($dif < 0) {
                $mermaQty = abs($dif);
                $mermaVal = round($mermaQty * $cost, 4);
            }
        }
        $totalMerma += $mermaVal;
        $pdf->SetFillColor(240, 245, 250);
        $pdf->Cell($w[0], 6, (string)$line['line_num'], 1, 0, 'C', $fill);
        $pdf->Cell($w[1], 6, pdf_text(substr($line['item_code'], 0, 12)), 1, 0, 'L', $fill);
        $pdf->Cell($w[2], 6, pdf_text(substr($line['item_name'], 0, 24)), 1, 0, 'L', $fill);
        $pdf->Cell($w[3], 6, number_format((float)$line['system_qty'], 2), 1, 0, 'R', $fill);
        $pdf->Cell($w[4], 6, number_format((float)$line['counted_qty'], 2), 1, 0, 'R', $fill);
        $pdf->Cell($w[5], 6, number_format((float)$line['difference_qty'], 2), 1, 0, 'R', $fill);
        $pdf->Cell($w[6], 6, number_format((float)($line['unit_cost'] ?? 0), 2), 1, 0, 'R', $fill);
        $pdf->Cell($w[7], 6, $mermaQty > 0 ? number_format($mermaQty, 2) : '—', 1, 0, 'R', $fill);
        $pdf->Cell($w[8], 6, $mermaVal > 0 ? number_format($mermaVal, 2) : '—', 1, 0, 'R', $fill);
        $pdf->Cell($w[9], 6, $line['status'], 1, 1, 'C', $fill);
        $fill = !$fill;
        $note = trim((string)($line['notes'] ?? ''));
        $bc = trim((string)($line['barcode'] ?? ''));
        if ($note !== '' || $bc !== '') {
            $pdf->SetFont('Arial', 'I', 7);
            $extra = ($bc !== '' ? ('Barcode: ' . $bc . '  ') : '') . ($note !== '' ? ('Nota: ' . $note) : '');
            $pdf->MultiCell(array_sum($w), 5, pdf_text($extra), 1, 'L', $fill);
            $pdf->SetFont('Arial', '', 7);
        }
    }
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 7, pdf_text('Merma total (faltante valorizado): ' . number_format($totalMerma, 2)), 0, 1, 'R');

    // Fotos por ítem
    $itemPhotos = array_filter($lines, static fn($l) => !empty($l['photo_path']));
    if ($itemPhotos) {
        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 7, pdf_text('Evidencias por ítem'), 0, 1);
        foreach ($itemPhotos as $line) {
            if ($pdf->GetY() > 220) {
                $pdf->AddPage();
            }
            $absItem = APP_ROOT . DIRECTORY_SEPARATOR . str_replace(
                ['/', '\\'],
                DIRECTORY_SEPARATOR,
                ltrim((string)$line['photo_path'], '/\\')
            );
            if (!is_file($absItem)) {
                continue;
            }
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(0, 6, pdf_text(($line['item_code'] ?? '') . ' — ' . ($line['item_name'] ?? '')), 0, 1);
            if (!empty($line['notes'])) {
                $pdf->SetFont('Arial', '', 8);
                $pdf->MultiCell(0, 4, pdf_text((string)$line['notes']));
            }
            $y = $pdf->GetY();
            pdf_embed_image($pdf, $absItem, 15, $y, 70);
            $pdf->SetY($y + 45);
            $pdf->Ln(2);
        }
    }

    // Evidencia fotográfica
    $photo = $audit['photo_annotated_path'] ?: $audit['photo_path'];
    if ($photo) {
        $absPhoto = APP_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($photo, '/\\'));
        if (is_file($absPhoto)) {
            $pdf->Ln(6);
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(0, 7, pdf_text('Evidencia visual (foto anotada)'), 0, 1);
            if ($pdf->GetY() > 180) {
                $pdf->AddPage();
            }
            $yBefore = $pdf->GetY();
            pdf_embed_image($pdf, $absPhoto, 15, $yBefore, 120);
            $pdf->SetY($yBefore + 70);
        }
    }

    // Firma
    $sig = $audit['signature_path'] ?? '';
    if ($sig) {
        $absSig = APP_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($sig, '/\\'));
        if (is_file($absSig)) {
            if ($pdf->GetY() > 230) {
                $pdf->AddPage();
            }
            $pdf->Ln(8);
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(0, 7, pdf_text('Firma del auditor'), 0, 1);
            $ySig = $pdf->GetY();
            pdf_embed_image($pdf, $absSig, 15, $ySig, 60);
            $pdf->SetY($ySig + 35);
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(0, 5, pdf_text($audit['auditor_name']), 0, 1);
            $pdf->Cell(0, 5, pdf_text('Fecha: ' . $audit['created_at']), 0, 1);
        }
    }

    $pdf->Ln(8);
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->MultiCell(0, 4, pdf_text(
        'Documento generado automáticamente por el Portal de Auditoría. ' .
        'Solo el resultado final se sincroniza con SAP Business One Service Layer.'
    ));

    $fileName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $audit['audit_code']) . '.pdf';
    $relPath  = 'uploads/actas/' . $fileName;
    $absPath  = UPLOAD_ACTAS . DIRECTORY_SEPARATOR . $fileName;
    $pdf->Output('F', $absPath);

    $pdo->prepare('UPDATE audits SET pdf_path = :p WHERE id = :id')
        ->execute([':p' => $relPath, ':id' => $auditId]);

    return $absPath;
}

// Endpoint: descarga PDF
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'generate_pdf.php') {
    require_once __DIR__ . '/auth.php';
    handle_preflight();
    $user = require_auth();

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_error('id es obligatorio');
    }

    try {
        $abs = generate_audit_pdf($id);
    } catch (Throwable $e) {
        json_error($e->getMessage(), 500);
    }

    $download = isset($_GET['download']) && $_GET['download'] === '1';
    if ($download) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($abs) . '"');
        header('Content-Length: ' . filesize($abs));
        readfile($abs);
        exit;
    }

    $stmt = db()->prepare('SELECT pdf_path FROM audits WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $rel = $stmt->fetchColumn();
    json_response(['ok' => true, 'pdf_path' => $rel, 'url' => '../' . $rel]);
}
