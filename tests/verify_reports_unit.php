<?php

declare(strict_types=1);

/**
 * Verificación sin base de datos de los reportes: filtros de ventas, lectura de las filas de
 * los SP (importes como texto, formas de pago) y que no queden avisos de vista previa viejos.
 *
 *   php tests/verify_reports_unit.php
 */

use App\Models\Dto\CourseSalesRow;
use App\Models\Dto\CourseStudentRow;
use App\Models\Dto\RecentReview;
use App\Services\ReportService;

require __DIR__ . '/support.php';

// -----------------------------------------------------------
Report::section('Filtros de ventas');
check(ReportService::salesFilters([]) === ['desde' => '', 'hasta' => '', 'categoria' => '', 'estado' => ''], 'sin filtros');
check(ReportService::salesFilters(['desde' => '2026-10-01', 'hasta' => '2026-10-31', 'categoria' => '7', 'estado' => 'activos'])
    === ['desde' => '2026-10-01', 'hasta' => '2026-10-31', 'categoria' => '7', 'estado' => 'activos'], 'filtros válidos se conservan');
check(ReportService::salesFilters(['desde' => '2026-10-31', 'hasta' => '2026-10-01'])['desde'] === '2026-10-01', 'fechas al revés se acomodan');
check(ReportService::salesFilters(['desde' => '2026-02-30', 'hasta' => '31/10/2026', 'categoria' => '0', 'estado' => 'terminados'])
    === ['desde' => '', 'hasta' => '', 'categoria' => '', 'estado' => ''], 'fechas imposibles, categoría 0 y estados desconocidos se ignoran');
check(ReportService::salesFilters(['categoria' => ['7'], 'estado' => ['activos']])['categoria'] === '', 'arreglos en lugar de texto se ignoran');
check(ReportService::RECENT_REVIEWS === 5, 'el panel muestra las últimas 5 opiniones');

// -----------------------------------------------------------
Report::section('Filas de los SP');
$sales = CourseSalesRow::fromRow([
    'id' => '9', 'title' => 'PHP', 'cover_image' => 'https://x/c.jpg', 'active' => 1, 'created_at' => '2026-10-21 10:00:00.000000',
    'currency' => 'MXN', 'student_count' => 3, 'avg_progress' => '33.3', 'revenue' => '1249.90', 'rating_avg' => null, 'rating_count' => 0,
]);
check($sales->revenue === '1249.90' && $sales->avgProgress === '33.3' && $sales->ratingAvg === null && $sales->active, 'importes y avance como texto exacto (sin float)');
$student = static fn (?string $methods): CourseStudentRow => CourseStudentRow::fromRow([
    'user_id' => '5', 'full_name' => 'Ana López', 'email' => 'ana@example.com', 'avatar' => 'img/a.svg',
    'enrolled_at' => '2026-10-21 10:00:00.000000', 'completed_at' => null, 'progress' => '50.0', 'paid' => '150.00', 'payment_methods' => $methods,
]);
check($student('PayPal (Sandbox)|Tarjeta simulada')->paymentMethods === ['PayPal (Sandbox)', 'Tarjeta simulada'], 'formas de pago separadas por |');
check($student(null)->paymentMethods === [] && $student('')->paymentMethods === [], 'sin formas de pago = gratis');
$review = static fn (?string $text): RecentReview => RecentReview::fromRow([
    'id' => '1', 'course_id' => '9', 'course_title' => 'PHP', 'opinion' => $text, 'rate' => 4, 'author_name' => 'Ana',
    'author_avatar' => 'img/a.svg', 'commented_at' => '2026-10-21 10:00:00.000000', 'updated_at' => '2026-10-21 10:00:00.000000',
    'active' => 0, 'reason' => 'Spam',
]);
check($review("Muy   bueno\n\ny claro")->excerpt() === 'Muy bueno y claro', 'el extracto junta espacios y saltos de línea');
$long = $review(str_repeat('palabra ', 40))->excerpt(40);
check(mb_strlen($long) <= 40 && str_ends_with($long, '…'), 'y recorta los textos largos con "…"');
check($review(null)->excerpt() === '' && !$review('x')->active && $review('x')->reason === 'Spam', 'opinión sin texto y opinión oculta con su motivo');

// -----------------------------------------------------------
Report::section('Vistas sin avisos viejos');
$views = dirname(__DIR__) . '/app/views';
check(!is_file("$views/base/html/preview-notice.html"), 'ya no existe el aviso de vista previa');
foreach (['instructor/html/dashboard.html', 'admin/html/dashboard.html', 'instructor/html/course-editor.html'] as $file) {
    $html = (string) file_get_contents("$views/$file");
    check(!str_contains($html, 'preview-notice') && !str_contains($html, 'siguiente entrega'), "$file sin avisos de \"siguiente entrega\"");
}
$instructorPanel = (string) file_get_contents("$views/instructor/html/dashboard.html");
check(!str_contains($instructorPanel, '<fieldset class="filters" disabled>') && str_contains($instructorPanel, 'name="estado"'), 'los filtros de ventas ya funcionan');

Report::finish();
