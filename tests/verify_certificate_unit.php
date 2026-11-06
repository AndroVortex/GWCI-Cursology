<?php

declare(strict_types=1);

/**
 * Verificación sin base de datos ni red del certificado y las opiniones: el escritor de PDF
 * (estructura, WinAnsi, anchos y ajuste), el diseño del certificado y las validaciones.
 *
 *   php tests/verify_certificate_unit.php
 */

use App\Models\Entities\Certificate;
use App\Services\CertificateRenderer;
use App\Services\CertificateService;
use App\Services\Exceptions\ValidationException;
use App\Services\Pdf\PdfDocument;
use App\Services\ReviewService;

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/app/view_helpers.php';

/** @return list<string>|null */
function failedFields(callable $callback): ?array
{
    $exception = thrown(ValidationException::class, $callback);

    return $exception === null ? null : array_keys($exception->fields);
}

/** Comprueba que cada entrada de la tabla xref apunte al inicio de su objeto. */
function xrefIsValid(string $pdf): bool
{
    if (preg_match('/startxref\n(\d+)\n%%EOF\n$/D', $pdf, $start) !== 1 || !str_starts_with(substr($pdf, (int) $start[1]), "xref\n")) {
        return false;
    }
    preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $entries);
    foreach ($entries[1] as $index => $offset) {
        if (!str_starts_with(substr($pdf, (int) $offset), ($index + 1) . ' 0 obj')) {
            return false;
        }
    }

    return $entries[1] !== [];
}

// -----------------------------------------------------------
Report::section('PDF en PHP puro');
$doc = new PdfDocument();
$doc->setTitle('Prueba (1)');
$doc->fillColor(37, 99, 235);
$doc->rect(10, 10, 100, 50, true, false);
$doc->line(0, 0, 100, 100, 2);
$doc->text(20, 300, 12, false, 'Ñúñez ¿Cómo? (a\\b)');
$pdf = $doc->output();
check(str_starts_with($pdf, "%PDF-1.4\n") && str_ends_with($pdf, "%%EOF\n"), 'empieza con %PDF-1.4 y termina con %%EOF');
check(xrefIsValid($pdf), 'la tabla xref apunta a cada objeto y startxref a la tabla');
check(str_contains($pdf, '/BaseFont /Helvetica /Encoding /WinAnsiEncoding') && str_contains($pdf, '/BaseFont /Helvetica-Bold'), 'Helvetica y Helvetica-Bold en WinAnsi');
check(str_contains($pdf, "(\xD1\xFA\xF1ez \xBFC\xF3mo? \\(a\\\\b\\)) Tj"), 'el texto va en WinAnsi (Ñ, ú, ñ, ¿, ó) con ( ) y \\ escapados');
check(str_contains($pdf, '0.15 0.39 0.92 rg') && str_contains($pdf, '10 10 100 50 re f') && str_contains($pdf, '2 w 0 0 m 100 100 l S'), 'colores, rectángulos y líneas');
check(str_contains($pdf, '/Title (Prueba \\(1\\))'), 'título del documento escapado');
check(preg_match('/<< \/Length (\d+) >>\nstream\n(.*?)\nendstream/s', $pdf, $stream) === 1 && (int) $stream[1] === strlen($stream[2]), '/Length coincide con el contenido');
check(PdfDocument::winAnsi('Hola 😀') === 'Hola ?', 'lo que no existe en WinAnsi queda como "?"');
check(abs(PdfDocument::textWidth('A', 10, false) - 6.67) < 0.001 && abs(PdfDocument::textWidth('A', 10, true) - 7.22) < 0.001, 'anchos de Helvetica y Helvetica-Bold');
check(abs(PdfDocument::textWidth('ñ', 10, false) - PdfDocument::textWidth('n', 10, false)) < 0.001, 'la ñ mide como la n');
[$size, $text] = PdfDocument::fit(str_repeat('Curso ', 10), 22, true, 300, 12);
check($size < 22 && $size >= 12 && PdfDocument::textWidth($text, $size, true) <= 300, 'un texto largo se reduce hasta caber');
[$size, $text] = PdfDocument::fit(str_repeat('Larguísimo ', 40), 22, true, 300, 12);
check($size === 12.0 && str_ends_with($text, '...') && PdfDocument::textWidth($text, 12, true) <= 300, 'si ni con el mínimo cabe, se recorta con "..."');
check(thrown(LogicException::class, static fn () => (new PdfDocument())->output()) !== null, 'un PDF sin páginas no se genera');

// -----------------------------------------------------------
Report::section('Diseño del certificado');
$certificate = Certificate::fromRow([
    'id' => '1', 'user_id' => '2', 'course_id' => '3', 'issued_by' => '4',
    'verification_code' => '3f9c2a1e-7b4d-4c8e-9a51-2d6f0b8e1c47', 'student_name_snapshot' => 'María José Núñez',
    'course_title_snapshot' => 'PHP desde cero', 'certifier_name_snapshot' => 'Academia Cursology',
    'completed_at_snapshot' => '2026-10-24 03:15:00.000000', 'template_snapshot' => 'cursology-v1', 'status' => 'pending',
    'document_uri' => null, 'created_at' => '2026-10-24 03:15:00.000000', 'issued_at' => null,
]);
$certificatePdf = (new CertificateRenderer())->render($certificate, 'http://localhost:8000/?view=verify&code=3f9c2a1e-7b4d-4c8e-9a51-2d6f0b8e1c47');
check(xrefIsValid($certificatePdf) && substr_count($certificatePdf, '/Type /Page ') === 1, 'una página con su xref válida');
check(str_contains($certificatePdf, '/MediaBox [0 0 841.89 595.28]'), 'A4 horizontal');
check(str_contains($certificatePdf, "(CERTIFICADO DE FINALIZACI\xD3N)") && str_contains($certificatePdf, "(Mar\xEDa Jos\xE9 N\xFA\xF1ez)"), 'título y nombre del alumno con acentos');
check(str_contains($certificatePdf, '(PHP desde cero)') && str_contains($certificatePdf, '(Academia Cursology)'), 'curso y certificador congelados');
check(str_contains($certificatePdf, '(23 de octubre de 2026)'), 'la fecha va en la hora de México (03:15 UTC del 24 = 23 de octubre)');
check(str_contains($certificatePdf, '3f9c2a1e-7b4d-4c8e-9a51-2d6f0b8e1c47') && str_contains($certificatePdf, '?view=verify&code='), 'código y dirección de verificación');
check(CertificateRenderer::TEMPLATE === 'cursology-v1', 'plantilla cursology-v1 (la misma que guarda sp_certificate_issue)');
$issueSql = (string) file_get_contents(dirname(__DIR__) . '/app/database/procedures/certificate/sp_certificate_issue.sql');
check(str_contains($issueSql, "'" . CertificateRenderer::TEMPLATE . "'"), 'el SP congela el mismo nombre de plantilla');

// -----------------------------------------------------------
Report::section('Descarga del PDF');
check(CertificateService::fileName($certificate) === 'Certificado - PHP desde cero.pdf', 'se descarga como "Certificado - <curso>.pdf"');
$odd = Certificate::fromRow(['course_title_snapshot' => 'C#: ¿qué es "async"? <1/2>'] + [
    'id' => '1', 'user_id' => '2', 'course_id' => '3', 'issued_by' => '4', 'verification_code' => '3f9c2a1e-7b4d-4c8e-9a51-2d6f0b8e1c47',
    'student_name_snapshot' => 'X', 'certifier_name_snapshot' => 'Y', 'completed_at_snapshot' => '2026-10-24 03:15:00.000000',
    'template_snapshot' => 'cursology-v1', 'status' => 'issued', 'document_uri' => 'x', 'created_at' => '2026-10-24 03:15:00.000000',
    'issued_at' => '2026-10-24 03:15:00.000000',
]);
check(CertificateService::fileName($odd) === 'Certificado - C# ¿qué es async 1 2.pdf', 'sin los caracteres que Windows no acepta (\\ / : * ? " < > |)');
$file = App\Http\JsonResponse::file('%PDF-1.4 x', 'application/pdf', 'Certificado - Programación.pdf')->withHeader('Vary', 'Origin');
check($file->body() === '%PDF-1.4 x' && $file->status === 200 && $file->header('Content-Type') === 'application/pdf', 'la respuesta es el PDF tal cual, con su tipo (y conserva los headers que se agregan después)');
check($file->header('Content-Disposition') === "attachment; filename=\"Certificado - Programaci_n.pdf\"; filename*=UTF-8''Certificado%20-%20Programaci%C3%B3n.pdf", 'Content-Disposition con el nombre .pdf (ASCII y UTF-8)');
check($file->header('Content-Length') === '10', 'Content-Length del archivo');

// -----------------------------------------------------------
Report::section('Código de verificación');
check(CertificateService::isCode('3f9c2a1e-7b4d-4c8e-9a51-2d6f0b8e1c47'), 'UUID en minúsculas');
check(!CertificateService::isCode('3F9C2A1E-7B4D-4C8E-9A51-2D6F0B8E1C47') && !CertificateService::isCode('abc') && !CertificateService::isCode("3f9c2a1e-7b4d-4c8e-9a51-2d6f0b8e1c47\n"), 'mayúsculas (se normalizan antes), basura o salto de línea no');

// -----------------------------------------------------------
Report::section('Calificación y opinión');
check(ReviewService::validateReview(['calificacion' => 5, 'opinion' => '  Muy bueno  ']) === [5, 'Muy bueno'], '5 estrellas con opinión (sin espacios de más)');
check(ReviewService::validateReview(['calificacion' => '4']) === [4, null], 'estrellas en texto y sin opinión');
check(ReviewService::validateReview(['calificacion' => 3, 'opinion' => '   ']) === [3, null], 'opinión vacía = quitarla');
foreach ([0, 6, 4.5, '4.5', null, 'cinco', true] as $bad) {
    check(failedFields(static fn () => ReviewService::validateReview(['calificacion' => $bad])) === ['calificacion'], 'calificación inválida: ' . var_export($bad, true));
}
check(failedFields(static fn () => ReviewService::validateReview(['calificacion' => 5, 'opinion' => str_repeat('a', 2001)])) === ['opinion'], 'opinión de más de 2000 caracteres');
check(ReviewService::validateReview(['calificacion' => 5, 'opinion' => str_repeat('ñ', 2000)])[1] === str_repeat('ñ', 2000), '2000 caracteres (con ñ) sí caben');
check(failedFields(static fn () => ReviewService::validateReview(['calificacion' => 5, 'opinion' => ['x']])) === ['opinion'], 'opinión que no es texto');
check(ReviewService::validateReason(['motivo' => ' Lenguaje ofensivo ']) === 'Lenguaje ofensivo', 'motivo válido');
check(failedFields(static fn () => ReviewService::validateReason(['motivo' => 'no'])) === ['motivo'] && failedFields(static fn () => ReviewService::validateReason(['motivo' => str_repeat('x', 501)])) === ['motivo'], 'motivo de menos de 3 o más de 500 caracteres');

// -----------------------------------------------------------
Report::section('Vistas');
check(format_rating('4.5', 12) === '4.5 ★ (12)' && format_rating(null, 0) === 'Sin calificaciones', 'promedio en las tarjetas');
check(star_string(4) === '★★★★☆' && star_string(0) === '☆☆☆☆☆' && star_string(9) === '★★★★★', 'estrellas llenas y vacías');
$baseJs = (string) file_get_contents(dirname(__DIR__) . '/app/views/base/js/base.js');
check(str_contains($baseJs, "form.querySelector('[data-field=\"' + name + '\"]')"), 'los errores se marcan en un [data-field] aunque el grupo no tenga controles');
$courseForm = (string) file_get_contents(dirname(__DIR__) . '/app/views/instructor/html/course-form.html');
check(str_contains($courseForm, 'data-field="categorias"') && str_contains($courseForm, 'El administrador aún no ha creado categorías'), 'Nuevo curso: aviso y error de categorías sin casillas');

Report::finish();
