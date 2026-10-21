<?php

declare(strict_types=1);

/**
 * Verificación de registro/login contra la base LOCAL, con los SP instalados
 * (app/database/procedures/install.sql). Usa la configuración 'local' del .env.
 *
 *   php tests/verify_auth_db.php
 *
 * Cada corrida crea dos cuentas de prueba (prueba+<marca>@cursology.test) que quedan
 * en la base: no hay un SP de borrado y no se ejecuta SQL directo desde PHP.
 */

use App\Database\Connection;
use App\Database\ProcedureException;
use App\Database\StoredProcedures;
use App\Integrations\CloudinaryClient;
use App\Models\Enums\AccountStatus;
use App\Models\Enums\LoginOutcome;
use App\Models\Enums\UserRole;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\Exceptions\AccountLockedException;
use App\Services\Exceptions\EmailTakenException;
use App\Services\Exceptions\InvalidCredentialsException;
use App\Services\Exceptions\ValidationException;
use App\Services\RegistrationValidator;

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/app/database/conn.php';

$procedures = new StoredProcedures(new Connection($DATABASES['local']));
$users = new UserRepository($procedures);
$validator = new RegistrationValidator();
$auth = new AuthService($users, $validator, new CloudinaryClient('', '', ''));

try {
    $procedures->call('sp_user_get_by_id', ['1']);
} catch (ProcedureException $exception) {
    fwrite(STDERR, 'No se pudo ejecutar sp_user_get_by_id en la base local. Revisa el .env y que MySQL esté encendido,' . PHP_EOL
        . 'e instala los SP con: SOURCE app/database/procedures/install.sql;' . PHP_EOL
        . 'Detalle: ' . ($exception->getPrevious()?->getMessage() ?? $exception->getMessage()) . PHP_EOL);
    exit(1);
}

try {
    $mark = date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $studentEmail = "prueba+$mark@cursology.test";
    $instructorEmail = "prueba+$mark-instructor@cursology.test";
    $password = 'Segura#2026';
    $input = [
        'rol' => 'student',
        'nombre' => 'Prueba',
        'apellido_paterno' => 'Automática',
        'apellido_materno' => '',
        'genero' => 'otro',
        'fecha_nacimiento' => '2000-01-31',
        'email' => strtoupper($studentEmail),
        'password' => $password,
    ];
    printf('Cuentas de prueba de esta corrida: %s, %s%s%s', $studentEmail, $instructorEmail, PHP_EOL, PHP_EOL);

    // -----------------------------------------------------------
    Report::section('Registro');
    $student = $auth->register($input, null);
    check($student->email() === $studentEmail, 'el email se guarda normalizado en minúsculas');
    check($student->role() === UserRole::Student, 'rol student');
    check($student->avatar() === AuthService::DEFAULT_AVATAR, 'sin foto -> avatar por defecto');
    check($student->motherLastName() === null, 'apellido materno vacío -> NULL en la BD');
    check($student->fatherLastName() === 'Automática', 'acentos se guardan bien (utf8mb4)');
    check($student->accountStatus() === AccountStatus::Active && $student->active() && !$student->ban(), 'cuenta activa y sin ban');
    check($student->consecutiveFailedLogins() === 0 && $student->lastLoginAt() === null, 'contador en 0 y sin último login');
    check(password_verify($password, $student->passwordHash()) && $student->passwordHash() !== $password, 'se guarda el hash, no la contraseña');
    check($student->createdAt()->getTimezone()->getName() === 'UTC', 'fechas hidratadas en UTC');

    check(thrown(EmailTakenException::class, static fn () => $auth->register($input, null)) !== null, 'email repetido -> EmailTakenException');
    $data = $validator->validate($input, null, new DateTimeImmutable());
    check(
        thrown(EmailTakenException::class, static fn () => $users->register($data, password_hash($password, PASSWORD_DEFAULT), 'x')) !== null,
        'el SP también rechaza el email repetido (EMAIL_TAKEN)',
    );
    foreach (['admin', 'Student'] as $role) {
        $exception = thrown(ProcedureException::class, static fn () => $procedures->row('sp_auth_register', [
            'Rol', 'Inválido', null, 'otro', '2000-01-01', 'x', "prueba+$mark-$role@cursology.test", 'hash', $role,
        ]));
        check($exception !== null && $exception->is('INVALID_ROLE'), "el SP rechaza el rol '$role' (INVALID_ROLE)");
    }

    $instructor = $auth->register(array_merge($input, ['rol' => 'instructor', 'email' => $instructorEmail]), null);
    check($instructor->role() === UserRole::Instructor, 'rol instructor');

    // -----------------------------------------------------------
    Report::section('Login y bloqueo por 3 fallos consecutivos');
    $logged = $auth->login(strtoupper($studentEmail), $password);
    check($logged->id() === $student->id(), 'login correcto (email sin importar mayúsculas)');
    $fresh = $users->findById($student->id());
    check($fresh !== null && $fresh->consecutiveFailedLogins() === 0 && $fresh->lastLoginAt() !== null, 'login correcto actualiza last_login_at');

    $wrong = static fn () => $auth->login($studentEmail, 'Incorrecta#1');
    check(thrown(InvalidCredentialsException::class, $wrong) !== null, '1er fallo -> credenciales inválidas');
    check(thrown(InvalidCredentialsException::class, $wrong) !== null, '2do fallo -> credenciales inválidas');
    check($users->findById($student->id())?->consecutiveFailedLogins() === 2, 'contador en 2');

    $auth->login($studentEmail, $password);
    check($users->findById($student->id())?->consecutiveFailedLogins() === 0, 'un login correcto reinicia el contador (fallos CONSECUTIVOS)');

    check(thrown(InvalidCredentialsException::class, $wrong) !== null, 'fallo 1 de la nueva serie');
    check(thrown(InvalidCredentialsException::class, $wrong) !== null, 'fallo 2 de la nueva serie');
    $lockedAt = $users->findById($student->id())?->statusChangedAt();
    $exception = thrown(AccountLockedException::class, $wrong);
    check($exception !== null && $exception->justLocked, '3er fallo consecutivo -> cuenta bloqueada en ese intento');
    $locked = $users->findById($student->id());
    check(
        $locked !== null && $locked->accountStatus() === AccountStatus::Locked && !$locked->active() && $locked->consecutiveFailedLogins() === 3,
        'estado locked, active = false y contador en 3',
    );
    check($locked !== null && $lockedAt !== null && $locked->statusChangedAt() >= $lockedAt, 'status_changed_at se actualizó');

    $exception = thrown(AccountLockedException::class, static fn () => $auth->login($studentEmail, $password));
    check($exception !== null && !$exception->justLocked, 'contraseña correcta con la cuenta bloqueada -> sigue bloqueada');
    check($users->findById($student->id())?->accountStatus() === AccountStatus::Locked, 'la contraseña correcta no reactiva la cuenta');

    check(thrown(InvalidCredentialsException::class, static fn () => $auth->login("no-existe-$mark@cursology.test", $password)) !== null, 'email inexistente -> credenciales inválidas');
    check($users->recordLogin(null, false)->outcome === LoginOutcome::UnknownUser, 'sp_auth_record_login registra unknown_user');
    $exception = thrown(ProcedureException::class, static fn () => $users->recordLogin('999999999999', true));
    check($exception !== null && $exception->is('USER_NOT_FOUND'), 'id inexistente -> USER_NOT_FOUND');

    $exception = thrown(ValidationException::class, static fn () => $auth->login('', ''));
    check($exception !== null && array_keys($exception->fields) === ['email', 'password'], 'login vacío -> errores de email y contraseña');

    // -----------------------------------------------------------
    Report::section('Usuario de la sesión');
    check($auth->userForSession($instructor->id())?->id() === $instructor->id(), 'cuenta activa -> usuario de la sesión');
    check($auth->userForSession($student->id()) === null, 'cuenta bloqueada -> la sesión deja de ser válida');
    check($auth->userForSession(null) === null && $auth->userForSession('999999999999') === null, 'sin sesión o id inexistente -> null');
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf('Error inesperado: %s: %s (%s:%d)%s', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine(), PHP_EOL));
    if ($exception->getPrevious() !== null) {
        fwrite(STDERR, 'Causa: ' . $exception->getPrevious()->getMessage() . PHP_EOL);
    }
    exit(1);
}

Report::finish();
