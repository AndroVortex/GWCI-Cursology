<?php

use App\Http\Request;
use App\Models\Enums\UserRole;
use App\Services\Exceptions\BusinessRuleException;

define('BASE_PATH', dirname(__DIR__));

// true = BD local, false = BD de producción
define('USE_LOCAL_DB', true);

require_once BASE_PATH . '/app/autoload.php';
require_once BASE_PATH . '/app/database/conn.php';
define('DB_DEFAULT', $DATABASES[USE_LOCAL_DB ? 'local' : 'prod']);

require_once BASE_PATH . '/app/bootstrap.php';

$request = Request::fromGlobals();
$session->start();

if ($request->isApi()) {
    $api->handle($request)->send();
    exit;
}

$currentUser = null;
try {
    $currentUser = $auth->userForSession($session->userId());
    if ($currentUser === null && $session->userId() !== null) {
        $session->clearUser();
    }
} catch (Throwable $exception) {
    error_log('[view] no se pudo cargar el usuario de la sesión: ' . $exception);
}
$isLoggedIn = $currentUser !== null;
$csrfToken = $session->csrfToken();

// 'auth' => requiere sesión; 'roles' => además, solo esos roles.
$views = [
    'home' => [
        'title' => 'Inicio',
        'file'  => BASE_PATH . '/app/views/home/html/home.html',
        'css'   => ['home/css/home.css'],
        'js'    => [],
    ],
    'login' => [
        'title' => 'Iniciar sesión',
        'file'  => BASE_PATH . '/app/views/base/html/login.html',
        'css'   => ['base/css/login.css'],
        'js'    => ['base/js/login.js'],
    ],
    'profile' => [
        'title' => 'Mi perfil',
        'file'  => BASE_PATH . '/app/views/profile/html/profile.html',
        'css'   => ['profile/css/profile.css'],
        'js'    => ['profile/js/profile.js'],
        'auth'  => true,
    ],
    'categories' => [
        'title' => 'Categorías',
        'file'  => BASE_PATH . '/app/views/admin/html/categories.html',
        'css'   => [],
        'js'    => ['admin/js/categories.js'],
        'auth'  => true,
        'roles' => [UserRole::Admin],
    ],
    'course-new' => [
        'title' => 'Nuevo curso',
        'file'  => BASE_PATH . '/app/views/instructor/html/course-new.html',
        'css'   => ['instructor/css/course.css'],
        'js'    => ['base/js/media-upload.js', 'instructor/js/course-form.js'],
        'auth'  => true,
        'roles' => [UserRole::Instructor],
    ],
    'course-editor' => [
        'title' => 'Editar curso',
        'file'  => BASE_PATH . '/app/views/instructor/html/course-editor.html',
        'css'   => ['instructor/css/course.css'],
        'js'    => ['base/js/media-upload.js', 'instructor/js/course-form.js', 'instructor/js/course-editor.js'],
        'auth'  => true,
        'roles' => [UserRole::Instructor],
    ],
];

// Cada rol tiene su propio panel en ?view=dashboard.
$dashboards = [
    UserRole::Student->value => [
        'title' => 'Mi aprendizaje',
        'file'  => BASE_PATH . '/app/views/student/html/dashboard.html',
    ],
    UserRole::Instructor->value => [
        'title' => 'Panel del instructor',
        'file'  => BASE_PATH . '/app/views/instructor/html/dashboard.html',
    ],
    UserRole::Admin->value => [
        'title' => 'Administración',
        'file'  => BASE_PATH . '/app/views/admin/html/dashboard.html',
        'js'    => ['admin/js/dashboard.js'],
    ],
];

$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'home';
if ($view !== 'dashboard' && !isset($views[$view])) {
    $view = 'home';
}

if ($view === 'login' && $isLoggedIn) {
    header('Location: ?view=dashboard');
    exit;
}

if (($view === 'dashboard' || ($views[$view]['auth'] ?? false)) && !$isLoggedIn) {
    header('Location: ?view=login');
    exit;
}

if (isset($views[$view]['roles']) && !in_array($currentUser->role(), $views[$view]['roles'], true)) {
    header('Location: ?view=dashboard');
    exit;
}

if ($view === 'dashboard') {
    $views['dashboard'] = $dashboards[$currentUser->role()->value] + ['css' => [], 'js' => []];
}

// Datos de cada vista, siempre a través de los servicios (que llaman a los SP).
try {
    if ($view === 'dashboard' && $currentUser->role() === UserRole::Instructor) {
        $instructorCourses = $courseService->listMine($currentUser);
    }
    if ($view === 'dashboard' && $currentUser->role() === UserRole::Admin) {
        $lockedAccounts = $accountService->lockedAccounts($currentUser);
    }
    if ($view === 'categories') {
        $categories = $categoryService->all();
    }
    if ($view === 'course-new' || $view === 'course-editor') {
        $activeCategories = $categoryService->active();
    }
    if ($view === 'course-editor') {
        $outline = $courseService->outline($currentUser, is_string($_GET['id'] ?? null) ? $_GET['id'] : '');
    }
} catch (BusinessRuleException) {
    header('Location: ?view=dashboard');
    exit;
}

$page = $views[$view];

require BASE_PATH . '/app/views/base/html/base.html';
