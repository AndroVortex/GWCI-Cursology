<?php

declare(strict_types=1);

/**
 * Verificación de inscripción, pagos, reproductor, exámenes y "Mi aprendizaje" contra la base
 * LOCAL, con los SP instalados (app/database/procedures/install.sql).
 *
 *   php tests/verify_learning_db.php
 *
 * PayPal se reemplaza por un envío falso (no hay red): así se prueba todo el flujo de la orden y
 * la captura contra la base. La prueba real con Sandbox está en tests/verify_paypal_live.php.
 * Cada corrida deja en la base cuentas de prueba (prueba+<marca>...@cursology.test), dos
 * categorías y tres cursos "Aprendizaje <marca>" (dados de baja y desactivadas al terminar), y
 * deja las formas de pago inactivas.
 */

use App\Database\Connection;
use App\Database\ProcedureException;
use App\Database\StoredProcedures;
use App\Integrations\CloudinaryClient;
use App\Integrations\PayPalClient;
use App\Models\Entities\AppUser;
use App\Models\Enums\PurchaseStatus;
use App\Repositories\CategoryRepository;
use App\Repositories\CourseRepository;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\LearningRepository;
use App\Repositories\PaymentMethodRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\CategoryService;
use App\Services\CheckoutService;
use App\Services\CourseService;
use App\Services\CourseValidator;
use App\Services\ExamAttemptService;
use App\Services\ExamGrader;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;
use App\Services\LearningService;
use App\Services\MediaService;
use App\Services\PaymentMethodService;
use App\Services\PaymentProvider;
use App\Services\RegistrationValidator;
use App\Services\StudentService;
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
$purchaseRepository = new PurchaseRepository($procedures);
$paymentMethods = new PaymentMethodRepository($procedures);
$learning = new LearningService(new LearningRepository($procedures), $media);
$attempts = new ExamAttemptService(new ExamAttemptRepository($procedures));
$studentService = new StudentService(new StudentRepository($procedures));

// PayPal falso: crea órdenes "ORDER-<compra>" y las captura según $paypalMode.
$paypalMode = 'completed';
$paypalOrders = [];
$paypalTransport = static function (string $method, string $url, array $headers, ?string $body) use (&$paypalMode, &$paypalOrders): array {
    $path = (string) parse_url($url, PHP_URL_PATH);
    $json = static fn (int $status, array $data): array => ['status' => $status, 'body' => json_encode($data, JSON_THROW_ON_ERROR)];
    if (!str_starts_with($url, 'https://api-m.sandbox.paypal.com/')) {
        throw new RuntimeException('El cliente salió de Sandbox: ' . $url);
    }
    if ($path === '/v1/oauth2/token') {
        return $json(200, ['access_token' => 'TOKEN-PRUEBA']);
    }
    if ($method === 'POST' && $path === '/v2/checkout/orders') {
        $unit = json_decode((string) $body, true)['purchase_units'][0];
        $paypalOrders['ORDER-' . $unit['custom_id']] = $unit;

        return $json(201, ['id' => 'ORDER-' . $unit['custom_id'], 'status' => 'CREATED']);
    }
    if (preg_match('#^/v2/checkout/orders/([^/]+)/capture$#D', $path, $match) === 1) {
        $unit = $paypalOrders[$match[1]];
        $status = ['completed' => 'COMPLETED', 'mismatch' => 'COMPLETED', 'pending' => 'PENDING', 'denied' => 'DECLINED'][$paypalMode];
        $value = $paypalMode === 'mismatch' ? '1.00' : $unit['amount']['value'];

        return $json(201, ['id' => $match[1], 'status' => $status === 'COMPLETED' ? 'COMPLETED' : 'APPROVED', 'purchase_units' => [[
            'payments' => ['captures' => [[
                'id' => 'CAP-' . $match[1], 'status' => $status, 'custom_id' => $unit['custom_id'],
                'amount' => ['currency_code' => $unit['amount']['currency_code'], 'value' => $value],
            ]]],
        ]]]);
    }
    throw new RuntimeException("PayPal falso: petición inesperada $method $path");
};
$paypal = new PayPalClient('sandbox-prueba', 'secreto-prueba', $paypalTransport);
$methods = new PaymentMethodService($paymentMethods, $paypal);
$checkout = new CheckoutService($purchaseRepository, $methods, $paypal);

try {
    $procedures->call('sp_course_access', [1, 1]);
} catch (ProcedureException $exception) {
    fwrite(STDERR, 'No se pudo ejecutar sp_course_access en la base local. Revisa el .env y que MySQL esté encendido,' . PHP_EOL
        . 'e instala los SP con: SOURCE app/database/procedures/install.sql;' . PHP_EOL
        . 'Detalle: ' . ($exception->getPrevious()?->getMessage() ?? $exception->getMessage()) . PHP_EOL);
    exit(1);
}

function ruleCode(callable $callback): ?string
{
    return thrown(BusinessRuleException::class, $callback)?->errorCode;
}

/** @return list<string>|null */
function failedFields(callable $callback): ?array
{
    $exception = thrown(ValidationException::class, $callback);

    return $exception === null ? null : array_keys($exception->fields);
}

try {
    $mark = date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $account = static fn (string $role, string $suffix): array => [
        'rol' => $role, 'nombre' => 'Prueba', 'apellido_paterno' => 'Aprendizaje', 'apellido_materno' => '',
        'genero' => 'otro', 'fecha_nacimiento' => '1998-05-20', 'email' => "prueba+$mark-$suffix@cursology.test", 'password' => 'Segura#2026',
    ];
    $instructor = $auth->register($account('instructor', 'instructor'), null);
    $s1 = $auth->register($account('student', 'alumno1'), null);
    $s2 = $auth->register($account('student', 'alumno2'), null);
    $s3 = $auth->register($account('student', 'alumno3'), null);
    $admin = $users->findForLogin('admin@cursology.local') ?? throw new RuntimeException('Falta la cuenta admin@cursology.local.');
    printf('Cuentas de prueba de esta corrida: prueba+%s-{instructor,alumno1,alumno2,alumno3}@cursology.test%s%s', $mark, PHP_EOL, PHP_EOL);

    // ---------- Cursos publicados para la prueba ----------
    $catA = $categories->create($admin, ['nombre' => "Prueba $mark aprendizaje"]);
    $catB = $categories->create($admin, ['nombre' => "Prueba $mark gratis"]);
    $folder = 'cursology/courses/u' . $instructor->id();
    $video = static fn (string $name, int $seconds): VerifiedMedia => new VerifiedMedia("$folder/content/$name-$mark", 'video', 'authenticated', 'mp4', 1_000_000, $seconds, "$name.mp4", 'video/mp4', '');
    $mainInput = $validator->validateContent(['tipo' => 'video_principal', 'archivo' => []]);
    $newCourse = static function (string $title, string $mode, string $price, string $categoryId) use ($validator, $courseRepository, $instructor, $folder, $mark) {
        $input = $validator->validateCourse([
            'titulo' => "Aprendizaje $mark $title", 'descripcion' => 'Curso de prueba.', 'certificador' => '', 'precio_modo' => $mode,
            'precio' => $price, 'categorias' => [$categoryId], 'portada' => [], 'video' => [],
        ], true);

        return $courseRepository->create($instructor->id(), $input, "https://res.cloudinary.com/prueba/image/upload/v1/$folder/covers/$title.jpg", "https://res.cloudinary.com/prueba/video/upload/v1/$folder/promos/$title.mp4");
    };
    $newLevel = static fn (string $courseId, string $title, string $cost) => $courseRepository->createLevel(
        $instructor->id(), $courseId,
        $validator->validateLevel(['titulo' => $title, 'descripcion' => "Descripción de $title", 'costo' => $cost, 'video' => []], true),
        "https://res.cloudinary.com/prueba/video/upload/v1/$folder/levels/$title-$mark.mp4",
    );
    $newVideoChapter = static function (string $levelId, string $title, int $seconds) use ($courseRepository, $validator, $instructor, $mainInput, $video) {
        $chapter = $courseRepository->createChapter($instructor->id(), $levelId, $validator->validateChapter(['titulo' => $title, 'tipo' => 'video'], true));
        $courseRepository->addContent($instructor->id(), $chapter->id(), $mainInput, $title, $video(strtolower(str_replace(' ', '-', $title)), $seconds));

        return $chapter;
    };

    // A: curso completo $500 + niveles (A1 gratis con video y examen, A2 $300).
    $courseA = $newCourse('A', 'paquete', '500', $catA->id());
    $a1 = $newLevel($courseA->id(), 'A1', '0');
    $a2 = $newLevel($courseA->id(), 'A2', '300');
    $a1Video = $newVideoChapter($a1->id(), 'Bienvenida', 100);
    $exam = $courseRepository->createChapter($instructor->id(), $a1->id(), $validator->validateChapter(['titulo' => 'Examen A1', 'tipo' => 'examen', 'minimo' => '70'], true));
    [$q1, $q1Answers] = $courseRepository->createQuestion($instructor->id(), $exam->id(), $validator->validateQuestion(['pregunta' => '¿Dónde corre PHP?', 'modo' => 'unica', 'respuestas' => [
        ['texto' => 'En el servidor', 'correcta' => true], ['texto' => 'En el navegador', 'correcta' => false],
    ]]));
    [$q2, $q2Answers] = $courseRepository->createQuestion($instructor->id(), $exam->id(), $validator->validateQuestion(['pregunta' => 'Lenguajes del servidor', 'modo' => 'multiple', 'respuestas' => [
        ['texto' => 'PHP', 'correcta' => true, 'peso' => '2'], ['texto' => 'Python', 'correcta' => true, 'peso' => '3'], ['texto' => 'CSS', 'correcta' => false],
    ]]));
    $a2Video = $newVideoChapter($a2->id(), 'Funciones', 200);
    $courseRepository->addContent($instructor->id(), $a2Video->id(), $validator->validateContent(['tipo' => 'enlace', 'titulo' => 'Manual', 'url' => 'https://www.php.net/manual/es/']), 'Manual', null);
    $courseService->publish($instructor, $courseA->id());

    // B: gratis (un nivel). C: por niveles ($100, $150, $80).
    $courseB = $newCourse('B', 'gratis', '', $catB->id());
    $b1 = $newLevel($courseB->id(), 'B1', '0');
    $newVideoChapter($b1->id(), 'Hola', 30);
    $courseService->publish($instructor, $courseB->id());
    $courseC = $newCourse('C', 'niveles', '', $catA->id());
    $c1 = $newLevel($courseC->id(), 'C1', '100');
    $c2 = $newLevel($courseC->id(), 'C2', '150');
    $c3 = $newLevel($courseC->id(), 'C3', '80');
    $c1Video = $newVideoChapter($c1->id(), 'C uno', 40);
    $newVideoChapter($c2->id(), 'C dos', 40);
    $newVideoChapter($c3->id(), 'C tres', 40);
    $courseService->publish($instructor, $courseC->id());
    $draft = $newCourse('Borrador', 'gratis', '', $catB->id());

    $card = static fn (string $courseId, ?string $levelId = null, string $number = '4242 4242 4242 4242'): array => array_filter([
        'curso' => $courseId, 'nivel' => $levelId, 'titular' => 'Alumna De Prueba', 'numero' => $number, 'vencimiento' => '12/30', 'cvv' => '123',
    ], static fn (?string $value): bool => $value !== null);
    $access = static fn (?AppUser $user, string $courseId) => $checkout->access($user, $courseId);
    $purchasesOf = static fn (AppUser $user): array => $studentService->dashboard($user, [])['purchases'];

    // -----------------------------------------------------------
    Report::section('Formas de pago');
    check(ruleCode(static fn () => $methods->setActive($s1, ['codigo' => 'simulated_card', 'activa' => true])) === 'NOT_ADMIN', 'un alumno no activa formas de pago -> NOT_ADMIN');
    check(failedFields(static fn () => $methods->setActive($admin, ['codigo' => 'bitcoin', 'activa' => true])) === ['codigo'], 'una forma desconocida se rechaza');
    $methods->setActive($admin, ['codigo' => 'simulated_card', 'activa' => false]);
    check(ruleCode(static fn () => $checkout->payWithCard($s1, $card($courseA->id(), $a2->id()))) === 'PAYMENT_METHOD_UNAVAILABLE', 'tarjeta inactiva -> PAYMENT_METHOD_UNAVAILABLE');
    $methods->setActive($admin, ['codigo' => 'simulated_card', 'activa' => true]);
    $methods->setActive($admin, ['codigo' => 'paypal', 'activa' => true]);
    check($methods->available() === [PaymentProvider::PayPal, PaymentProvider::SimulatedCard], 'activas y listas: PayPal (con credenciales) y la tarjeta');
    $unconfigured = new PaymentMethodService($paymentMethods, new PayPalClient('', ''));
    check($unconfigured->available() === [PaymentProvider::SimulatedCard], 'sin credenciales de Sandbox, PayPal no se ofrece aunque esté activa');
    $noPayPal = new CheckoutService($purchaseRepository, $unconfigured, new PayPalClient('', ''));
    check(ruleCode(static fn () => $noPayPal->startPayPal($s3, ['curso' => $courseC->id(), 'nivel' => $c1->id()])) === 'PAYMENT_METHOD_UNAVAILABLE', 'y pagar con PayPal -> PAYMENT_METHOD_UNAVAILABLE');

    // -----------------------------------------------------------
    Report::section('Inscripción gratis');
    check($checkout->enrollFree($s1, ['curso' => $courseB->id()]) === 1, 'curso gratis: el alumno queda inscrito con su nivel');
    $bAccess = $access($s1, $courseB->id());
    check($bAccess->enrolled && $bAccess->ownsCourse && $bAccess->levelIds === [$b1->id()], 'con una compra de $0 por el curso completo');
    check(ruleCode(static fn () => $checkout->enrollFree($s1, ['curso' => $courseB->id()])) === 'ALREADY_OWNED', 'inscribirse otra vez -> ALREADY_OWNED');
    check(ruleCode(static fn () => $checkout->enrollFree($instructor, ['curso' => $courseB->id()])) === 'NOT_STUDENT', 'un instructor no se inscribe -> NOT_STUDENT');
    check(ruleCode(static fn () => $checkout->payWithCard($s2, $card($courseB->id()))) === 'NOT_FOR_SALE', 'un curso gratis no se cobra -> NOT_FOR_SALE');
    check($checkout->enrollFree($s1, ['curso' => $courseA->id()]) === 1, 'curso de paga con un nivel de $0: se obtiene ese nivel');
    $aAccess = $access($s1, $courseA->id());
    check($aAccess->enrolled && !$aAccess->ownsCourse && $aAccess->levelIds === [$a1->id()], 'solo el nivel gratis, sin el curso completo');
    check(ruleCode(static fn () => $checkout->enrollFree($s1, ['curso' => $courseA->id()])) === 'ALREADY_OWNED', 'pedir los gratis otra vez -> ALREADY_OWNED');
    check(ruleCode(static fn () => $checkout->enrollFree($s1, ['curso' => $courseC->id()])) === 'NOT_FREE', 'sin niveles gratis -> NOT_FREE');
    check(ruleCode(static fn () => $checkout->enrollFree($s1, ['curso' => $draft->id()])) === 'COURSE_NOT_FOUND', 'un borrador -> COURSE_NOT_FOUND');
    check(failedFields(static fn () => $checkout->enrollFree($s1, ['curso' => 'abc'])) === ['curso'], 'curso inválido -> curso');
    check(!$access($s2, $courseA->id())->enrolled && $access(null, $courseA->id())->levelIds === [], 'otro alumno y un visitante no tienen nada');

    // -----------------------------------------------------------
    Report::section('Tarjeta simulada');
    $before = count($purchasesOf($s1));
    check(failedFields(static fn () => $checkout->payWithCard($s1, $card($courseA->id(), $a2->id(), '4242 4242 4242 4241'))) === ['numero'], 'número inválido -> numero');
    check(count($purchasesOf($s1)) === $before, 'y no se abre ninguna compra');
    $a2Purchase = $checkout->payWithCard($s1, $card($courseA->id(), $a2->id()));
    check($a2Purchase->status() === PurchaseStatus::Completed && $a2Purchase->amount() === '300.00' && $a2Purchase->currency() === 'MXN', 'nivel A2 pagado: $300.00 que puso la base');
    check($a2Purchase->provider() === 'simulated_card' && preg_match('/^SIM-[0-9A-F]{12}-4242$/D', (string) $a2Purchase->providerTransactionId()) === 1, 'transacción SIM-...-4242 (solo los últimos 4 dígitos)');
    check($a2Purchase->paymentMethodNameSnapshot() === 'Tarjeta simulada' && $a2Purchase->levelTitleSnapshot() === 'A2', 'con la forma de pago y el nivel congelados');
    check($access($s1, $courseA->id())->levelIds === [$a1->id(), $a2->id()] || $access($s1, $courseA->id())->levelIds === [$a2->id(), $a1->id()], 'ya tiene A1 y A2');
    check(ruleCode(static fn () => $checkout->payWithCard($s1, $card($courseA->id(), $a2->id()))) === 'ALREADY_OWNED', 'comprar A2 otra vez -> ALREADY_OWNED');
    check(ruleCode(static fn () => $checkout->payWithCard($s1, $card($courseA->id(), $a1->id()))) === 'NOT_FOR_SALE', 'un nivel de $0 no se cobra -> NOT_FOR_SALE');
    check(ruleCode(static fn () => $checkout->payWithCard($s1, $card($courseC->id()))) === 'NOT_FOR_SALE', 'un curso por niveles no se vende completo -> NOT_FOR_SALE');
    check(ruleCode(static fn () => $checkout->payWithCard($s1, $card($courseC->id(), $a2->id()))) === 'LEVEL_NOT_FOUND', 'un nivel de otro curso -> LEVEL_NOT_FOUND');
    check(ruleCode(static fn () => $checkout->payWithCard($s1, $card($courseC->id(), $c1->id(), '4000 0000 0000 0002'))) === 'CARD_DECLINED', 'la tarjeta 4000 0000 0000 0002 -> CARD_DECLINED');
    $declined = $purchasesOf($s1)[0];
    check($declined->status() === PurchaseStatus::Failed && $declined->levelTitleSnapshot() === 'C1' && !$access($s1, $courseC->id())->enrolled, 'queda la compra rechazada y sin acceso');

    // -----------------------------------------------------------
    Report::section('PayPal (Sandbox simulado)');
    check(ruleCode(static fn () => $checkout->startPayPal($instructor, ['curso' => $courseC->id(), 'nivel' => $c1->id()])) === 'NOT_STUDENT', 'solo los alumnos compran -> NOT_STUDENT');
    ['purchase' => $pending, 'orderId' => $orderId] = $checkout->startPayPal($s3, ['curso' => $courseC->id(), 'nivel' => $c1->id()]);
    check($pending->status() === PurchaseStatus::Pending && $pending->provider() === 'paypal' && $orderId === 'ORDER-' . $pending->id() && $pending->providerTransactionId() === $orderId, 'la compra queda pendiente con la orden de PayPal');
    check($paypalOrders[$orderId]['amount'] === ['currency_code' => 'MXN', 'value' => '100.00'], 'a PayPal se le pide el importe de la base (100.00 MXN)');
    $paid = $checkout->capturePayPal($s3, $pending->id());
    check($paid->status() === PurchaseStatus::Completed && $paid->completedAt() !== null && $access($s3, $courseC->id())->levelIds === [$c1->id()], 'captura completada: acceso al nivel C1');
    check($checkout->capturePayPal($s3, $pending->id())->status() === PurchaseStatus::Completed, 'capturar otra vez no cambia nada');
    check(ruleCode(static fn () => $checkout->capturePayPal($s1, $pending->id())) === 'PURCHASE_NOT_FOUND', 'la compra de otro alumno -> PURCHASE_NOT_FOUND');

    ['purchase' => $c2Pending] = $checkout->startPayPal($s3, ['curso' => $courseC->id(), 'nivel' => $c2->id()]);
    $paypalMode = 'mismatch';
    check(ruleCode(static fn () => $checkout->capturePayPal($s3, $c2Pending->id())) === 'PAYMENT_MISMATCH', 'PayPal cobra otro importe -> PAYMENT_MISMATCH');
    check(!in_array($c2->id(), $access($s3, $courseC->id())->levelIds, true) && $purchaseRepository->get($s3->id(), $c2Pending->id())->status() === PurchaseStatus::Pending, 'sin acceso y la compra sigue pendiente');
    $paypalMode = 'pending';
    check($checkout->capturePayPal($s3, $c2Pending->id())->status() === PurchaseStatus::Pending, 'captura PENDING: la compra sigue pendiente');
    $paypalMode = 'denied';
    check(ruleCode(static fn () => $checkout->capturePayPal($s3, $c2Pending->id())) === 'PAYMENT_DECLINED', 'captura rechazada -> PAYMENT_DECLINED');
    check($purchaseRepository->get($s3->id(), $c2Pending->id())->status() === PurchaseStatus::Failed, 'y la compra queda rechazada');
    $paypalMode = 'completed';
    ['purchase' => $toCancel] = $checkout->startPayPal($s3, ['curso' => $courseC->id(), 'nivel' => $c2->id()]);
    check($checkout->cancel($s3, $toCancel->id())->status() === PurchaseStatus::Cancelled, 'cerrar la ventana de PayPal cancela la compra');
    check(ruleCode(static fn () => $checkout->capturePayPal($s3, $toCancel->id())) === 'PURCHASE_NOT_PENDING', 'una compra cancelada no se captura -> PURCHASE_NOT_PENDING');

    // -----------------------------------------------------------
    Report::section('Compras en la base: idempotencia');
    $key = "prueba-$mark-c3";
    $tx = "SIM-$mark-1";
    $otherTx = "SIM-$mark-2";
    $first = $purchaseRepository->start($s3->id(), $courseC->id(), $c3->id(), 'simulated_card', $key);
    check($purchaseRepository->start($s3->id(), $courseC->id(), $c3->id(), 'simulated_card', $key)->id() === $first->id(), 'la misma clave de idempotencia devuelve la misma compra');
    check($first->amount() === '80.00' && $first->status() === PurchaseStatus::Pending, 'el importe lo pone la base (80.00)');
    check(ruleCode(static fn () => $purchaseRepository->complete($s3->id(), $first->id(), $tx, '1.00', 'MXN')) === 'PAYMENT_MISMATCH', 'completar con otro importe -> PAYMENT_MISMATCH');
    check(ruleCode(static fn () => $purchaseRepository->complete($s3->id(), $first->id(), $tx, '80.00', 'USD')) === 'PAYMENT_MISMATCH', 'o con otra moneda -> PAYMENT_MISMATCH');
    $done = $purchaseRepository->complete($s3->id(), $first->id(), $tx, '80.00', 'MXN');
    check($done->status() === PurchaseStatus::Completed && $purchaseRepository->complete($s3->id(), $first->id(), $tx, '80.00', 'MXN')->completedAt() == $done->completedAt(), 'completar dos veces con la misma transacción no cambia nada');
    check(ruleCode(static fn () => $purchaseRepository->complete($s3->id(), $first->id(), $otherTx, '80.00', 'MXN')) === 'PURCHASE_NOT_PENDING', 'con otra transacción -> PURCHASE_NOT_PENDING');
    check(ruleCode(static fn () => $purchaseRepository->start($s3->id(), $courseC->id(), $c3->id(), 'simulated_card', "$key-otra")) === 'ALREADY_OWNED', 'una compra nueva de C3 -> ALREADY_OWNED');

    // -----------------------------------------------------------
    Report::section('Curso completo y niveles futuros');
    $bundle = $checkout->payWithCard($s2, $card($courseA->id()));
    check($bundle->amount() === '500.00' && $bundle->levelTitleSnapshot() === null && $bundle->status() === PurchaseStatus::Completed, 'curso completo A: $500.00');
    $bundleAccess = $access($s2, $courseA->id());
    check($bundleAccess->ownsCourse && count($bundleAccess->levelIds) === 2, 'acceso a todos los niveles');
    check(ruleCode(static fn () => $checkout->payWithCard($s2, $card($courseA->id()))) === 'ALREADY_OWNED' && ruleCode(static fn () => $checkout->payWithCard($s2, $card($courseA->id(), $a2->id()))) === 'ALREADY_OWNED', 'ni el curso ni sus niveles se vuelven a cobrar');
    $a3 = $newLevel($courseA->id(), 'A3', '120');
    $a3Video = $newVideoChapter($a3->id(), 'Extra', 60);
    check($access($s2, $courseA->id())->hasLevel($a3->id()), 'un nivel nuevo queda incluido para quien compró el curso completo');
    check(!$access($s1, $courseA->id())->hasLevel($a3->id()), 'pero no para quien compró niveles sueltos');

    // -----------------------------------------------------------
    Report::section('Precios con alumnos');
    $courseForm = ['titulo' => "Aprendizaje $mark A", 'descripcion' => 'Curso de prueba.', 'certificador' => '', 'categorias' => [$catA->id()], 'portada' => [], 'video' => []];
    check(ruleCode(static fn () => $courseRepository->update($instructor->id(), $courseA->id(), $validator->validateCourse(['precio_modo' => 'niveles', 'precio' => ''] + $courseForm, false), null, null)) === 'PRICING_LOCKED', 'con alumnos no cambia el modelo de cobro -> PRICING_LOCKED');
    [$repriced] = $courseRepository->update($instructor->id(), $courseA->id(), $validator->validateCourse(['precio_modo' => 'paquete', 'precio' => '550'] + $courseForm, false), null, null);
    check($repriced->fullCoursePrice() === '550.00', 'pero sí el importe del curso completo');
    check(ruleCode(static fn () => $courseService->unpublish($instructor, $courseA->id())) === 'COURSE_HAS_STUDENTS', 'ni vuelve a borrador -> COURSE_HAS_STUDENTS');

    // -----------------------------------------------------------
    Report::section('Reproductor y avance');
    check(ruleCode(static fn () => $learning->course($s3, $courseA->id())) === 'NOT_ENROLLED', 'sin inscripción -> NOT_ENROLLED');
    $view = $learning->course($s1, $courseA->id());
    check($view->title === "Aprendizaje $mark A" && count($view->levels) === 3 && $view->chapterCount() === 4, 'el alumno ve los 3 niveles y sus 4 capítulos');
    check($view->level($a1->id())['hasAccess'] && $view->level($a2->id())['hasAccess'] && !$view->level($a3->id())['hasAccess'], 'con acceso a A1 y A2, no a A3');
    check($view->mainVideo($a1Video->id())?->durationSeconds() === 100 && $view->resources($a2Video->id())[0]->title() === 'Manual', 'trae el video y los recursos de sus niveles');
    check($view->contents($a3Video->id()) === [], 'pero no el contenido de A3');
    check($view->resumeChapter()['id'] === $a1Video->id() && $view->nextChapter($a1Video->id())['id'] === $exam->id(), 'empieza por el primer capítulo; el siguiente es el examen');

    $p = $learning->progress($s1, $a1Video->id(), ['posicion' => 50]);
    check($p->positionSeconds === 50 && $p->completedAt === null, 'guarda la posición (50 s)');
    check($learning->course($s1, $courseA->id())->chapter($a1Video->id())['lastAccessedAt'] !== null, 'y el capítulo queda "en curso"');
    check($learning->progress($s1, $a1Video->id(), ['posicion' => 89])->completedAt === null, '89 de 100 s todavía no cuenta');
    $p = $learning->progress($s1, $a1Video->id(), ['posicion' => 90]);
    check($p->completedAt !== null && $p->levelCompletedAt === null, '90 de 100 s lo termina (el nivel sigue: falta el examen)');
    check($learning->progress($s1, $a1Video->id(), ['posicion' => 10])->completedAt == $p->completedAt, 'regresar el video no lo desmarca');
    check($learning->progress($s1, $a1Video->id(), ['posicion' => 5000])->positionSeconds === 100, 'la posición se recorta a la duración del video');
    check(ruleCode(static fn () => $learning->progress($s1, $exam->id(), ['terminado' => true])) === 'EXAM_NOT_MARKABLE', 'un examen no se marca como visto -> EXAM_NOT_MARKABLE');
    check(ruleCode(static fn () => $learning->progress($s1, $a3Video->id(), ['posicion' => 1])) === 'NO_LEVEL_ACCESS', 'un nivel sin acceso -> NO_LEVEL_ACCESS');
    check(ruleCode(static fn () => $learning->progress($s1, '999999999999', [])) === 'CHAPTER_NOT_FOUND', 'capítulo inexistente -> CHAPTER_NOT_FOUND');
    check(failedFields(static fn () => $learning->progress($s1, $a2Video->id(), ['posicion' => -1, 'terminado' => 'si'])) === ['posicion', 'terminado'], 'posición negativa y terminado no booleano');

    // -----------------------------------------------------------
    Report::section('Exámenes');
    check(ruleCode(static fn () => $attempts->start($s1, $a1Video->id())) === 'CHAPTER_NOT_EXAM', 'un capítulo de video no se presenta -> CHAPTER_NOT_EXAM');
    check(ruleCode(static fn () => $attempts->start($s3, $exam->id())) === 'NO_LEVEL_ACCESS', 'sin acceso al nivel -> NO_LEVEL_ACCESS');
    $attempt = $attempts->start($s1, $exam->id());
    check($attempt->number === 1 && !$attempt->isSubmitted() && $attempt->maxPoints === '6.00' && $attempt->minimumGrade === '70.00', 'intento #1 abierto: vale 6 puntos, mínimo 70');
    check(array_map(static fn (array $q): string => $q['id'], $attempt->questions) === [$q1->id(), $q2->id()] && count($attempt->questions[1]['answers']) === 3, 'con las preguntas y opciones copiadas en su orden');
    $raw = $procedures->call('sp_attempt_get', [$s1->id(), $attempt->id]);
    check(!array_key_exists('is_correct_snapshot', $raw[2][0]) && !array_key_exists('weight_snapshot', $raw[2][0]) && !array_key_exists('earned_points', $raw[1][0]), 'el SP no devuelve las claves mientras el intento está abierto');
    check($attempts->start($s1, $exam->id())->id === $attempt->id, 'abrirlo otra vez retoma el mismo intento');
    check(ruleCode(static fn () => $attempts->submit($s2, $attempt->id, ['respuestas' => []])) === 'ATTEMPT_NOT_FOUND', 'el intento de otro alumno -> ATTEMPT_NOT_FOUND');
    check(ruleCode(static fn () => $attempts->submit($s1, $attempt->id, ['respuestas' => [$q1Answers[0]->id(), $q1Answers[1]->id()]])) === 'INVALID_SELECTION', 'dos opciones en opción única -> INVALID_SELECTION');
    check(ruleCode(static fn () => $attempts->submit($s1, $attempt->id, ['respuestas' => ['999999999999']])) === 'INVALID_SELECTION', 'una opción ajena -> INVALID_SELECTION');
    check(ruleCode(static fn () => $attempts->submit($s1, $attempt->id, ['respuestas' => [$q1Answers[0]->id(), $q1Answers[0]->id()]])) === 'INVALID_SELECTION', 'una opción repetida -> INVALID_SELECTION');
    check(failedFields(static fn () => $attempts->submit($s1, $attempt->id, ['respuestas' => 'todas'])) === ['respuestas'], 'respuestas debe ser una lista de ids');

    $grader = new ExamGrader();
    $examQuestions = array_map(static fn (array $pair): array => [
        'id' => $pair[0]->id(), 'mode' => $pair[0]->selectionMode(),
        'answers' => array_map(static fn ($a): array => ['id' => $a->id(), 'correct' => $a->isCorrect(), 'weight' => $a->weight()], $pair[1]),
    ], [[$q1, $q1Answers], [$q2, $q2Answers]]);
    $tries = [
        'servidor + PHP y CSS (incorrecta) -> 1 de 6' => [[$q1Answers[0]], [$q2Answers[0], $q2Answers[2]], '1.00', '16.67', false],
        'servidor + PHP -> 3 de 6' => [[$q1Answers[0]], [$q2Answers[0]], '3.00', '50.00', false],
        'navegador (incorrecta) + PHP y Python -> 5 de 6' => [[$q1Answers[1]], [$q2Answers[0], $q2Answers[1]], '5.00', '83.33', true],
    ];
    $number = 0;
    foreach ($tries as $label => [$first, $second, $earned, $grade, $passes]) {
        $number++;
        $open = $number === 1 ? $attempt : $attempts->start($s1, $exam->id());
        $ids = array_map(static fn ($a): string => $a->id(), [...$first, ...$second]);
        $result = $attempts->submit($s1, $open->id, ['respuestas' => $ids]);
        $expected = $grader->grade($examQuestions, [$q1->id() => array_map(static fn ($a): string => $a->id(), $first), $q2->id() => array_map(static fn ($a): string => $a->id(), $second)], '70.00');
        check($open->number === $number && $result->earnedPoints === $earned && $result->grade === $grade && $result->passed === $passes, "intento #$number: $label = $grade");
        check($result->grade === $expected->grade && $result->earnedPoints === $expected->earnedPoints && $result->passed === $expected->passed, "intento #$number: la base califica igual que ExamGrader");
    }
    check($result->questions[0]['earnedPoints'] === '0.00' && $result->questions[1]['earnedPoints'] === '5.00', 'puntos por pregunta (la incorrecta deja la suya en 0)');
    check($result->questions[0]['answers'][0]['correct'] === true && $result->questions[0]['answers'][1]['selected'] === true, 'después de entregar se ven las correctas y lo marcado');
    check($result->chapterCompletedAt !== null && $result->levelCompletedAt !== null, 'aprobar termina el examen y, con el video visto, el nivel A1');
    check(ruleCode(static fn () => $attempts->submit($s1, $result->id, ['respuestas' => []])) === 'ATTEMPT_ALREADY_SUBMITTED', 'entregar otra vez -> ATTEMPT_ALREADY_SUBMITTED');
    $history = $attempts->attempts($s1, $exam->id());
    check(array_map(static fn ($a): ?string => $a->grade, $history) === ['83.33', '50.00', '16.67'] && $history[0]->passed === true && $history[2]->passed === false, 'historial del más reciente al primero');
    check($learning->course($s1, $courseA->id())->chapter($exam->id())['bestGrade'] === '83.33', 'el reproductor muestra la mejor calificación');
    $retry = $attempts->start($s1, $exam->id());
    $empty = $attempts->submit($s1, $retry->id, ['respuestas' => []]);
    check($empty->grade === '0.00' && $empty->passed === false && $empty->chapterCompletedAt !== null, 'reprobar después de aprobar no quita el examen terminado');
    check(ruleCode(static fn () => $courseRepository->updateQuestion($instructor->id(), $q1->id(), $validator->validateQuestion(['pregunta' => 'Otra', 'modo' => 'unica', 'respuestas' => [
        ['texto' => 'Sí', 'correcta' => true], ['texto' => 'No', 'correcta' => false],
    ]]))) === 'EXAM_HAS_ATTEMPTS', 'con intentos, el instructor ya no edita la pregunta -> EXAM_HAS_ATTEMPTS');
    check(ruleCode(static fn () => $courseRepository->deleteQuestion($instructor->id(), $q2->id())) === 'EXAM_HAS_ATTEMPTS', 'ni la borra -> EXAM_HAS_ATTEMPTS');

    // -----------------------------------------------------------
    Report::section('Terminado en cascada');
    $p = $learning->progress($s1, $a2Video->id(), ['posicion' => 0, 'terminado' => true]);
    check($p->completedAt !== null && $p->levelCompletedAt !== null && $p->courseCompletedAt === null, '"Marcar como visto" termina A2, pero el curso no: falta A3 (sin acceso)');
    $a3Purchase = $checkout->payWithCard($s1, $card($courseA->id(), $a3->id()));
    check($a3Purchase->amount() === '120.00', 'compra el nivel nuevo A3');
    $p = $learning->progress($s1, $a3Video->id(), ['posicion' => 60]);
    check($p->levelCompletedAt !== null && $p->courseCompletedAt !== null, 'al terminar A3, el nivel y el curso quedan terminados');
    $finished = $learning->course($s1, $courseA->id());
    check($finished->completedAt !== null && $finished->progressPercent() === 100 && $finished->completedChapterCount() === 4, 'el reproductor lo muestra terminado al 100%');
    $b = $learning->progress($s1, $learning->course($s1, $courseB->id())->chapters()[0]['id'], ['posicion' => 10]);
    check($b->completedAt === null && $b->positionSeconds === 10, 'en el curso gratis va a la mitad');

    // -----------------------------------------------------------
    Report::section('Mi aprendizaje: kardex y compras');
    $dash = $studentService->dashboard($s1, []);
    check($dash['stats'] === ['enrolled' => 2, 'inProgress' => 1, 'completed' => 1], 'cifras: 2 inscritos, 1 en progreso, 1 terminado');
    check(array_map(static fn ($e): string => $e->courseId, $dash['continue']) === [$courseB->id()], '"Continuar aprendiendo" solo trae el que va en progreso');
    $byCourse = [];
    foreach ($dash['kardex'] as $entry) {
        $byCourse[$entry->courseId] = $entry;
    }
    $kA = $byCourse[$courseA->id()];
    $kB = $byCourse[$courseB->id()];
    check($kA->isCompleted() && $kA->progressPercent() === 100 && $kA->levelsOwned === 3 && $kA->levelCount === 3 && $kA->categories === ["Prueba $mark aprendizaje"], 'A: terminado, 100%, 3 de 3 niveles y su categoría');
    check(!$kB->isCompleted() && $kB->progressPercent() === 0 && $kB->lastChapterTitle === 'Hola' && $kB->lastAccessedAt !== null, 'B: en progreso, con su último capítulo y último acceso');
    $kardex = static fn (array $filters): array => array_map(static fn ($e): string => $e->courseId, $studentService->dashboard($s1, $filters)['kardex']);
    check($kardex(['estado' => 'terminados']) === [$courseA->id()] && $kardex(['estado' => 'en_progreso']) === [$courseB->id()], 'filtro por estado');
    check($kardex(['categoria' => $catB->id()]) === [$courseB->id()], 'filtro por categoría');
    $today = date('Y-m-d');
    check(count($kardex(['desde' => $today, 'hasta' => $today])) === 2, 'inscritos hoy: los dos');
    check($kardex(['desde' => date('Y-m-d', strtotime('+1 day'))]) === [] && $kardex(['hasta' => date('Y-m-d', strtotime('-1 day'))]) === [], 'fuera del rango de fechas: ninguno');
    check(ruleCode(static fn () => (new StudentRepository($procedures))->courses($s1->id(), null, null, null, 'raro')) === 'INVALID_KARDEX_STATUS', 'el SP rechaza un estado desconocido -> INVALID_KARDEX_STATUS');
    $purchases = $dash['purchases'];
    $summary = array_map(static fn ($p): string => sprintf('%s|%s|%s', $p->levelTitleSnapshot() ?? 'curso', $p->amount(), $p->status()->value), $purchases);
    check($summary === ['A3|120.00|completed', 'C1|100.00|failed', 'A2|300.00|completed', 'A1|0.00|completed', 'curso|0.00|completed'], 'mis compras: de la más reciente a la primera, incluidas las gratis y la rechazada');
    check($purchases[0]->paymentMethodNameSnapshot() === 'Tarjeta simulada' && $purchases[3]->paymentMethodNameSnapshot() === null, 'con su forma de pago (las gratis no tienen)');

    // -----------------------------------------------------------
    Report::section('Curso dado de baja');
    $courseService->setActive($instructor, $courseA->id(), ['activo' => false]);
    check(ruleCode(static fn () => $checkout->payWithCard($s3, $card($courseA->id()))) === 'COURSE_NOT_FOUND', 'ya no se vende -> COURSE_NOT_FOUND');
    check(ruleCode(static fn () => $checkout->enrollFree($s3, ['curso' => $courseA->id()])) === 'COURSE_NOT_FOUND', 'ni se inscribe nadie nuevo');
    $inactive = $learning->course($s2, $courseA->id());
    check(!$inactive->active && $inactive->level($a3->id())['hasAccess'], 'quien lo compró conserva el acceso');
    check($learning->progress($s2, $a2Video->id(), ['posicion' => 30])->positionSeconds === 30, 'y sigue guardando su avance');
    check($kardex(['estado' => 'activos']) === [$courseB->id()], 'el kardex puede filtrar solo los cursos activos');

    // Limpieza: cursos dados de baja, categorías y formas de pago inactivas.
    $courseService->setActive($instructor, $courseB->id(), ['activo' => false]);
    $courseService->setActive($instructor, $courseC->id(), ['activo' => false]);
    $categories->update($admin, $catA->id(), ['activa' => false]);
    $categories->update($admin, $catB->id(), ['activa' => false]);
    $methods->setActive($admin, ['codigo' => 'paypal', 'activa' => false]);
    $methods->setActive($admin, ['codigo' => 'simulated_card', 'activa' => false]);
    check($methods->available() === [], 'al terminar, las formas de pago quedan inactivas');
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf('Error inesperado: %s: %s (%s:%d)%s', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine(), PHP_EOL));
    if ($exception->getPrevious() !== null) {
        fwrite(STDERR, 'Causa: ' . $exception->getPrevious()->getMessage() . PHP_EOL);
    }
    exit(1);
}

Report::finish();
