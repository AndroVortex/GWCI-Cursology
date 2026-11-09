<?php

use App\Http\Request;
use App\Models\Enums\UserRole;
use App\Services\Exceptions\BusinessRuleException;

define('BASE_PATH', dirname(__DIR__));

// true = BD local, false = BD de producción
define('USE_LOCAL_DB', true);

require_once BASE_PATH . '/app/autoload.php';
require_once BASE_PATH . '/app/database/conn.php';
// Con la variable de entorno CURSOLOGY_DB=test (servidor de las pruebas HTTP) usa la base de pruebas.
define('DB_DEFAULT', $DATABASES[getenv('CURSOLOGY_DB') === 'test' ? 'test' : (USE_LOCAL_DB ? 'local' : 'prod')]);

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
    'catalog' => [
        'title' => 'Catálogo',
        'file'  => BASE_PATH . '/app/views/catalog/html/catalog.html',
        'css'   => ['catalog/css/catalog.css'],
        'js'    => [],
    ],
    'course' => [
        'title' => 'Curso',
        'file'  => BASE_PATH . '/app/views/catalog/html/course.html',
        'css'   => ['catalog/css/catalog.css'],
        'js'    => ['catalog/js/course.js', 'reactions/js/reactions.js', 'base/js/report.js', 'catalog/js/reviews.js', 'messages/js/start-chat.js'],
    ],
    'verify' => [
        'title' => 'Verificar certificado',
        'file'  => BASE_PATH . '/app/views/catalog/html/verify.html',
        'css'   => ['catalog/css/catalog.css'],
        'js'    => [],
    ],
    'learn' => [
        'title' => 'Mi curso',
        'file'  => BASE_PATH . '/app/views/student/html/learn.html',
        'css'   => ['student/css/student.css', 'comments/css/comments.css'],
        'js'    => ['student/js/learn.js', 'student/js/exam-attempt.js', 'student/js/certificate.js', 'messages/js/start-chat.js',
                    'reactions/js/reactions.js', 'base/js/report.js', 'comments/js/comments.js'],
        'auth'  => true,
        'roles' => [UserRole::Student],
    ],
    'checkout' => [
        'title' => 'Pagar',
        'file'  => BASE_PATH . '/app/views/student/html/checkout.html',
        'css'   => ['catalog/css/catalog.css', 'student/css/student.css'],
        'js'    => ['student/js/checkout.js'],
        'auth'  => true,
        'roles' => [UserRole::Student],
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
    'moderation' => [
        'title' => 'Moderación',
        'file'  => BASE_PATH . '/app/views/admin/html/moderation.html',
        'css'   => ['admin/css/admin.css'],
        'js'    => ['admin/js/moderation.js'],
        'auth'  => true,
        'roles' => [UserRole::Admin],
    ],
    'accounts' => [
        'title' => 'Cuentas',
        'file'  => BASE_PATH . '/app/views/admin/html/accounts.html',
        'css'   => ['admin/css/admin.css'],
        'js'    => ['admin/js/accounts.js'],
        'auth'  => true,
        'roles' => [UserRole::Admin],
    ],
    'reactions' => [
        'title' => 'Reacciones',
        'file'  => BASE_PATH . '/app/views/admin/html/reactions.html',
        'css'   => [],
        'js'    => ['admin/js/reactions.js'],
        'auth'  => true,
        'roles' => [UserRole::Admin],
    ],
    'payment-methods' => [
        'title' => 'Formas de pago',
        'file'  => BASE_PATH . '/app/views/admin/html/payment-methods.html',
        'css'   => [],
        'js'    => ['admin/js/payment-methods.js'],
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
        'js'    => ['base/js/media-upload.js', 'instructor/js/course-form.js', 'instructor/js/course-editor.js', 'instructor/js/exam-editor.js'],
        'auth'  => true,
        'roles' => [UserRole::Instructor],
    ],
    'course-sales' => [
        'title' => 'Ventas del curso',
        'file'  => BASE_PATH . '/app/views/instructor/html/course-sales.html',
        'css'   => [],
        'js'    => ['messages/js/start-chat.js'],
        'auth'  => true,
        'roles' => [UserRole::Instructor],
    ],
    'course-comments' => [
        'title' => 'Comentarios del curso',
        'file'  => BASE_PATH . '/app/views/comments/html/course-comments.html',
        'css'   => ['comments/css/comments.css'],
        'js'    => ['reactions/js/reactions.js', 'comments/js/comments.js'],
        'auth'  => true,
        'roles' => [UserRole::Instructor],
    ],
    'messages' => [
        'title' => 'Mensajes',
        'file'  => BASE_PATH . '/app/views/messages/html/messages.html',
        'css'   => ['messages/css/messages.css'],
        'js'    => ['reactions/js/reactions.js', 'messages/js/messages.js'],
        'auth'  => true,
        'roles' => [UserRole::Student, UserRole::Instructor],
    ],
];

// Cada rol tiene su propio panel en ?view=dashboard.
$dashboards = [
    UserRole::Student->value => [
        'title' => 'Mi aprendizaje',
        'file'  => BASE_PATH . '/app/views/student/html/dashboard.html',
        'css'   => ['student/css/student.css'],
        'js'    => ['student/js/certificate.js'],
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

// Verificación pública de un certificado por su código (sin sesión).
if ($view === 'verify') {
    $verifyCode = is_string($_GET['code'] ?? null) ? trim($_GET['code']) : '';
    $verifiedCertificate = null;
    if ($verifyCode !== '') {
        try {
            $verifiedCertificate = $certificateService->verify($verifyCode);
        } catch (BusinessRuleException) {
            http_response_code(404);
        }
    }
}

// Reproductor: el capítulo pedido o el último que vio el alumno. Sin inscripción, la vista lo dice (403).
if ($view === 'learn') {
    try {
        $learningCourse = $learningService->course($currentUser, is_string($_GET['course'] ?? null) ? $_GET['course'] : '');
        $views['learn']['title'] = $learningCourse->title;
        $currentChapter = (is_string($_GET['chapter'] ?? null) ? $learningCourse->chapter($_GET['chapter']) : null)
            ?? $learningCourse->resumeChapter();
        $examAttempts = $currentChapter !== null && $currentChapter['isExam'] && $learningCourse->hasAccessTo($currentChapter)
            ? $examAttemptService->attempts($currentUser, $currentChapter['id'])
            : [];
    } catch (BusinessRuleException) {
        $learningCourse = null;
        $currentChapter = null;
        http_response_code(403);
    }
    // Comentarios del capítulo actual: solo en capítulos de video con acceso.
    $chapterComments = null;
    if ($learningCourse !== null && $currentChapter !== null && !$currentChapter['isExam'] && $learningCourse->hasAccessTo($currentChapter)) {
        try {
            $chapterComments = $commentService->list($currentUser, $currentChapter['id']);
        } catch (BusinessRuleException) {
            $chapterComments = null;
        }
    }
}

// Página pública de un curso y su pago: si no existe, es borrador o está dado de baja, la vista lo dice (404).
if ($view === 'course' || $view === 'checkout') {
    $publicCourseId = is_string($_GET[$view === 'course' ? 'id' : 'course'] ?? null) ? $_GET[$view === 'course' ? 'id' : 'course'] : '';
    try {
        $publicCourse = $catalogService->course($publicCourseId);
        $views[$view]['title'] = ($view === 'checkout' ? 'Pagar: ' : '') . $publicCourse->title;
    } catch (BusinessRuleException) {
        $publicCourse = null;
        http_response_code(404);
    }
    $courseAccess = $checkoutService->access($currentUser, $publicCourseId);
    $isStudent = $currentUser !== null && $currentUser->role() === UserRole::Student;
    if ($view === 'course' && $publicCourse !== null) {
        $reviewPage = $reviewService->list($publicCourse->id, [], $currentUser);
        $myReview = $reviewService->mine($currentUser, $publicCourse->id);
        $canModerate = $currentUser !== null
            && ($currentUser->role() === UserRole::Admin || $currentUser->id() === $publicCourse->instructorId);
    }
    if ($view === 'checkout') {
        $checkoutLevelId = is_string($_GET['level'] ?? null) && $_GET['level'] !== '' ? $_GET['level'] : null;
        $paymentProviders = $paymentMethodService->available();
        $paypalClientId = $paypal->clientId();
    }
}

// Datos de cada vista, siempre a través de los servicios (que llaman a los SP).
try {
    if ($view === 'home') {
        $homeCategories = $categoryService->active();
        $recentCourses = $catalogService->recent(8);
    }
    if ($view === 'catalog') {
        $catalogPage = $catalogService->search($_GET);
        $catalogCategories = $categoryService->active();
    }
    if ($view === 'dashboard' && $currentUser->role() === UserRole::Student) {
        $studentDashboard = $studentService->dashboard($currentUser, $_GET);
        $kardexCategories = $categoryService->active();
        $studentCertificates = $certificateService->forStudent($currentUser);
    }
    if ($view === 'dashboard' && $currentUser->role() === UserRole::Instructor) {
        $instructorCourses = $courseService->listMine($currentUser);
        $instructorDashboard = $reportService->instructorDashboard($currentUser, $_GET);
        $salesCategories = $categoryService->active();
        $recentComments = $commentService->recentForInstructor($currentUser);
    }
    if ($view === 'dashboard' && $currentUser->role() === UserRole::Admin) {
        $lockedAccounts = $accountService->lockedAccounts($currentUser);
        $adminDashboard = $reportService->adminDashboard($currentUser);
        $moderationSummary = $moderationService->summary($currentUser);
    }
    if ($view === 'moderation') {
        $moderationTab = ($_GET['tab'] ?? '') === 'contenido' ? 'contenido' : 'reportes';
        $moderationReports = $moderationService->reports($currentUser, $moderationTab === 'reportes' ? $_GET : []);
        $moderationContent = $moderationTab === 'contenido' ? $moderationService->content($currentUser, $_GET) : null;
    }
    if ($view === 'accounts') {
        $accountPage = $accountService->users($currentUser, $_GET);
    }
    if ($view === 'course-sales') {
        $courseSales = $reportService->courseStudents($currentUser, is_string($_GET['id'] ?? null) ? $_GET['id'] : '');
        $views['course-sales']['title'] = 'Ventas: ' . $courseSales->title;
    }
    if ($view === 'dashboard' && in_array($currentUser->role(), [UserRole::Student, UserRole::Instructor], true)) {
        $recentChats = array_slice($chatService->list($currentUser), 0, 3);
    }
    if ($view === 'messages') {
        // Abrir la conversación la marca leída: va antes de la lista y del contador del menú.
        $chatThread = null;
        $requestedChat = is_string($_GET['chat'] ?? null) ? $_GET['chat'] : '';
        if ($requestedChat !== '') {
            try {
                $chatThread = $chatService->open($currentUser, $requestedChat);
                $views['messages']['title'] = 'Mensajes: ' . $chatThread->otherName();
            } catch (BusinessRuleException) {
                http_response_code(404);
            }
        }
        $chatList = $chatService->list($currentUser);
    }
    if ($view === 'course-comments') {
        $instructorComments = $commentService->forInstructor($currentUser, is_string($_GET['id'] ?? null) ? $_GET['id'] : '', $_GET);
        $views['course-comments']['title'] = 'Comentarios: ' . $instructorComments->courseTitle;
    }
    if ($view === 'categories') {
        $categories = $categoryService->all();
    }
    if ($view === 'reactions') {
        $reactionCatalog = $reactionService->all();
    }
    // Catálogo activo para el selector de reacciones (reactions.js lo lee de la página).
    if (in_array($view, ['learn', 'course', 'messages', 'course-comments'], true)) {
        $activeReactions = $reactionService->active();
    }
    if ($view === 'payment-methods') {
        $paymentMethods = $paymentMethodService->overview();
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

// Contador de mensajes no leídos del menú (0 para visitantes y administradores).
try {
    $unreadMessages = $chatService->unreadTotal($currentUser);
} catch (Throwable $exception) {
    error_log('[view] no se pudo contar los mensajes no leídos: ' . $exception);
    $unreadMessages = 0;
}

$page = $views[$view];

require BASE_PATH . '/app/views/base/html/base.html';
