<?php

declare(strict_types=1);

/**
 * Verificación sin base de datos de la moderación y las cuentas: validación de reportes, filtros
 * y búsqueda, lectura de las filas de los SP, mensajes de login, la baja desde el perfil y que la
 * migración y los scripts sean seguros.
 *
 *   php tests/verify_moderation_unit.php
 */

use App\Database\Connection;
use App\Database\StoredProcedures;
use App\Integrations\CloudinaryClient;
use App\Models\Dto\AccountEvent;
use App\Models\Dto\AdminUserRow;
use App\Models\Dto\LoginResult;
use App\Models\Dto\ModerationItem;
use App\Models\Dto\ModerationReport;
use App\Models\Entities\AppUser;
use App\Models\Enums\ReportReason;
use App\Models\Enums\ReportTarget;
use App\Models\Enums\UserRole;
use App\Repositories\ModerationRepository;
use App\Repositories\UserRepository;
use App\Services\AccountAdminService;
use App\Services\Exceptions\AccountUnavailableException;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;
use App\Services\ModerationService;
use App\Services\ProfileService;
use App\Services\ProfileValidator;

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

function user(UserRole $role, string $passwordHash = '$2y$10$hashDePrueba'): AppUser
{
    $timestamp = '2026-10-25 12:00:00.000000';

    return AppUser::fromRow([
        'id' => 7, 'name' => 'Ana', 'father_last_name' => 'López', 'mother_last_name' => null, 'gender' => 'femenino',
        'birthday' => '2000-05-17', 'avatar' => 'img/avatar-default.svg', 'email' => 'ana@example.com',
        'password_hash' => $passwordHash, 'role' => $role->value, 'description' => null,
        'account_status' => 'active', 'active' => 1, 'consecutive_failed_logins' => 0, 'status_changed_at' => $timestamp,
        'ban' => 0, 'banned_by' => null, 'banned_at' => null, 'ban_reason' => null, 'created_at' => $timestamp,
        'updated_at' => $timestamp, 'last_login_at' => null,
    ]);
}

// La conexión es perezosa: estas pruebas fallan antes de llegar a la base.
$noDatabase = new StoredProcedures(new Connection(['host' => '127.0.0.1', 'port' => 1, 'name' => 'sin_base', 'user' => 'nadie', 'password' => '']));
$moderation = new ModerationService(new ModerationRepository($noDatabase));
$users = new UserRepository($noDatabase);
$accounts = new AccountAdminService($users);
$profiles = new ProfileService($users, new ProfileValidator(), new CloudinaryClient('', '', ''));
$student = user(UserRole::Student, password_hash('Segura#2026', PASSWORD_DEFAULT));
$admin = user(UserRole::Admin);

// -----------------------------------------------------------
Report::section('Validación del reporte');
check(failedFields(static fn () => ModerationService::validateReport([])) === ['motivo'], 'sin motivo -> motivo');
check(failedFields(static fn () => ModerationService::validateReport(['motivo' => 'abuse'])) === ['motivo'], 'motivo desconocido -> motivo');
check(failedFields(static fn () => ModerationService::validateReport(['motivo' => 'SPAM'])) === ['motivo'], 'el motivo distingue mayúsculas');
check(failedFields(static fn () => ModerationService::validateReport(['motivo' => 'other'])) === ['detalle'], '"Otro" sin detalle -> detalle');
check(failedFields(static fn () => ModerationService::validateReport(['motivo' => 'spam', 'detalle' => 'ab'])) === ['detalle'], 'detalle de 2 caracteres -> detalle');
check(failedFields(static fn () => ModerationService::validateReport(['motivo' => 'spam', 'detalle' => str_repeat('a', 501)])) === ['detalle'], 'detalle de 501 caracteres -> detalle');
check(ModerationService::validateReport(['motivo' => 'spam']) === [ReportReason::Spam, null], 'spam sin detalle');
check(ModerationService::validateReport(['motivo' => 'other', 'detalle' => '  Publicidad de otro sitio  ']) === [ReportReason::Other, 'Publicidad de otro sitio'], '"Otro" con detalle, sin espacios de los lados');
check(ModerationService::validateReport(['motivo' => 'answers', 'detalle' => '']) === [ReportReason::Answers, null], 'detalle vacío = sin detalle');
check(ReportReason::Answers->label() === 'Comparte respuestas del examen' && ReportTarget::Opinion->label() === 'Opinión', 'etiquetas de motivo y tipo');

// -----------------------------------------------------------
Report::section('Filtros y búsqueda');
check(ModerationService::contentFilters([]) === ['tipo' => '', 'estado' => '', 'q' => ''], 'sin filtros');
check(ModerationService::contentFilters(['tipo' => 'comment', 'estado' => 'ocultos', 'q' => ' spam ']) === ['tipo' => 'comment', 'estado' => 'ocultos', 'q' => 'spam'], 'filtros válidos');
check(ModerationService::contentFilters(['tipo' => 'mensaje', 'estado' => 'todos', 'q' => ['x']]) === ['tipo' => '', 'estado' => '', 'q' => ''], 'los inválidos se ignoran');
check(mb_strlen(ModerationService::contentFilters(['q' => str_repeat('ñ', 150)])['q']) === 100, 'la búsqueda se corta a 100 caracteres');
check(ModerationService::likePattern('') === null && ModerationService::likePattern('   ') === null, 'sin búsqueda: null');
check(ModerationService::likePattern('50%_off\\') === '%50\\%\\_off\\\\%', '%, _ y \\ se escapan para LIKE');
check(AccountAdminService::filters(['q' => 'ana', 'rol' => 'instructor', 'estado' => 'suspendidas']) === ['q' => 'ana', 'rol' => 'instructor', 'estado' => 'suspendidas'], 'filtros de cuentas válidos');
check(AccountAdminService::filters(['rol' => 'student', 'estado' => 'banned']) === ['q' => '', 'rol' => '', 'estado' => ''], 'los valores internos del SP no se aceptan como filtro');

// -----------------------------------------------------------
Report::section('Ids y validaciones antes de la base');
check(ruleCode(static fn () => $moderation->report($student, ReportTarget::Comment, 'abc', ['motivo' => 'spam'])) === 'COMMENT_NOT_FOUND', 'reportar un comentario con id inválido -> COMMENT_NOT_FOUND');
check(ruleCode(static fn () => $moderation->report($student, ReportTarget::Opinion, '0', ['motivo' => 'spam'])) === 'OPINION_NOT_FOUND', 'una opinión con id inválido -> OPINION_NOT_FOUND');
check(failedFields(static fn () => $moderation->report($student, ReportTarget::Comment, '5', [])) === ['motivo'], 'sin motivo no se llama al SP');
check(ruleCode(static fn () => $moderation->dismiss($admin, ReportTarget::Comment, 'x')) === 'COMMENT_NOT_FOUND', 'descartar con id inválido');
check(ruleCode(static fn () => $moderation->restore($admin, ReportTarget::Opinion, '-1')) === 'OPINION_NOT_FOUND', 'restaurar con id inválido');
check(ruleCode(static fn () => $accounts->ban($admin, 'abc', ['motivo' => 'Spam'])) === 'USER_NOT_FOUND', 'suspender con id inválido -> USER_NOT_FOUND');
check(failedFields(static fn () => $accounts->ban($admin, '9', ['motivo' => 'no'])) === ['motivo'], 'motivo de suspensión de menos de 3 caracteres -> motivo');
check(ruleCode(static fn () => $accounts->unban($admin, '')) === 'USER_NOT_FOUND' && ruleCode(static fn () => $accounts->reactivate($admin, '1.5')) === 'USER_NOT_FOUND', 'quitar suspensión y reactivar con id inválido');
check(ruleCode(static fn () => $accounts->events($admin, 'x')) === 'USER_NOT_FOUND', 'historial con id inválido');

// -----------------------------------------------------------
Report::section('Dar de baja la cuenta');
check(ruleCode(static fn () => $profiles->deactivate($admin, ['password' => 'x'])) === 'ADMIN_CANNOT_DEACTIVATE', 'un admin no se da de baja');
check(failedFields(static fn () => $profiles->deactivate($student, [])) === ['password'], 'sin contraseña -> password');
check(failedFields(static fn () => $profiles->deactivate($student, ['password' => 'Otra#2026'])) === ['password'], 'contraseña incorrecta -> password');
check(failedFields(static fn () => $profiles->deactivate($student, ['password' => 'Segura#2026', 'motivo' => str_repeat('a', 501)])) === ['motivo'], 'motivo de 501 caracteres -> motivo');
check(ProfileService::DEACTIVATE_REASON === 'Baja solicitada por el usuario', 'motivo por defecto en el historial');

// -----------------------------------------------------------
Report::section('Login de cuentas no disponibles');
$blocked = static fn (?string $status, ?int $ban): LoginResult => LoginResult::fromRow(['outcome' => 'blocked', 'consecutive_failures' => 0, 'account_status' => $status, 'ban' => $ban]);
check($blocked('deleted', 0)->banned === false && $blocked('active', 1)->banned === true, 'sp_auth_record_login dice si la cuenta tiene ban');
check(LoginResult::fromRow(['outcome' => 'unknown_user', 'consecutive_failures' => 0, 'account_status' => null, 'ban' => null])->banned === false, 'sin cuenta: sin ban');
check((new AccountUnavailableException())->getMessage() === AccountUnavailableException::DELETED_MESSAGE && str_contains(AccountUnavailableException::DELETED_MESSAGE, 'dada de baja'), 'mensaje de cuenta dada de baja');
check((new AccountUnavailableException(banned: true))->getMessage() === AccountUnavailableException::BANNED_MESSAGE && str_contains(AccountUnavailableException::BANNED_MESSAGE, 'suspendida'), 'mensaje de cuenta suspendida');

// -----------------------------------------------------------
Report::section('Filas de los SP');
$report = ModerationReport::fromRow([
    'target_type' => 'comment', 'target_id' => '10', 'content' => 'La respuesta es la B', 'active' => 1, 'created_at' => '2026-10-25 18:00:00.000000',
    'author_id' => '8', 'author_name' => 'Beto Ruiz', 'author_email' => 'beto@example.com', 'author_avatar' => 'img/b.svg',
    'course_id' => '9', 'course_title' => 'PHP', 'chapter_id' => '3', 'chapter_title' => 'Variables',
    'report_count' => 4, 'spam_count' => 1, 'offensive_count' => 0, 'answers_count' => 3, 'other_count' => 0,
    'first_reported_at' => '2026-10-25 18:05:00.000000', 'last_reported_at' => '2026-10-25 19:00:00.000000', 'last_details' => 'Spoiler',
]);
check($report->reasonsSummary() === '3 × Comparte respuestas del examen, 1 × Spam o publicidad', 'motivos del más repetido al menos');
check($report->topReason() === ReportReason::Answers && $report->reportCount === 4 && $report->lastDetails === 'Spoiler', 'el motivo propuesto al ocultar es el más repetido');
$tie = ModerationReport::fromRow(['spam_count' => 2, 'answers_count' => 2, 'report_count' => 4] + [
    'target_type' => 'opinion', 'target_id' => '11', 'content' => 'x', 'active' => 1, 'created_at' => '2026-10-25 18:00:00.000000',
    'author_id' => '8', 'author_name' => 'Beto', 'author_email' => 'b@example.com', 'author_avatar' => 'img/b.svg',
    'course_id' => '9', 'course_title' => 'PHP', 'chapter_id' => null, 'chapter_title' => null,
    'offensive_count' => 0, 'other_count' => 0, 'first_reported_at' => '2026-10-25 18:05:00.000000', 'last_reported_at' => '2026-10-25 19:00:00.000000', 'last_details' => null,
]);
check($tie->topReason() === ReportReason::Spam && $tie->chapterId === null, 'empate: gana el orden de los motivos; las opiniones no tienen capítulo');

$item = static fn (array $overrides): ModerationItem => ModerationItem::fromRow($overrides + [
    'target_type' => 'comment', 'target_id' => '10', 'content' => 'Hola', 'active' => 1, 'created_at' => '2026-10-25 18:00:00.000000',
    'author_id' => '8', 'author_name' => 'Beto', 'author_email' => 'b@example.com', 'author_avatar' => 'img/b.svg',
    'course_id' => '9', 'course_title' => 'PHP', 'chapter_id' => '3', 'chapter_title' => 'Variables',
    'deactivated_at' => null, 'deactivation_reason' => null, 'deactivated_by_name' => null, 'removed_by_author' => 0, 'restorable' => 0, 'report_count' => 0,
]);
check($item([])->statusLabel() === 'Visible', 'visible');
check($item(['active' => 0, 'removed_by_author' => 1, 'deactivated_at' => '2026-10-25 19:00:00.000000', 'deactivation_reason' => 'Eliminado por su autor', 'deactivated_by_name' => 'Beto'])->statusLabel() === 'Eliminado por su autor', 'eliminado por su autor');
$hidden = $item(['active' => 0, 'restorable' => 1, 'deactivated_at' => '2026-10-25 19:00:00.000000', 'deactivation_reason' => 'Spoiler', 'deactivated_by_name' => 'Irene Instructora']);
check($hidden->statusLabel() === 'Oculto por Irene Instructora: Spoiler' && $hidden->restorable, 'oculto: por quién y por qué, y se puede restaurar');

$row = static fn (array $overrides): AdminUserRow => AdminUserRow::fromRow($overrides + [
    'id' => '5', 'name' => 'Ana', 'father_last_name' => 'López', 'mother_last_name' => null, 'email' => 'ana@example.com', 'avatar' => 'img/a.svg',
    'role' => 'student', 'account_status' => 'active', 'ban' => 0, 'ban_reason' => null, 'banned_at' => null,
    'created_at' => '2026-10-01 10:00:00.000000', 'last_login_at' => null, 'status_changed_at' => '2026-10-01 10:00:00.000000',
    'last_event_type' => null, 'last_event_at' => null, 'last_event_reason' => null,
]);
check($row([])->fullName() === 'Ana López' && $row([])->canBan() && !$row([])->canReactivate(), 'activa: se puede suspender, no reactivar');
check(!$row(['role' => 'admin'])->canBan(), 'un admin no se suspende');
check(!$row(['ban' => 1, 'ban_reason' => 'Spam', 'banned_at' => '2026-10-02 10:00:00.000000'])->canBan(), 'ya suspendida: solo "Quitar suspensión"');
check($row(['account_status' => 'deleted', 'last_event_type' => 'deleted', 'last_event_at' => '2026-10-03 10:00:00.000000', 'last_event_reason' => 'Ya no la uso'])->canReactivate(), 'dada de baja: se puede reactivar');

$event = static fn (?string $actor): AccountEvent => AccountEvent::fromRow(['id' => '1', 'event_type' => 'banned', 'reason' => 'Spam', 'created_at' => '2026-10-02 10:00:00.000000', 'actor_id' => $actor, 'actor_name' => $actor === null ? null : 'Admin Principal']);
check($event('1')->label() === 'Suspendida' && $event('1')->actorLabel('5') === 'Admin Principal', 'evento con el admin que actuó');
check($event(null)->actorLabel('5') === 'Sistema' && $event('5')->actorLabel('5') === 'La propia cuenta', 'el sistema (bloqueo automático) o la propia cuenta (baja)');

// -----------------------------------------------------------
Report::section('Códigos, migración y vistas');
foreach (['CANNOT_REPORT_OWN' => 409, 'INVALID_REPORT' => 422, 'NOT_RESTORABLE' => 409, 'CANNOT_BAN_ADMIN' => 409, 'ALREADY_BANNED' => 409, 'NOT_BANNED' => 409, 'USER_ALREADY_ACTIVE' => 409, 'ADMIN_CANNOT_DEACTIVATE' => 403] as $code => $status) {
    check(BusinessRuleException::of($code)->status === $status, "$code: $status");
}
$root = dirname(__DIR__);
$migration = (string) file_get_contents("$root/app/database/migrations/2026_11_content_report.sql");
$schema = (string) file_get_contents("$root/app/database/schema/Cursology_modelo_fisico_mysql.sql");
check(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `content_report`') && !str_contains($migration, 'DROP '), 'la migración usa IF NOT EXISTS y no borra nada');
$tableBody = static function (string $sql): string {
    preg_match('/CREATE TABLE(?: IF NOT EXISTS)? `content_report` \((.*?)\R\) ENGINE=InnoDB;/s', $sql, $m);

    return (string) preg_replace('/\s+/', ' ', trim($m[1] ?? ''));
};
check($tableBody($migration) !== '' && $tableBody($migration) === $tableBody($schema), 'la tabla de la migración es la misma que la del esquema');
$views = "$root/app/views";
foreach (['base/js/report.js', 'admin/js/moderation.js', 'admin/js/accounts.js', 'profile/js/profile.js'] as $script) {
    check(!preg_match('/innerHTML|outerHTML|insertAdjacentHTML|document\.write/', (string) file_get_contents("$views/$script")), "$script no arma HTML con texto");
}
check(!str_contains((string) file_get_contents("$views/profile/html/profile.html"), 'Disponible próximamente'), '"Dar de baja la cuenta" ya está disponible en el perfil');
check(!str_contains((string) file_get_contents("$views/admin/html/dashboard.html"), 'Desde aquí podrás dar de baja'), 'el panel de moderación ya no es un aviso de "próximamente"');

Report::finish();
