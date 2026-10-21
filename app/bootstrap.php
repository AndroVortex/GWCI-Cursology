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
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\RegistrationValidator;

/** Escapa texto para imprimirlo en HTML. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
