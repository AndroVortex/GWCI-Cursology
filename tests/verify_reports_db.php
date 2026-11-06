<?php

declare(strict_types=1);

/**
 * Verificación de los reportes de los paneles del instructor y del admin contra la base LOCAL,
 * con los SP instalados (app/database/procedures/install.sql).
 *
 *   php tests/verify_reports_db.php
 *
 * PayPal se reemplaza por un envío falso (no hay red). Cada corrida deja cuentas de prueba
 * (prueba+<marca>...@cursology.test), dos categorías y tres cursos "Reportes <marca>" (dados de
 * baja y desactivadas al terminar). Las formas de pago vuelven a quedar como estaban.
 */

use App\Database\Connection;
use App\Database\ProcedureException;
use App\Database\StoredProcedures;
use App\Integrations\CloudinaryClient;
use App\Integrations\PayPalClient;
use App\Models\Entities\AppUser;
use App\Repositories\CategoryRepository;
use App\Repositories\CourseRepository;
use App\Repositories\LearningRepository;
use App\Repositories\PaymentMethodRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\ReportRepository;
use App\Repositories\ReviewRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\CategoryService;
use App\Services\CheckoutService;
use App\Services\CourseService;
use App\Services\CourseValidator;
use App\Services\Exceptions\AccountLockedException;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\InvalidCredentialsException;
use App\Services\LearningService;
use App\Services\MediaService;
use App\Services\PaymentMethodService;
use App\Services\RegistrationValidator;
use App\Services\ReportService;
use App\Services\ReviewService;
use App\Services\VerifiedMedia;

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/app/database/conn.php';

$procedures = new StoredProcedures(new Connection($DATABASES['local']));
$users = new UserRepository($procedures);
$noCloudinary = new CloudinaryClient('', '', '');
$auth = new AuthService($users, new RegistrationValidator(), $noCloudinary);
$categories = new CategoryService(new CategoryRepository($procedures));
$courseRepository = new CourseRepository($procedures);
$validator = new CourseValidator();
$media = new MediaService($noCloudinary);
$courseService = new CourseService($courseRepository, $validator, $media);
$learning = new LearningService(new LearningRepository($procedures), $media);
$reviews = new ReviewService(new ReviewRepository($procedures));
$reports = new ReportService(new ReportRepository($procedures));

// PayPal falso: crea "ORDER-<compra>" y la captura completa por el importe pedido.
$paypalOrders = [];
$paypal = new PayPalClient('sandbox-prueba', 'secreto-prueba', static function (string $method, string $url, array $headers, ?string $body) use (&$paypalOrders): array {
    $path = (string) parse_url($url, PHP_URL_PATH);
    $json = static fn (int $status, array $data): array => ['status' => $status, 'body' => json_encode($data, JSON_THROW_ON_ERROR)];
    if ($path === '/v1/oauth2/token') {
        return $json(200, ['access_token' => 'TOKEN-PRUEBA']);
    }
    if ($method === 'POST' && $path === '/v2/checkout/orders') {
        $unit = json_decode((string) $body, true)['purchase_units'][0];
        $paypalOrders['ORDER-' . $unit['custom_id']] = $unit;

        return $json(201, ['id' => 'ORDER-' . $unit['custom_id'], 'status' => 'PAYER_ACTION_REQUIRED']);
    }
    if (preg_match('#^/v2/checkout/orders/([^/]+)/capture$#D', $path, $match) === 1) {
        $unit = $paypalOrders[$match[1]];

        return $json(201, ['id' => $match[1], 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [[
            'id' => 'CAP-' . $match[1], 'status' => 'COMPLETED', 'custom_id' => $unit['custom_id'],
            'amount' => ['currency_code' => $unit['amount']['currency_code'], 'value' => $unit['amount']['value']],
        ]]]]]]);
    }
    throw new RuntimeException("PayPal falso: petición inesperada $method $path");
});
$methods = new PaymentMethodService(new PaymentMethodRepository($procedures), $paypal);
$checkout = new CheckoutService(new PurchaseRepository($procedures), $methods, $paypal);

try {
    $procedures->call('sp_admin_overview', [0]);
} catch (ProcedureException $exception) {
    if ($exception->businessCode !== 'NOT_ADMIN') {
        fwrite(STDERR, 'No se pudieron ejecutar los SP de reportes en la base local. Revisa el .env y que MySQL esté encendido,' . PHP_EOL
            . 'e instala los SP con: SOURCE app/database/procedures/install.sql;' . PHP_EOL
            . 'Detalle: ' . ($exception->getPrevious()?->getMessage() ?? $exception->getMessage()) . PHP_EOL);
        exit(1);
    }
}

function ruleCode(callable $callback): ?string
{
    return thrown(BusinessRuleException::class, $callback)?->errorCode;
}

/** @template T @param list<T> $rows @return ?T */
function rowWithId(array $rows, string $id, string $property = 'id'): mixed
{
    foreach ($rows as $row) {
        if ($row->{$property} === $id) {
            return $row;
        }
    }

    return null;
}

try {
    $mark = date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $account = static fn (string $role, string $suffix, string $name): array => [
        'rol' => $role, 'nombre' => $name, 'apellido_paterno' => 'Reportes', 'apellido_materno' => '',
        'genero' => 'otro', 'fecha_nacimiento' => '1997-03-14', 'email' => "prueba+$mark-$suffix@cursology.test", 'password' => 'Segura#2026',
    ];
    $admin = $users->findForLogin('admin@cursology.local') ?? throw new RuntimeException('Falta la cuenta admin@cursology.local.');
    restorePaymentMethodsAtExit($methods, $admin);
    $methods->setActive($admin, ['codigo' => 'simulated_card', 'activa' => true]);
    $methods->setActive($admin, ['codigo' => 'paypal', 'activa' => true]);

    $adminBefore = $reports->adminDashboard($admin)['overview'];
    $instructor = $auth->register($account('instructor', 'instructor', 'Irene'), null);
    $otherInstructor = $auth->register($account('instructor', 'otro', 'Omar'), null);
    $sa = $auth->register($account('student', 'alumna-a', 'Ana'), null);
    $sb = $auth->register($account('student', 'alumno-b', 'Beto'), null);
    $sc = $auth->register($account('student', 'alumna-c', 'Carla'), null);
    printf('Cuentas de prueba de esta corrida: prueba+%s-{instructor,otro,alumna-a,alumno-b,alumna-c}@cursology.test%s%s', $mark, PHP_EOL, PHP_EOL);

    // ---------- Cursos: X por niveles (A1 gratis + A2 $150, 2 capítulos cada uno), Y gratis, Z borrador ----------
    $catX = $categories->create($admin, ['nombre' => "Prueba $mark ventas"]);
    $catY = $categories->create($admin, ['nombre' => "Prueba $mark gratis"]);
    $folder = 'cursology/courses/u' . $instructor->id();
    $mainInput = $validator->validateContent(['tipo' => 'video_principal', 'archivo' => []]);
    $newCourse = static fn (string $title, string $mode, string $categoryId) => $courseRepository->create($instructor->id(), $validator->validateCourse([
        'titulo' => "Reportes $mark $title", 'descripcion' => 'Curso de prueba.', 'certificador' => '', 'precio_modo' => $mode,
        'precio' => '', 'categorias' => [$categoryId], 'portada' => [], 'video' => [],
    ], true), "https://res.cloudinary.com/prueba/image/upload/v1/$folder/covers/$title.jpg", "https://res.cloudinary.com/prueba/video/upload/v1/$folder/promos/$title.mp4");
    $newLevel = static fn (string $courseId, string $title, string $cost) => $courseRepository->createLevel($instructor->id(), $courseId, $validator->validateLevel(['titulo' => $title, 'descripcion' => "Nivel $title.", 'costo' => $cost, 'video' => []], true), "https://res.cloudinary.com/prueba/video/upload/v1/$folder/levels/$title-$mark.mp4");
    $newChapter = static function (string $levelId, string $title) use ($courseRepository, $validator, $instructor, $mainInput, $folder, $mark) {
        $chapter = $courseRepository->createChapter($instructor->id(), $levelId, $validator->validateChapter(['titulo' => $title, 'tipo' => 'video'], true));
        $courseRepository->addContent($instructor->id(), $chapter->id(), $mainInput, $title, new VerifiedMedia("$folder/content/$title-$mark", 'video', 'authenticated', 'mp4', 1000, 60, 'v.mp4', 'video/mp4', ''));

        return $chapter;
    };
    $courseX = $newCourse('X', 'niveles', $catX->id());
    $x1 = $newLevel($courseX->id(), 'X1', '0');
    $x2 = $newLevel($courseX->id(), 'X2', '150');
    $xChapters = [$newChapter($x1->id(), 'x1a'), $newChapter($x1->id(), 'x1b'), $newChapter($x2->id(), 'x2a'), $newChapter($x2->id(), 'x2b')];
    $courseService->publish($instructor, $courseX->id());
    $courseY = $newCourse('Y', 'gratis', $catY->id());
    $y1 = $newLevel($courseY->id(), 'Y1', '0');
    $yChapter = $newChapter($y1->id(), 'y1a');
    $courseService->publish($instructor, $courseY->id());
    $courseZ = $newCourse('Z', 'gratis', $catY->id());

    // Ana: X1 gratis + X2 con tarjeta, 2 de 4 capítulos; Y gratis y terminado.
    $checkout->enrollFree($sa, ['curso' => $courseX->id()]);
    $checkout->payWithCard($sa, ['curso' => $courseX->id(), 'nivel' => $x2->id(), 'titular' => 'Ana', 'numero' => '4242 4242 4242 4242', 'vencimiento' => '12/30', 'cvv' => '123']);
    $done = static fn (AppUser $student, $chapter) => $learning->progress($student, $chapter->id(), ['terminado' => true]);
    $done($sa, $xChapters[0]);
    $done($sa, $xChapters[1]);
    $checkout->enrollFree($sa, ['curso' => $courseY->id()]);
    $done($sa, $yChapter);
    // Beto: X1 gratis + X2 con PayPal, los 4 capítulos (termina X).
    $checkout->enrollFree($sb, ['curso' => $courseX->id()]);
    ['purchase' => $pending] = $checkout->startPayPal($sb, ['curso' => $courseX->id(), 'nivel' => $x2->id()]);
    $checkout->capturePayPal($sb, $pending->id());
    foreach ($xChapters as $chapter) {
        $done($sb, $chapter);
    }
    // Carla: solo X1 gratis, sin avance.
    $checkout->enrollFree($sc, ['curso' => $courseX->id()]);
    // Opiniones: Beto 5 en X; Ana 3 en Y (después la oculta la instructora).
    $reviews->save($sb, $courseX->id(), ['calificacion' => 5, 'opinion' => 'Excelente curso, muy claro.']);
    usleep(20000);
    $reviews->save($sa, $courseY->id(), ['calificacion' => 3, 'opinion' => 'Regular.']);

    // -----------------------------------------------------------
    Report::section('Ventas del instructor');
    check(ruleCode(static fn () => $reports->instructorDashboard($sa, [])) === 'NOT_INSTRUCTOR', 'un alumno no ve reportes de ventas -> NOT_INSTRUCTOR');
    $dash = $reports->instructorDashboard($instructor, []);
    check(array_map(static fn ($row): string => $row->id, $dash['sales']) === [$courseY->id(), $courseX->id()], 'solo los cursos publicados (sin el borrador), del más reciente al primero');
    $rowX = rowWithId($dash['sales'], $courseX->id());
    $rowY = rowWithId($dash['sales'], $courseY->id());
    check($rowX->studentCount === 3 && $rowX->avgProgress === '50.0', 'X: 3 alumnos con avance 50%, 100% y 0% -> promedio 50.0');
    check($rowX->revenue === '300.00' && $rowX->ratingAvg === '5.0' && $rowX->ratingCount === 1, 'X: $150 con tarjeta + $150 con PayPal = 300.00 y 5.0 estrellas');
    check($rowY->studentCount === 1 && $rowY->avgProgress === '100.0' && $rowY->revenue === '0.00', 'Y: curso gratis terminado, sin ingresos');
    check(array_map(static fn ($m): string => "$m->methodName|$m->purchases|$m->total", $dash['byMethod']) === ['PayPal (Sandbox)|1|150.00', 'Tarjeta simulada|1|150.00'], 'total por forma de pago (las inscripciones de $0 no cuentan)');
    check($dash['revenueTotal'] === '300.00', 'total general 300.00');
    check($dash['overview']->revenueTotal === '300.00' && $dash['overview']->studentTotal === 4, 'cifras: ingresos 300.00 e inscripciones 4');
    check($dash['overview']->ratingAvg === '4.0' && $dash['overview']->ratingCount === 2, 'calificación promedio (5 + 3) / 2 = 4.0');

    $ids = static fn (array $query): array => array_map(static fn ($row): string => $row->id, $reports->instructorDashboard($instructor, $query)['sales']);
    check($ids(['categoria' => $catX->id()]) === [$courseX->id()], 'filtro por categoría');
    check($reports->instructorDashboard($instructor, ['categoria' => $catY->id()])['byMethod'] === [], 'el total por forma de pago también se filtra');
    $today = date('Y-m-d');
    check(count($ids(['desde' => $today, 'hasta' => $today])) === 2, 'creados hoy: los dos');
    check($ids(['desde' => date('Y-m-d', strtotime('+1 day'))]) === [] && $ids(['hasta' => date('Y-m-d', strtotime('-1 day'))]) === [], 'fuera del rango de fechas: ninguno');
    check($reports->instructorDashboard($instructor, ['desde' => '2026-02-30', 'estado' => 'raro'])['filters'] === ['desde' => '', 'hasta' => '', 'categoria' => '', 'estado' => ''], 'los filtros inválidos se ignoran');

    // -----------------------------------------------------------
    Report::section('Detalle de un curso');
    check(ruleCode(static fn () => $reports->courseStudents($otherInstructor, $courseX->id())) === 'COURSE_NOT_FOUND', 'otro instructor -> COURSE_NOT_FOUND');
    check(ruleCode(static fn () => $reports->courseStudents($instructor, 'abc')) === 'COURSE_NOT_FOUND', 'id inválido -> COURSE_NOT_FOUND');
    $detail = $reports->courseStudents($instructor, $courseX->id());
    check($detail->chapterCount === 4 && $detail->studentCount === 3 && $detail->avgProgress === '50.0' && $detail->revenue === '300.00', 'resumen del curso: 4 capítulos, 3 alumnos, 50.0% y 300.00');
    $ana = rowWithId($detail->students, $sa->id(), 'userId');
    $beto = rowWithId($detail->students, $sb->id(), 'userId');
    $carla = rowWithId($detail->students, $sc->id(), 'userId');
    check($ana->progress === '50.0' && $ana->paid === '150.00' && $ana->paymentMethods === ['Tarjeta simulada'] && $ana->completedAt === null, 'Ana: 50.0%, pagó 150.00 con tarjeta, sin terminar');
    check($beto->progress === '100.0' && $beto->paid === '150.00' && $beto->paymentMethods === ['PayPal (Sandbox)'] && $beto->completedAt !== null, 'Beto: 100.0%, pagó 150.00 con PayPal, terminó');
    check($carla->progress === '0.0' && $carla->paid === '0.00' && $carla->paymentMethods === [], 'Carla: 0.0%, gratis');
    check($detail->students[0]->userId === $sc->id() && $ana->fullName === 'Ana Reportes' && $ana->email === $sa->email(), 'del inscrito más reciente al primero, con nombre y email');
    $draftDetail = $reports->courseStudents($instructor, $courseZ->id());
    check($draftDetail->publishedAt === null && $draftDetail->students === [] && $draftDetail->avgProgress === '0.0', 'un borrador: sin alumnos y avance 0');

    // -----------------------------------------------------------
    Report::section('Opiniones recientes');
    $yOpinion = $reviews->mine($sa, $courseY->id())?->opinion;
    $reviews->hide($instructor, (string) $yOpinion?->id(), ['motivo' => 'Prueba de reportes']);
    $recent = $reports->instructorDashboard($instructor, [])['recentReviews'];
    check(array_map(static fn ($r): string => $r->courseId, $recent) === [$courseY->id(), $courseX->id()], 'las dos opiniones, de la más reciente a la primera');
    check(!$recent[0]->active && $recent[0]->reason === 'Prueba de reportes' && $recent[0]->rate === 3, 'la oculta aparece marcada, con su motivo');
    check($recent[1]->active && $recent[1]->authorName === 'Beto Reportes' && $recent[1]->excerpt() === 'Excelente curso, muy claro.', 'la visible con su autor y su texto');
    check($reports->instructorDashboard($instructor, [])['overview']->ratingAvg === '5.0', 'con la opinión oculta, sus estrellas ya no cuentan en el promedio');
    check($reports->instructorDashboard($otherInstructor, [])['recentReviews'] === [] && $reports->instructorDashboard($otherInstructor, [])['sales'] === [], 'otro instructor no ve ventas ni opiniones ajenas');

    // -----------------------------------------------------------
    Report::section('Solo cursos activos');
    $courseService->setActive($instructor, $courseY->id(), ['activo' => false]);
    check($ids(['estado' => 'activos']) === [$courseX->id()] && count($ids([])) === 2, '"solo activos" quita el curso dado de baja; "todos" lo conserva');

    // -----------------------------------------------------------
    Report::section('Panel del admin');
    check(ruleCode(static fn () => $reports->adminDashboard($instructor)) === 'NOT_ADMIN', 'un instructor no ve los reportes del admin -> NOT_ADMIN');
    $adminDash = $reports->adminDashboard($admin);
    $after = $adminDash['overview'];
    check($after->activeStudents === $adminBefore->activeStudents + 3 && $after->activeInstructors === $adminBefore->activeInstructors + 2, 'cifras: +3 alumnos y +2 instructores activos');
    check($after->publishedCourses === $adminBefore->publishedCourses + 1, 'cursos publicados: +1 (X; Y está dado de baja y Z es borrador)');
    $irene = rowWithId($adminDash['instructors'], $instructor->id());
    check($irene !== null && $irene->publishedCourses === 1 && $irene->earnings === '300.00' && $irene->fullName === 'Irene Reportes', 'reporte de instructores: 1 curso publicado activo y 300.00 de ganancias');
    check(rowWithId($adminDash['instructors'], $otherInstructor->id())?->earnings === '0.00', 'un instructor sin ventas: 0.00');
    $anaRow = rowWithId($adminDash['students'], $sa->id());
    check($anaRow !== null && $anaRow->enrolledCourses === 2 && $anaRow->completedCourses === 1 && $anaRow->completedPercent === '50.0', 'reporte de alumnos: Ana con 2 cursos, 1 terminado = 50.0%');
    check(rowWithId($adminDash['students'], $sb->id())?->completedPercent === '100.0' && rowWithId($adminDash['students'], $sc->id())?->completedPercent === '0.0', 'Beto 100.0% y Carla 0.0%');
    check($adminDash['students'][0]->id === $sc->id(), 'de la cuenta más reciente a la más antigua');

    thrown(InvalidCredentialsException::class, static fn () => $auth->login($sc->email(), 'Mala#2026'));
    thrown(InvalidCredentialsException::class, static fn () => $auth->login($sc->email(), 'Mala#2026'));
    thrown(AccountLockedException::class, static fn () => $auth->login($sc->email(), 'Mala#2026'));
    $locked = $reports->adminDashboard($admin);
    check(rowWithId($locked['students'], $sc->id()) === null && $locked['overview']->activeStudents === $after->activeStudents - 1, 'una cuenta bloqueada no aparece en el reporte ni en las cifras');
    $users->unlock($admin->id(), $sc->id(), 'Fin de la prueba de reportes');

    // Limpieza: cursos de prueba dados de baja y sus categorías desactivadas.
    $courseService->setActive($instructor, $courseX->id(), ['activo' => false]);
    $categories->update($admin, $catX->id(), ['activa' => false]);
    $categories->update($admin, $catY->id(), ['activa' => false]);
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf('Error inesperado: %s: %s (%s:%d)%s', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine(), PHP_EOL));
    if ($exception->getPrevious() !== null) {
        fwrite(STDERR, 'Causa: ' . $exception->getPrevious()->getMessage() . PHP_EOL);
    }
    exit(1);
}

Report::finish();
