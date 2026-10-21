<?php

declare(strict_types=1);

// Raíz de composición: crea los objetos de la aplicación e inyecta sus dependencias.
// Requiere app/autoload.php, app/database/conn.php ($ENV) y la constante DB_DEFAULT.

use App\Controllers\AuthController;
use App\Database\Connection;
use App\Database\StoredProcedures;
use App\Http\ApiKernel;
use App\Http\Middleware\Cors;
use App\Http\Middleware\RequireAuth;
use App\Http\Middleware\RequireCsrf;
use App\Http\Router;
use App\Http\Session;
use App\Integrations\CloudinaryClient;
use App\Models\Enums\UserRole;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\RegistrationValidator;

/** Zona horaria en la que se muestran las fechas (en la BD se guardan en UTC). */
const DISPLAY_TIME_ZONE = 'America/Monterrey';

/** Escapa texto para imprimirlo en HTML. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Fecha con el formato de la rúbrica: día, mes en tres letras y año de cuatro dígitos
 * ("18 oct 2026"). Acepta un DATETIME ya hidratado (UTC) o un DATE 'Y-m-d'.
 */
function format_date(DateTimeInterface|string|null $date): string
{
    if ($date === null) {
        return '—';
    }

    $zone = new DateTimeZone(DISPLAY_TIME_ZONE);
    $local = is_string($date)
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone)
        : DateTimeImmutable::createFromInterface($date)->setTimezone($zone);
    if ($local === false) {
        return '—';
    }

    $months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    return sprintf('%d %s %s', (int) $local->format('j'), $months[(int) $local->format('n') - 1], $local->format('Y'));
}

function role_label(UserRole $role): string
{
    return match ($role) {
        UserRole::Student => 'Alumno',
        UserRole::Instructor => 'Instructor',
        UserRole::Admin => 'Administrador',
    };
}

$session = new Session();
$users = new UserRepository(new StoredProcedures(new Connection(DB_DEFAULT)));
$cloudinary = new CloudinaryClient(
    $ENV['CLOUDINARY_CLOUD_NAME'] ?? '',
    $ENV['CLOUDINARY_API_KEY'] ?? '',
    $ENV['CLOUDINARY_API_SECRET'] ?? '',
);
$auth = new AuthService($users, new RegistrationValidator(), $cloudinary);
$authController = new AuthController($auth, $session);

$csrf = new RequireCsrf($session);
$requireAuth = new RequireAuth($session, $auth);

$router = new Router();
$router->add('GET', '/api/auth/csrf', $authController->csrf(...));
$router->add('POST', '/api/auth/register', $authController->register(...), [$csrf]);
$router->add('POST', '/api/auth/login', $authController->login(...), [$csrf]);
$router->add('POST', '/api/auth/logout', $authController->logout(...), [$csrf]);
$router->add('GET', '/api/auth/me', $authController->me(...), [$requireAuth]);

$allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $ENV['APP_ALLOWED_ORIGINS'] ?? ''))));
$api = new ApiKernel($router, new Cors($allowedOrigins));
