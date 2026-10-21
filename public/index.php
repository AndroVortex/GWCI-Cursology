<?php

use App\Http\Request;
use App\Models\Enums\UserRole;

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
        'js'    => [],
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
    ],
];

$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'home';

if ($view === 'login' && $isLoggedIn) {
    header('Location: ?view=dashboard');
    exit;
}

if (in_array($view, ['profile', 'dashboard'], true) && !$isLoggedIn) {
    header('Location: ?view=login');
    exit;
}

$dashboardRole = null;
$isPreview = false;
if ($view === 'dashboard') {
    $dashboardRole = $currentUser->role();

    // Solo en local: ?view=dashboard&as=admin muestra el panel (sin datos) de otro rol
    // para revisar su diseño sin crear cuentas de cada tipo.
    $previewRole = USE_LOCAL_DB && is_string($_GET['as'] ?? null) ? UserRole::tryFrom($_GET['as']) : null;
    if ($previewRole !== null && $previewRole !== $dashboardRole) {
        $dashboardRole = $previewRole;
        $isPreview = true;
    }

    $views['dashboard'] = $dashboards[$dashboardRole->value] + ['css' => [], 'js' => []];
}

if (!isset($views[$view])) {
    $view = 'home';
}

$page = $views[$view];

require BASE_PATH . '/app/views/base/html/base.html';
