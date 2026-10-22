<?php

declare(strict_types=1);

// Raíz de composición: crea los objetos de la aplicación e inyecta sus dependencias.
// Requiere app/autoload.php, app/database/conn.php ($ENV) y la constante DB_DEFAULT.

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\CategoryController;
use App\Controllers\CourseController;
use App\Controllers\MediaController;
use App\Controllers\UserController;
use App\Database\Connection;
use App\Database\StoredProcedures;
use App\Http\ApiKernel;
use App\Http\Middleware\Cors;
use App\Http\Middleware\RequireAuth;
use App\Http\Middleware\RequireCsrf;
use App\Http\Middleware\RequireRole;
use App\Http\Router;
use App\Http\Session;
use App\Integrations\CloudinaryClient;
use App\Models\Enums\UserRole;
use App\Repositories\CategoryRepository;
use App\Repositories\CourseRepository;
use App\Repositories\UserRepository;
use App\Services\AccountAdminService;
use App\Services\AuthService;
use App\Services\CategoryService;
use App\Services\CourseService;
use App\Services\CourseValidator;
use App\Services\MediaService;
use App\Services\ProfileService;
use App\Services\ProfileValidator;
use App\Services\RegistrationValidator;

require_once __DIR__ . '/view_helpers.php';

// ---------------------------------------------------------------
// Servicios
// ---------------------------------------------------------------
$session = new Session();
$procedures = new StoredProcedures(new Connection(DB_DEFAULT));
$users = new UserRepository($procedures);
$cloudinary = new CloudinaryClient(
    $ENV['CLOUDINARY_CLOUD_NAME'] ?? '',
    $ENV['CLOUDINARY_API_KEY'] ?? '',
    $ENV['CLOUDINARY_API_SECRET'] ?? '',
);
$mediaService = new MediaService($cloudinary);
$auth = new AuthService($users, new RegistrationValidator(), $cloudinary);
$profileService = new ProfileService($users, new ProfileValidator(), $cloudinary);
$accountService = new AccountAdminService($users);
$categoryService = new CategoryService(new CategoryRepository($procedures));
$courseService = new CourseService(new CourseRepository($procedures), new CourseValidator(), $mediaService);

// ---------------------------------------------------------------
// API: rutas y middleware (sesión -> rol -> CSRF -> controlador)
// ---------------------------------------------------------------
$authController = new AuthController($auth, $session);
$userController = new UserController($profileService, $session);
$accountController = new AccountController($accountService);
$categoryController = new CategoryController($categoryService);
$mediaController = new MediaController($mediaService);
$courseController = new CourseController($courseService);

$csrf = new RequireCsrf($session);
$requireAuth = new RequireAuth($session, $auth);
$adminOnly = [$requireAuth, new RequireRole(UserRole::Admin)];
$instructorOnly = [$requireAuth, new RequireRole(UserRole::Instructor)];

$router = new Router();

$router->add('GET', '/api/auth/csrf', $authController->csrf(...));
$router->add('POST', '/api/auth/register', $authController->register(...), [$csrf]);
$router->add('POST', '/api/auth/login', $authController->login(...), [$csrf]);
$router->add('POST', '/api/auth/logout', $authController->logout(...), [$csrf]);
$router->add('GET', '/api/auth/me', $authController->me(...), [$requireAuth]);

$router->add('PATCH', '/api/users/me', $userController->updateProfile(...), [$requireAuth, $csrf]);
$router->add('POST', '/api/users/me/avatar', $userController->updateAvatar(...), [$requireAuth, $csrf]);
$router->add('DELETE', '/api/users/me/avatar', $userController->removeAvatar(...), [$requireAuth, $csrf]);
$router->add('PUT', '/api/users/me/password', $userController->changePassword(...), [$requireAuth, $csrf]);

$router->add('GET', '/api/admin/users/locked', $accountController->listLocked(...), $adminOnly);
$router->add('POST', '/api/admin/users/{id}/unlock', $accountController->unlock(...), [...$adminOnly, $csrf]);

$router->add('GET', '/api/categories', $categoryController->listActive(...));
$router->add('GET', '/api/admin/categories', $categoryController->listAll(...), $adminOnly);
$router->add('POST', '/api/admin/categories', $categoryController->create(...), [...$adminOnly, $csrf]);
$router->add('PATCH', '/api/admin/categories/{id}', $categoryController->update(...), [...$adminOnly, $csrf]);

$router->add('POST', '/api/media/upload-ticket', $mediaController->ticket(...), [...$instructorOnly, $csrf]);

$router->add('GET', '/api/instructor/courses', $courseController->listMine(...), $instructorOnly);
$router->add('POST', '/api/instructor/courses', $courseController->create(...), [...$instructorOnly, $csrf]);
$router->add('GET', '/api/instructor/courses/{id}', $courseController->show(...), $instructorOnly);
$router->add('PATCH', '/api/instructor/courses/{id}', $courseController->update(...), [...$instructorOnly, $csrf]);
$router->add('POST', '/api/instructor/courses/{id}/levels', $courseController->addLevel(...), [...$instructorOnly, $csrf]);
$router->add('PATCH', '/api/instructor/levels/{id}', $courseController->updateLevel(...), [...$instructorOnly, $csrf]);
$router->add('DELETE', '/api/instructor/levels/{id}', $courseController->deleteLevel(...), [...$instructorOnly, $csrf]);
$router->add('POST', '/api/instructor/levels/{id}/chapters', $courseController->addChapter(...), [...$instructorOnly, $csrf]);
$router->add('PATCH', '/api/instructor/chapters/{id}', $courseController->updateChapter(...), [...$instructorOnly, $csrf]);
$router->add('DELETE', '/api/instructor/chapters/{id}', $courseController->deleteChapter(...), [...$instructorOnly, $csrf]);
$router->add('POST', '/api/instructor/chapters/{id}/contents', $courseController->addContent(...), [...$instructorOnly, $csrf]);
$router->add('DELETE', '/api/instructor/contents/{id}', $courseController->deleteContent(...), [...$instructorOnly, $csrf]);

$allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $ENV['APP_ALLOWED_ORIGINS'] ?? ''))));
$api = new ApiKernel($router, new Cors($allowedOrigins));
