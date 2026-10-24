<?php

declare(strict_types=1);

/**
 * Verificación sin base de datos ni red de la inscripción, pagos y aprendizaje:
 * tarjeta simulada, cliente de PayPal (con un envío falso), umbral del 90%, claves ocultas
 * de los exámenes y filtros del kardex.
 *
 *   php tests/verify_learning_unit.php
 */

use App\Controllers\ExamAttemptController;
use App\Integrations\PaymentProviderException;
use App\Integrations\PayPalClient;
use App\Models\Dto\ExamAttempt;
use App\Services\CardValidator;
use App\Services\Exceptions\ValidationException;
use App\Services\LearningService;
use App\Services\PaymentProvider;
use App\Services\StudentService;

require __DIR__ . '/support.php';

/** @return list<string>|null */
function failedFields(callable $callback): ?array
{
    $exception = thrown(ValidationException::class, $callback);

    return $exception === null ? null : array_keys($exception->fields);
}

/**
 * PayPal falso: responde según el método y la ruta, y guarda cada petición.
 *
 * @param array<string, list<array{status: int, body: array<string, mixed>}>> $responses "MÉTODO ruta" => respuestas en orden
 * @param list<array{method: string, url: string, headers: list<string>, body: ?string}> $log
 */
function fakePayPal(array $responses, array &$log, string $id = 'sandbox-id', string $secret = 'sandbox-secret'): PayPalClient
{
    return new PayPalClient($id, $secret, static function (string $method, string $url, array $headers, ?string $body) use (&$responses, &$log): array {
        $log[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $key = $method . ' ' . parse_url($url, PHP_URL_PATH);
        if (($responses[$key] ?? []) === []) {
            throw new RuntimeException("Petición inesperada: $key");
        }
        $next = array_shift($responses[$key]);

        return ['status' => $next['status'], 'body' => json_encode($next['body'], JSON_THROW_ON_ERROR)];
    });
}

$today = new DateTimeImmutable('2026-10-21', new DateTimeZone('America/Monterrey'));
$cards = new CardValidator();
$card = static fn (array $override = []): array => $override + ['titular' => 'Alumna De Prueba', 'numero' => '4242 4242 4242 4242', 'vencimiento' => '12/28', 'cvv' => '123'];

// -----------------------------------------------------------
Report::section('Tarjeta simulada');
$valid = $cards->validate($card(), $today);
check($valid === ['last4' => '4242', 'brand' => 'Visa', 'declined' => false], 'Visa de prueba válida: solo regresa los últimos 4 dígitos');
check($cards->validate($card(['numero' => '4242-4242-4242-4242']), $today)['last4'] === '4242', 'acepta espacios o guiones en el número');
check($cards->validate($card(['numero' => '5555 5555 5555 4444']), $today)['brand'] === 'Mastercard', 'reconoce Mastercard');
check($cards->validate($card(['numero' => '4000 0000 0000 0002']), $today)['declined'] === true, 'la 4000 0000 0000 0002 se marca como rechazada');
check(CardValidator::luhn('4242424242424242') && !CardValidator::luhn('4242424242424241') && !CardValidator::luhn(''), 'algoritmo de Luhn');
check(failedFields(static fn () => $cards->validate($card(['numero' => '4242 4242 4242 4241']), $today)) === ['numero'], 'número que no pasa Luhn -> numero');
check(failedFields(static fn () => $cards->validate($card(['numero' => '4242']), $today)) === ['numero'], 'número muy corto -> numero');
check($cards->validate($card(['vencimiento' => '10/26']), $today)['last4'] === '4242', 'vence a fin de mes: 10/26 todavía sirve el 21 de octubre');
check(failedFields(static fn () => $cards->validate($card(['vencimiento' => '09/26']), $today)) === ['vencimiento'], '09/26 ya venció');
check(failedFields(static fn () => $cards->validate($card(['vencimiento' => '13/27']), $today)) === ['vencimiento'], 'mes 13 -> vencimiento');
check(failedFields(static fn () => $cards->validate($card(['cvv' => '12']), $today)) === ['cvv'], 'CVV de 2 dígitos -> cvv');
check(failedFields(static fn () => $cards->validate($card(['titular' => ' ']), $today)) === ['titular'], 'sin titular -> titular');
check(failedFields(static fn () => $cards->validate($card(['titular' => str_repeat('a', 101)]), $today)) === ['titular'], 'titular de más de 100 caracteres');
check(failedFields(static fn () => $cards->validate([], $today)) === ['titular', 'numero', 'vencimiento', 'cvv'], 'sin datos: los cuatro campos');
check(PaymentProvider::SimulatedCard->label() === 'Tarjeta simulada' && PaymentProvider::PayPal->label() === 'PayPal (Sandbox)', 'nombres de las formas de pago');

// -----------------------------------------------------------
Report::section('PayPal: solo Sandbox');
check(PayPalClient::API_BASE === 'https://api-m.sandbox.paypal.com', 'la dirección de la API es la de Sandbox');
$source = (string) file_get_contents(dirname(__DIR__) . '/app/integrations/PayPalClient.php');
check(!str_contains($source, 'https://api-m.paypal.com') && !str_contains($source, 'api.paypal.com'), 'el cliente no contiene la dirección Live');
$bootstrap = (string) file_get_contents(dirname(__DIR__) . '/app/bootstrap.php');
check(str_contains($bootstrap, 'PAYPAL_SANDBOX_CLIENT_ID') && preg_match('/PAYPAL_(MODE|ENV|LIVE|BASE)/', $bootstrap) !== 1, 'el bootstrap solo lee credenciales de Sandbox, sin opción de entorno');

$log = [];
$noKeys = new PayPalClient('', '', static function () use (&$log): array {
    $log[] = 'llamada';

    return ['status' => 200, 'body' => '{}'];
});
$missing = thrown(PaymentProviderException::class, static fn () => $noKeys->createOrder('1', '10.00', 'MXN', 'x'));
check(!$noKeys->isConfigured() && $missing?->notConfigured === true && $log === [], 'sin credenciales no se llama a PayPal (missingConfiguration)');

$log = [];
$paypal = fakePayPal([
    'POST /v1/oauth2/token' => [['status' => 200, 'body' => ['access_token' => 'TOKEN-1', 'expires_in' => 32400]]],
    'POST /v2/checkout/orders' => [
        ['status' => 201, 'body' => ['id' => 'ORDER-1', 'status' => 'CREATED']],
        ['status' => 201, 'body' => ['id' => 'ORDER-2', 'status' => 'CREATED']],
        ['status' => 422, 'body' => ['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'CURRENCY_NOT_SUPPORTED']]]],
    ],
], $log);
check($paypal->createOrder('77', '199.50', 'MXN', 'Cursology: Curso - Nivel 2') === 'ORDER-1', 'crear orden devuelve su id');
$token = $log[0];
check($token['url'] === 'https://api-m.sandbox.paypal.com/v1/oauth2/token' && $token['body'] === 'grant_type=client_credentials', 'pide el token a Sandbox con client_credentials');
check(in_array('Authorization: Basic ' . base64_encode('sandbox-id:sandbox-secret'), $token['headers'], true), 'con autenticación Basic (client id y secret)');
$order = $log[1];
$orderBody = json_decode((string) $order['body'], true);
check($order['url'] === 'https://api-m.sandbox.paypal.com/v2/checkout/orders' && in_array('Authorization: Bearer TOKEN-1', $order['headers'], true), 'la orden va a Sandbox con el token');
check(in_array('PayPal-Request-Id: cursology-order-77', $order['headers'], true), 'PayPal-Request-Id por compra (idempotente)');
check($orderBody['intent'] === 'CAPTURE' && $orderBody['purchase_units'][0]['custom_id'] === '77' && $orderBody['purchase_units'][0]['reference_id'] === 'purchase-77', 'intent CAPTURE y la compra en custom_id');
check($orderBody['purchase_units'][0]['amount'] === ['currency_code' => 'MXN', 'value' => '199.50'], 'el importe va como texto exacto ("199.50"), no como número');
check(str_contains((string) $order['body'], '"value":"199.50"'), 'también en el JSON enviado');
check($orderBody['payment_source']['paypal']['experience_context']['shipping_preference'] === 'NO_SHIPPING', 'sin envío (producto digital)');
$paypal->createOrder('78', '10.00', 'MXN', 'x');
check(count(array_filter($log, static fn (array $r): bool => str_ends_with($r['url'], '/oauth2/token'))) === 1, 'el token se reutiliza entre llamadas');
$failed = thrown(PaymentProviderException::class, static fn () => $paypal->createOrder('79', '10.00', 'XXX', 'x'));
check($failed !== null && str_contains($failed->getMessage(), 'HTTP 422: CURRENCY_NOT_SUPPORTED') && !$failed->notConfigured, 'un error de PayPal trae el HTTP y el issue');

$captured = static fn (string $status, string $value = '199.50'): array => ['id' => 'ORDER-1', 'status' => $status, 'purchase_units' => [[
    'reference_id' => 'purchase-77',
    'payments' => ['captures' => [['id' => 'CAP-1', 'status' => $status, 'amount' => ['currency_code' => 'MXN', 'value' => $value], 'custom_id' => '77']]],
]]];
$log = [];
$paypal = fakePayPal([
    'POST /v1/oauth2/token' => [['status' => 200, 'body' => ['access_token' => 'TOKEN-2']]],
    'POST /v2/checkout/orders/ORDER-1/capture' => [
        ['status' => 201, 'body' => $captured('COMPLETED')],
        ['status' => 201, 'body' => $captured('PENDING')],
        ['status' => 422, 'body' => ['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]]],
        ['status' => 422, 'body' => ['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED']]]],
    ],
    'GET /v2/checkout/orders/ORDER-1' => [['status' => 200, 'body' => $captured('COMPLETED')], ['status' => 200, 'body' => ['id' => 'ORDER-1', 'status' => 'APPROVED']]],
], $log);
$capture = $paypal->captureOrder('ORDER-1');
check($capture->isCompleted() && $capture->captureId === 'CAP-1' && $capture->amount === '199.50' && $capture->currency === 'MXN' && $capture->customId === '77', 'captura completada con importe, moneda y compra');
check(in_array('PayPal-Request-Id: cursology-capture-ORDER-1', $log[1]['headers'], true), 'la captura también es idempotente');
$pending = $paypal->captureOrder('ORDER-1');
check($pending->status === 'PENDING' && !$pending->isCompleted(), 'PENDING se reporta tal cual (sin dar acceso)');
$again = $paypal->captureOrder('ORDER-1');
check($again->isCompleted() && end($log)['method'] === 'GET', 'ORDER_ALREADY_CAPTURED: lee la orden y devuelve su captura');
$declined = thrown(PaymentProviderException::class, static fn () => $paypal->captureOrder('ORDER-1'));
check($declined !== null && str_contains($declined->getMessage(), 'INSTRUMENT_DECLINED'), 'un rechazo de PayPal es un error del proveedor');
check($paypal->orderStatus('ORDER-1') === 'APPROVED' && end($log)['body'] === null, 'leer el estado de una orden (GET sin cuerpo)');
check(!str_contains(print_r($paypal, true), 'sandbox-secret') && str_contains(print_r($paypal, true), 'sandbox'), 'print_r/var_dump no muestran el secret');

// -----------------------------------------------------------
Report::section('Avance: umbral del 90%');
check(LearningService::WATCHED_PERCENT === 90, 'el umbral es 90%');
check(!LearningService::isWatched(89, 100) && LearningService::isWatched(90, 100) && LearningService::isWatched(100, 100), '89 de 100 s no, 90 sí');
check(!LearningService::isWatched(53, 60) && LearningService::isWatched(54, 60), '54 de 60 s (exactamente 90%)');
check(!LearningService::isWatched(500, null) && !LearningService::isWatched(0, 0), 'sin duración en la base no cuenta como visto');
$progressSql = (string) file_get_contents(dirname(__DIR__) . '/app/database/procedures/learning/sp_learning_progress.sql');
check(str_contains($progressSql, 'v_position * 10 >= v_duration * 9'), 'sp_learning_progress usa la misma regla (posición * 10 >= duración * 9)');

// -----------------------------------------------------------
Report::section('Exámenes: claves ocultas');
$attemptRow = [
    'id' => '5', 'course_chapter_id' => '9', 'course_level_id' => '3', 'attempt_number' => '1', 'started_at' => '2026-10-21 10:00:00.000000',
    'submitted_at' => null, 'minimum_grade_snapshot' => '70.00', 'max_points' => '6.00', 'earned_points' => null, 'grade' => null, 'passed' => null,
    'chapter_completed_at' => null, 'level_completed_at' => null, 'course_completed_at' => null,
];
$open = ExamAttempt::fromResultSets([
    [$attemptRow],
    [['question_id' => '11', 'question_snapshot' => '¿Dónde corre PHP?', 'selection_mode_snapshot' => 'single', 'max_points' => '1.00']],
    [['question_id' => '11', 'answer_id' => '21', 'answer_snapshot' => 'Servidor'], ['question_id' => '11', 'answer_id' => '22', 'answer_snapshot' => 'Navegador']],
]);
$openJson = json_encode(ExamAttemptController::present($open), JSON_THROW_ON_ERROR);
check(!$open->isSubmitted() && $open->questions[0]['answers'][0]['correct'] === null, 'un intento abierto no trae las claves');
check(!str_contains($openJson, '"correct"') && !str_contains($openJson, '"weight"') && !str_contains($openJson, '"selected"') && !str_contains($openJson, '"earnedPoints":"'), 'ni el JSON: sin correct, weight, selected ni puntos ganados');
$submitted = ExamAttempt::fromResultSets([
    [['submitted_at' => '2026-10-21 10:05:00.000000', 'earned_points' => '1.00', 'grade' => '100.00', 'passed' => '1', 'chapter_completed_at' => '2026-10-21 10:05:00.000000'] + $attemptRow],
    [['question_id' => '11', 'question_snapshot' => '¿Dónde corre PHP?', 'selection_mode_snapshot' => 'single', 'max_points' => '1.00', 'earned_points' => '1.00']],
    [
        ['question_id' => '11', 'answer_id' => '21', 'answer_snapshot' => 'Servidor', 'selected' => '1', 'is_correct_snapshot' => '1', 'weight_snapshot' => '1.00'],
        ['question_id' => '11', 'answer_id' => '22', 'answer_snapshot' => 'Navegador', 'selected' => '0', 'is_correct_snapshot' => '0', 'weight_snapshot' => '0.00'],
    ],
]);
$review = ExamAttemptController::present($submitted);
check($review['submitted'] && $review['passed'] === true && $review['grade'] === '100.00' && $review['chapterCompleted'], 'entregado: calificación, aprobado y capítulo terminado');
check($review['questions'][0]['earnedPoints'] === '1.00' && $review['questions'][0]['answers'][0] === ['id' => '21', 'text' => 'Servidor', 'selected' => true, 'correct' => true, 'weight' => '1.00'], 'y ya muestra las correctas y lo marcado');

// -----------------------------------------------------------
Report::section('Kardex: filtros');
check(StudentService::filters([]) === ['desde' => '', 'hasta' => '', 'categoria' => '', 'estado' => ''], 'sin filtros');
check(StudentService::filters(['desde' => '2026-10-01', 'hasta' => '2026-10-31', 'categoria' => '7', 'estado' => 'terminados'])
    === ['desde' => '2026-10-01', 'hasta' => '2026-10-31', 'categoria' => '7', 'estado' => 'terminados'], 'filtros válidos se conservan');
check(StudentService::filters(['desde' => '2026-10-31', 'hasta' => '2026-10-01'])['desde'] === '2026-10-01', 'fechas al revés se acomodan');
check(StudentService::filters(['desde' => '2026-02-30', 'hasta' => 'ayer', 'categoria' => 'x', 'estado' => 'todos'])
    === ['desde' => '', 'hasta' => '', 'categoria' => '', 'estado' => ''], 'fechas imposibles, categoría y estado desconocidos se ignoran');
check(StudentService::STATUSES === ['en_progreso' => 'in_progress', 'terminados' => 'completed', 'activos' => 'active'], 'estados del kardex y su valor en el SP');

Report::finish();
