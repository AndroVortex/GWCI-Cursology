<?php

use App\Http\Request;

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
];

$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'home';

if ($view === 'login' && $isLoggedIn) {
    header('Location: ?view=home');
    exit;
}

if ($view === 'profile' && !$isLoggedIn) {
    header('Location: ?view=login');
    exit;
}

if (!isset($views[$view])) {
    $view = 'home';
}

$page = $views[$view];

require BASE_PATH . '/app/views/base/html/base.html';
