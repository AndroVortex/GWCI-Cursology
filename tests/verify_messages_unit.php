<?php

declare(strict_types=1);

/**
 * Verificación sin base de datos de los mensajes: validación del texto y de los ids, lectura de
 * las filas de los SP, los extractos, el JSON de la API y que la migración sea idempotente.
 *
 *   php tests/verify_messages_unit.php
 */

use App\Database\Connection;
use App\Database\StoredProcedures;
use App\Http\Presenters\ChatPresenter;
use App\Models\Dto\ChatMessage;
use App\Models\Dto\ChatSummary;
use App\Models\Dto\ChatThread;
use App\Models\Entities\AppUser;
use App\Models\Enums\UserRole;
use App\Repositories\ChatRepository;
use App\Services\ChatService;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/app/view_helpers.php';

/** @return list<string>|null */
function failedFields(callable $callback): ?array
{
    $exception = thrown(ValidationException::class, $callback);

    return $exception === null ? null : array_keys($exception->fields);
}

function ruleCode(callable $callback): ?string
{
    return thrown(BusinessRuleException::class, $callback)?->errorCode;
}

function user(UserRole $role): AppUser
{
    $timestamp = '2026-10-25 12:00:00.000000';

    return AppUser::fromRow([
        'id' => 7, 'name' => 'Ana', 'father_last_name' => 'López', 'mother_last_name' => null, 'gender' => 'femenino',
        'birthday' => '2000-05-17', 'avatar' => 'img/avatar-default.svg', 'email' => 'ana@example.com',
        'password_hash' => '$2y$10$hashDePrueba', 'role' => $role->value, 'description' => null,
        'account_status' => 'active', 'active' => 1, 'consecutive_failed_logins' => 0, 'status_changed_at' => $timestamp,
        'ban' => 0, 'banned_by' => null, 'banned_at' => null, 'ban_reason' => null, 'created_at' => $timestamp,
        'updated_at' => $timestamp, 'last_login_at' => null,
    ]);
}

// La conexión es perezosa: estas pruebas fallan antes de llegar a la base.
$service = new ChatService(new ChatRepository(new StoredProcedures(new Connection([
    'host' => '127.0.0.1', 'port' => 1, 'name' => 'sin_base', 'user' => 'nadie', 'password' => '',
]))));
$student = user(UserRole::Student);
$instructor = user(UserRole::Instructor);

// -----------------------------------------------------------
Report::section('Validación del mensaje');
check(failedFields(static fn () => ChatService::validateMessage([])) === ['texto'], 'sin texto -> texto');
check(failedFields(static fn () => ChatService::validateMessage(['texto' => " \n\t "])) === ['texto'], 'solo espacios -> texto');
check(failedFields(static fn () => ChatService::validateMessage(['texto' => ['hola']])) === ['texto'], 'un arreglo en lugar de texto -> texto');
check(failedFields(static fn () => ChatService::validateMessage(['texto' => "\xC3\x28"])) === ['texto'], 'texto que no es UTF-8 -> texto');
check(ChatService::validateMessage(['texto' => str_repeat('a', 2000)])[0] === str_repeat('a', 2000), '2000 caracteres se aceptan');
check(failedFields(static fn () => ChatService::validateMessage(['texto' => str_repeat('a', 2001)])) === ['texto'], '2001 caracteres -> texto');
check(ChatService::validateMessage(['texto' => str_repeat('ñ', 2000)])[0] === str_repeat('ñ', 2000), 'cuenta caracteres, no bytes (2000 "ñ")');
check(ChatService::validateMessage(['texto' => "  Hola\nprofe  "]) === ["Hola\nprofe", null], 'quita los espacios de los lados y conserva los saltos de línea');
check(ChatService::validateMessage(['texto' => 'Hola', 'responde_a' => 15]) === ['Hola', '15'], 'responde_a como número');
check(ChatService::validateMessage(['texto' => 'Hola', 'responde_a' => '15']) === ['Hola', '15'], 'responde_a como texto');
check(ChatService::validateMessage(['texto' => 'Hola', 'responde_a' => null])[1] === null
    && ChatService::validateMessage(['texto' => 'Hola', 'responde_a' => ''])[1] === null, 'responde_a vacío o null = no responde');
foreach (['abc', '0', '-3', '1.5', 'x15'] as $badId) {
    check(failedFields(static fn () => ChatService::validateMessage(['texto' => 'Hola', 'responde_a' => $badId])) === ['responde_a'], "responde_a \"$badId\" -> responde_a");
}
check(failedFields(static fn () => ChatService::validateMessage(['texto' => '', 'responde_a' => 'abc'])) === ['texto', 'responde_a'], 'los dos errores a la vez');
check(ChatService::MAX_MESSAGE_CHARS === 2000 && ChatService::MESSAGE_LIMIT === 100, 'límites: 2000 caracteres y 100 mensajes por consulta');

// -----------------------------------------------------------
Report::section('Ids y roles');
check(failedFields(static fn () => $service->start($student, [])) === ['curso'], 'iniciar sin curso -> curso');
check(failedFields(static fn () => $service->start($student, ['curso' => 'abc'])) === ['curso'], 'curso inválido -> curso');
check(failedFields(static fn () => $service->start($instructor, ['curso' => '5'])) === ['alumno'], 'el instructor necesita el alumno');
check(failedFields(static fn () => $service->start($instructor, ['curso' => '5', 'alumno' => '0'])) === ['alumno'], 'alumno inválido -> alumno');
check(ruleCode(static fn () => $service->open($student, 'abc')) === 'CHAT_NOT_FOUND', 'abrir con id inválido -> CHAT_NOT_FOUND');
check(failedFields(static fn () => $service->open($student, '5', 'xyz')) === ['after'], 'after inválido -> after');
check(ruleCode(static fn () => $service->send($student, '0', ['texto' => 'Hola'])) === 'CHAT_NOT_FOUND', 'enviar a un id inválido -> CHAT_NOT_FOUND');
check(failedFields(static fn () => $service->send($student, '5', ['texto' => ''])) === ['texto'], 'enviar vacío -> texto (antes de llamar al SP)');
check($service->unreadTotal(null) === 0 && $service->unreadTotal(user(UserRole::Admin)) === 0, 'sin sesión o admin: 0 no leídos, sin consultar la base');

$rule = BusinessRuleException::of('INVALID_MESSAGE');
check($rule->status === 422 && $rule->field === 'texto', 'INVALID_MESSAGE: 422 en texto');
check(BusinessRuleException::of('CHAT_NOT_FOUND')->status === 404 && BusinessRuleException::of('MESSAGE_NOT_FOUND')->status === 404, 'CHAT_NOT_FOUND y MESSAGE_NOT_FOUND: 404');
check(BusinessRuleException::of('NOT_ALLOWED')->status === 403, 'NOT_ALLOWED: 403');
check(BusinessRuleException::of('STUDENT_NOT_ENROLLED')->field === 'alumno', 'STUDENT_NOT_ENROLLED: en el campo alumno');

// -----------------------------------------------------------
Report::section('Filas de los SP y extractos');
$summaryRow = [
    'id' => '3', 'course_id' => '9', 'course_title' => 'PHP desde cero', 'course_cover' => 'https://x/c.jpg', 'course_active' => 0,
    'viewer_role' => 'student', 'other_id' => '4', 'other_name' => 'Irene Instructora', 'other_avatar' => 'img/i.svg',
    'last_message_id' => '20', 'last_message_content' => "Claro,   revisa\n\nel capítulo 2", 'last_message_at' => '2026-10-25 18:05:00.000000',
    'last_message_mine' => 0, 'unread_count' => 2, 'created_at' => '2026-10-25 17:00:00.000000', 'last_activity_at' => '2026-10-25 18:05:00.000000',
];
$summary = ChatSummary::fromRow($summaryRow);
check($summary->viewerRole === UserRole::Student && !$summary->courseActive && $summary->unreadCount === 2, 'resumen: rol de quien consulta, curso dado de baja y no leídos');
check($summary->excerpt() === 'Claro, revisa el capítulo 2', 'el extracto junta espacios y saltos de línea');
check(ChatSummary::fromRow(['last_message_mine' => 1] + $summaryRow)->excerpt() === 'Tú: Claro, revisa el capítulo 2', 'con "Tú: " si el último lo envió quien consulta');
$empty = ChatSummary::fromRow(['last_message_id' => null, 'last_message_content' => null, 'last_message_at' => null, 'last_message_mine' => null, 'unread_count' => 0] + $summaryRow);
check($empty->excerpt() === '' && $empty->lastMessageAt === null, 'sin mensajes: extracto vacío');
$long = ChatMessage::excerpt(str_repeat('palabra ', 40), 40);
check(mb_strlen($long) <= 40 && str_ends_with($long, '…'), 'recorta los textos largos con "…"');

$messageRow = [
    'id' => '21', 'sent_by' => '7', 'sender_name' => 'Ana López', 'sender_avatar' => 'img/a.svg', 'content' => "Gracias\nprofe",
    'sent_at' => '2026-10-25 18:10:00.000000', 'reply_to' => '20', 'reply_sender_name' => 'Irene Instructora', 'reply_content' => 'Claro, revisa el capítulo 2',
];
$message = ChatMessage::fromRow($messageRow);
check($message->isFrom('7') && !$message->isFrom('4') && $message->replyExcerpt() === 'Claro, revisa el capítulo 2', 'mensaje: autor y la cita de la respuesta');
$hiddenReply = ChatMessage::fromRow(['reply_content' => null] + $messageRow);
check($hiddenReply->replyTo === '20' && $hiddenReply->replyExcerpt() === '', 'si el mensaje citado se dio de baja, la cita queda vacía');

$thread = ChatThread::fromResultSets([
    [[
        'id' => '3', 'course_id' => '9', 'course_title' => 'PHP desde cero', 'course_cover' => 'https://x/c.jpg', 'course_active' => 1,
        'viewer_role' => 'instructor', 'student_id' => '7', 'student_name' => 'Ana López', 'student_avatar' => 'img/a.svg',
        'instructor_id' => '4', 'instructor_name' => 'Irene Instructora', 'instructor_avatar' => 'img/i.svg', 'created_at' => '2026-10-25 17:00:00.000000',
    ]],
    [$messageRow, ['id' => '22', 'reply_to' => null, 'reply_sender_name' => null, 'reply_content' => null] + $messageRow],
    [['unread_total' => 5]],
    [['target_id' => '22', 'reaction_id' => '1', 'emoji' => '👍', 'reaction_name' => 'Me gusta', 'total' => 2, 'mine' => 1]],
]);
check($thread->otherName() === 'Ana López' && $thread->otherAvatar() === 'img/a.svg', 'para el instructor, la otra persona es la alumna');
check($thread->lastMessageId() === '22' && count($thread->messages) === 2 && $thread->unreadTotal === 5, 'último id, mensajes y no leídos del menú');
check($thread->messages[0]->reactions->counts === [] && $thread->messages[1]->reactions->mine === '1' && $thread->messages[1]->reactions->total() === 2, 'cada mensaje con sus reacciones (4.º resultado)');

// -----------------------------------------------------------
Report::section('JSON de la API');
$json = ChatPresenter::message($message, '7');
check(array_keys($json) === ['id', 'mine', 'author', 'avatar', 'text', 'sentAt', 'sentAtLabel', 'replyTo', 'replyAuthor', 'replyExcerpt', 'reactions'], 'mensaje: solo los campos de la vista');
check($json['reactions'] === ['items' => [], 'mine' => null], 'sin reacciones: la lista vacía');
check($json['mine'] === true && ChatPresenter::message($message, '4')['mine'] === false, '"mine" depende de quien consulta');
check($json['sentAtLabel'] === '25 oct 2026, 12:10' && $json['text'] === "Gracias\nprofe", 'fecha en hora de Monterrey y el texto tal cual (el navegador lo pone con textContent)');
$summaryJson = ChatPresenter::summary($summary);
check(!array_key_exists('otherId', $summaryJson) && !isset($summaryJson['other']['id']) && $summaryJson['url'] === '?view=messages&chat=3', 'resumen sin ids internos de usuarios, con su enlace');
$threadJson = ChatPresenter::thread($thread, '4');
check($threadJson['lastMessageId'] === '22' && $threadJson['unreadTotal'] === 5 && $threadJson['chat']['other']['name'] === 'Ana López', 'conversación: último id, no leídos y la otra persona');
check(format_date_time(new DateTimeImmutable('2026-01-05 03:07:00', new DateTimeZone('UTC'))) === '4 ene 2026, 21:07', 'format_date_time cambia de día al pasar a Monterrey');

// -----------------------------------------------------------
Report::section('Migración y vistas');
$migration = (string) file_get_contents(dirname(__DIR__) . '/app/database/migrations/2026_11_chat_read.sql');
foreach (['student_last_read_at', 'instructor_last_read_at'] as $column) {
    $check = strpos($migration, "COLUMN_NAME = '$column'");
    $alter = strpos($migration, "ADD COLUMN $column");
    check($check !== false && $alter !== false && $check < $alter, "$column: revisa information_schema antes del ALTER");
}
check(substr_count($migration, 'PREPARE migration_step FROM') === 2 && !str_contains($migration, 'DROP '), 'cada columna con su PREPARE y sin DROP');
$views = dirname(__DIR__) . '/app/views';
$js = (string) file_get_contents("$views/messages/js/messages.js");
check(!str_contains($js, 'innerHTML') && !str_contains($js, 'insertAdjacentHTML'), 'messages.js no arma HTML con texto: solo textContent');
check(str_contains($js, 'POLL_MS = 10000'), 'messages.js pregunta cada 10 segundos');
foreach (['student/html/dashboard.html', 'instructor/html/dashboard.html'] as $file) {
    check(!str_contains((string) file_get_contents("$views/$file"), 'entrega posterior'), "$file sin el aviso de \"entrega posterior\"");
}

// -----------------------------------------------------------
Report::section('Menú lateral escondido');
$sidebar = (string) file_get_contents("$views/base/html/sidebar.html");
$css = (string) file_get_contents("$views/base/css/base.css");
$baseJs = (string) file_get_contents("$views/base/js/base.js");
$handle = strpos($sidebar, 'data-sidebar-pin');
check(str_starts_with(trim(substr($sidebar, strpos($sidebar, '<div class="sidebar-dock"'))), '<div class="sidebar-dock" data-sidebar-dock>') && $handle !== false && $handle < strpos($sidebar, '<aside class="sidebar"'), 'el menú y su pestaña van juntos, la pestaña primero (orden del teclado)');
check(substr_count($sidebar, 'data-unread-total') === 2, 'la pestaña repite el contador de mensajes sin leer (messages.js actualiza los dos)');
check(preg_match('/\.app-shell \{\s+display: grid;\s+grid-template-columns: minmax\(0, 1fr\);/', $css) === 1, 'el menú ya no ocupa una columna');
check(preg_match('/@media \(min-width: 1024px\) \{\s+\.sidebar-dock \{\s+position: fixed;/', $css) === 1 && str_contains($css, '.sidebar-dock:hover .sidebar,'), 'en escritorio se despliega encima del contenido con el cursor');
check(str_contains($css, '.sidebar-dock.is-pinned .sidebar,') && str_contains($css, '.sidebar-dock.has-focus .sidebar {'), 'también con la pestaña (pantallas táctiles) y con el teclado');
check(str_contains($baseJs, "dock.addEventListener('focusout'") && str_contains($baseJs, '!dock.contains(event.relatedTarget)'), 'con el teclado sigue abierto mientras el foco pase entre sus enlaces');

Report::finish();
