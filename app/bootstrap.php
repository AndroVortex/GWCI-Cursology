<?php

declare(strict_types=1);

// Raíz de composición: crea los objetos de la aplicación e inyecta sus dependencias.
// Requiere app/autoload.php, app/database/conn.php ($ENV) y la constante DB_DEFAULT.

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\CatalogController;
use App\Controllers\CategoryController;
use App\Controllers\CertificateController;
use App\Controllers\ChatController;
use App\Controllers\CheckoutController;
use App\Controllers\CommentController;
use App\Controllers\ExamAttemptController;
use App\Controllers\LearningController;
use App\Controllers\CourseController;
use App\Controllers\MediaController;
use App\Controllers\PaymentMethodController;
use App\Controllers\ReactionController;
use App\Controllers\ReportController;
use App\Controllers\ReviewController;
use App\Controllers\UserController;
use App\Database\Connection;
use App\Database\StoredProcedures;
use App\Http\ApiKernel;
use App\Http\Middleware\Cors;
use App\Http\Middleware\OptionalAuth;
use App\Http\Middleware\RequireAuth;
use App\Http\Middleware\RequireCsrf;
use App\Http\Middleware\RequireRole;
use App\Http\Router;
use App\Http\Session;
use App\Integrations\CloudinaryClient;
use App\Integrations\PayPalClient;
use App\Models\Enums\UserRole;
use App\Repositories\CatalogRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\CertificateRepository;
use App\Repositories\ChatRepository;
use App\Repositories\CommentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\PaymentMethodRepository;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\LearningRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\ReactionRepository;
use App\Repositories\ReportRepository;
use App\Repositories\ReviewRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UserRepository;
use App\Services\AccountAdminService;
use App\Services\AuthService;
use App\Services\CatalogService;
use App\Services\CategoryService;
use App\Services\CertificateRenderer;
use App\Services\CertificateService;
use App\Services\ChatService;
use App\Services\CheckoutService;
use App\Services\CloudinaryCertificateStore;
use App\Services\CommentService;
use App\Services\ExamAttemptService;
use App\Services\LearningService;
use App\Services\StudentService;
use App\Services\CourseService;
use App\Services\CourseValidator;
use App\Services\MediaService;
use App\Services\PaymentMethodService;
use App\Services\ProfileService;
use App\Services\ProfileValidator;
use App\Services\ReactionService;
use App\Services\RegistrationValidator;
use App\Services\ReportService;
use App\Services\ReviewService;

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
$catalogService = new CatalogService(new CatalogRepository($procedures));
// Solo Sandbox de PayPal Developers: la URL de la API va fija en PayPalClient.
$paypal = new PayPalClient($ENV['PAYPAL_SANDBOX_CLIENT_ID'] ?? '', $ENV['PAYPAL_SANDBOX_CLIENT_SECRET'] ?? '');
$paymentMethodService = new PaymentMethodService(new PaymentMethodRepository($procedures), $paypal);
$checkoutService = new CheckoutService(new PurchaseRepository($procedures), $paymentMethodService, $paypal);
// Dirección pública que se imprime en los certificados; sin APP_URL, la de esta petición.
$appUrl = rtrim(trim($ENV['APP_URL'] ?? ''), '/');
$requestHost = $_SERVER['HTTP_HOST'] ?? '';
if ($appUrl === '' && is_string($requestHost) && preg_match('/^[A-Za-z0-9.-]+(:[0-9]{1,5})?$/D', $requestHost) === 1) {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $appUrl = ($https ? 'https' : 'http') . '://' . $requestHost . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
}
$certificateService = new CertificateService(
    new CertificateRepository($procedures),
    new CertificateRenderer(),
    new CloudinaryCertificateStore($cloudinary),
    $appUrl,
);

// ---------------------------------------------------------------
// API: rutas y middleware (sesión -> rol -> CSRF -> controlador)
// ---------------------------------------------------------------
$authController = new AuthController($auth, $session);
$userController = new UserController($profileService, $session);
$accountController = new AccountController($accountService);
$categoryController = new CategoryController($categoryService);
$mediaController = new MediaController($mediaService);
$courseController = new CourseController($courseService);
$catalogController = new CatalogController($catalogService);
$paymentMethodController = new PaymentMethodController($paymentMethodService);
$checkoutController = new CheckoutController($checkoutService);
$learningService = new LearningService(new LearningRepository($procedures), $mediaService);
$learningController = new LearningController($learningService);
$examAttemptService = new ExamAttemptService(new ExamAttemptRepository($procedures));
$examAttemptController = new ExamAttemptController($examAttemptService);
$studentService = new StudentService(new StudentRepository($procedures));
$certificateController = new CertificateController($certificateService);
$reviewService = new ReviewService(new ReviewRepository($procedures));
$reviewController = new ReviewController($reviewService);
$reportService = new ReportService(new ReportRepository($procedures));
$reportController = new ReportController($reportService);
$chatService = new ChatService(new ChatRepository($procedures));
$chatController = new ChatController($chatService);
$commentService = new CommentService(new CommentRepository($procedures));
$commentController = new CommentController($commentService);
$reactionService = new ReactionService(new ReactionRepository($procedures));
$reactionController = new ReactionController($reactionService);

$csrf = new RequireCsrf($session);
$requireAuth = new RequireAuth($session, $auth);
$optionalAuth = new OptionalAuth($session, $auth);
$adminOnly = [$requireAuth, new RequireRole(UserRole::Admin)];
$instructorOnly = [$requireAuth, new RequireRole(UserRole::Instructor)];
$studentOnly = [$requireAuth, new RequireRole(UserRole::Student)];
$studentOrInstructor = [$requireAuth, new RequireRole(UserRole::Student, UserRole::Instructor)];

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
$router->add('GET', '/api/catalog/courses', $catalogController->search(...));
$router->add('GET', '/api/catalog/courses/{id}', $catalogController->show(...));
$router->add('GET', '/api/catalog/courses/{id}/reviews', $reviewController->list(...), [$optionalAuth]);
$router->add('PUT', '/api/student/courses/{id}/review', $reviewController->save(...), [...$studentOnly, $csrf]);
$router->add('POST', '/api/opinions/{id}/hide', $reviewController->hide(...), [$requireAuth, new RequireRole(UserRole::Instructor, UserRole::Admin), $csrf]);
$router->add('GET', '/api/certificates/verify', $certificateController->verify(...));
$router->add('POST', '/api/student/courses/{id}/certificate', $certificateController->issue(...), [...$studentOnly, $csrf]);
$router->add('GET', '/api/student/certificates/{id}/download', $certificateController->download(...), $studentOnly);
$router->add('GET', '/api/chats', $chatController->list(...), $studentOrInstructor);
$router->add('POST', '/api/chats', $chatController->start(...), [...$studentOrInstructor, $csrf]);
$router->add('GET', '/api/chats/{id}/messages', $chatController->messages(...), $studentOrInstructor);
$router->add('POST', '/api/chats/{id}/messages', $chatController->send(...), [...$studentOrInstructor, $csrf]);
$router->add('PUT', '/api/messages/{id}/reaction', $reactionController->reactToMessage(...), [...$studentOrInstructor, $csrf]);
$router->add('PUT', '/api/opinions/{id}/reaction', $reactionController->reactToOpinion(...), [...$studentOrInstructor, $csrf]);
$router->add('GET', '/api/chapters/{id}/comments', $commentController->list(...), $studentOrInstructor);
$router->add('POST', '/api/chapters/{id}/comments', $commentController->create(...), [...$studentOrInstructor, $csrf]);
$router->add('PATCH', '/api/comments/{id}', $commentController->update(...), [...$studentOrInstructor, $csrf]);
$router->add('DELETE', '/api/comments/{id}', $commentController->delete(...), [...$studentOrInstructor, $csrf]);
$router->add('POST', '/api/comments/{id}/hide', $commentController->hide(...), [$requireAuth, new RequireRole(UserRole::Instructor, UserRole::Admin), $csrf]);
$router->add('PUT', '/api/comments/{id}/reaction', $commentController->react(...), [...$studentOrInstructor, $csrf]);
$router->add('GET', '/api/reactions', $reactionController->catalog(...));
$router->add('GET', '/api/admin/reactions', $reactionController->adminList(...), $adminOnly);
$router->add('POST', '/api/admin/reactions', $reactionController->create(...), [...$adminOnly, $csrf]);
$router->add('POST', '/api/admin/reactions/defaults', $reactionController->addDefaults(...), [...$adminOnly, $csrf]);
$router->add('PATCH', '/api/admin/reactions/{id}', $reactionController->update(...), [...$adminOnly, $csrf]);
$router->add('GET', '/api/admin/categories', $categoryController->listAll(...), $adminOnly);
$router->add('POST', '/api/admin/categories', $categoryController->create(...), [...$adminOnly, $csrf]);
$router->add('PATCH', '/api/admin/categories/{id}', $categoryController->update(...), [...$adminOnly, $csrf]);
$router->add('POST', '/api/student/enrollments', $checkoutController->enrollFree(...), [...$studentOnly, $csrf]);
$router->add('POST', '/api/student/chapters/{id}/progress', $learningController->progress(...), [...$studentOnly, $csrf]);
$router->add('GET', '/api/student/chapters/{id}/attempts', $examAttemptController->list(...), $studentOnly);
$router->add('POST', '/api/student/chapters/{id}/attempts', $examAttemptController->start(...), [...$studentOnly, $csrf]);
$router->add('POST', '/api/student/attempts/{id}/submit', $examAttemptController->submit(...), [...$studentOnly, $csrf]);
$router->add('POST', '/api/checkout/paypal/orders', $checkoutController->startPayPal(...), [...$studentOnly, $csrf]);
$router->add('POST', '/api/checkout/paypal/purchases/{id}/capture', $checkoutController->capturePayPal(...), [...$studentOnly, $csrf]);
$router->add('POST', '/api/checkout/purchases/{id}/cancel', $checkoutController->cancel(...), [...$studentOnly, $csrf]);
$router->add('POST', '/api/checkout/card', $checkoutController->payWithCard(...), [...$studentOnly, $csrf]);
$router->add('GET', '/api/admin/payment-methods', $paymentMethodController->list(...), $adminOnly);
$router->add('GET', '/api/admin/reports', $reportController->admin(...), $adminOnly);
$router->add('POST', '/api/admin/payment-methods', $paymentMethodController->setActive(...), [...$adminOnly, $csrf]);

$router->add('POST', '/api/media/upload-ticket', $mediaController->ticket(...), [...$instructorOnly, $csrf]);

$router->add('GET', '/api/instructor/courses', $courseController->listMine(...), $instructorOnly);
$router->add('GET', '/api/instructor/sales', $reportController->sales(...), $instructorOnly);
$router->add('GET', '/api/instructor/courses/{id}/students', $reportController->courseStudents(...), $instructorOnly);
$router->add('GET', '/api/instructor/courses/{id}/comments', $commentController->instructorList(...), $instructorOnly);
$router->add('POST', '/api/instructor/courses', $courseController->create(...), [...$instructorOnly, $csrf]);
$router->add('GET', '/api/instructor/courses/{id}', $courseController->show(...), $instructorOnly);
$router->add('PATCH', '/api/instructor/courses/{id}', $courseController->update(...), [...$instructorOnly, $csrf]);
$router->add('POST', '/api/instructor/courses/{id}/publish', $courseController->publish(...), [...$instructorOnly, $csrf]);
$router->add('POST', '/api/instructor/courses/{id}/unpublish', $courseController->unpublish(...), [...$instructorOnly, $csrf]);
$router->add('PATCH', '/api/instructor/courses/{id}/status', $courseController->setStatus(...), [...$instructorOnly, $csrf]);
$router->add('POST', '/api/instructor/courses/{id}/levels', $courseController->addLevel(...), [...$instructorOnly, $csrf]);
$router->add('PATCH', '/api/instructor/levels/{id}', $courseController->updateLevel(...), [...$instructorOnly, $csrf]);
$router->add('DELETE', '/api/instructor/levels/{id}', $courseController->deleteLevel(...), [...$instructorOnly, $csrf]);
$router->add('POST', '/api/instructor/levels/{id}/move', $courseController->moveLevel(...), [...$instructorOnly, $csrf]);
$router->add('POST', '/api/instructor/levels/{id}/chapters', $courseController->addChapter(...), [...$instructorOnly, $csrf]);
$router->add('PATCH', '/api/instructor/chapters/{id}', $courseController->updateChapter(...), [...$instructorOnly, $csrf]);
$router->add('DELETE', '/api/instructor/chapters/{id}', $courseController->deleteChapter(...), [...$instructorOnly, $csrf]);
$router->add('POST', '/api/instructor/chapters/{id}/move', $courseController->moveChapter(...), [...$instructorOnly, $csrf]);
$router->add('POST', '/api/instructor/chapters/{id}/questions', $courseController->addQuestion(...), [...$instructorOnly, $csrf]);
$router->add('POST', '/api/instructor/chapters/{id}/preview-grade', $courseController->previewGrade(...), [...$instructorOnly, $csrf]);
$router->add('PATCH', '/api/instructor/questions/{id}', $courseController->updateQuestion(...), [...$instructorOnly, $csrf]);
$router->add('DELETE', '/api/instructor/questions/{id}', $courseController->deleteQuestion(...), [...$instructorOnly, $csrf]);
$router->add('POST', '/api/instructor/questions/{id}/move', $courseController->moveQuestion(...), [...$instructorOnly, $csrf]);
$router->add('POST', '/api/instructor/chapters/{id}/contents', $courseController->addContent(...), [...$instructorOnly, $csrf]);
$router->add('DELETE', '/api/instructor/contents/{id}', $courseController->deleteContent(...), [...$instructorOnly, $csrf]);

$allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $ENV['APP_ALLOWED_ORIGINS'] ?? ''))));
$api = new ApiKernel($router, new Cors($allowedOrigins));
