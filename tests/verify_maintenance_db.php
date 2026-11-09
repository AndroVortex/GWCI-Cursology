<?php

declare(strict_types=1);

/**
 * Verificación de la limpieza (sp_maintenance_purge) y de los datos de demostración contra la
 * base de pruebas (cursology_test), creada con php scripts/setup_test_db.php.
 *
 *   php tests/verify_maintenance_db.php
 *
 * Arma datos cruzados entre cuentas de prueba (...@cursology.test) y cuentas que no lo son
 * (aquí, ...@demo.cursology.local): inscripciones, comentarios y respuestas, reacciones,
 * reportes, opiniones, exámenes y conversaciones. Comprueba que el ensayo no borra nada y que
 * --apply quita solo lo de prueba. Después carga los datos de demostración y los borra.
 * La limpieza 'test' también borra las cuentas de prueba que dejaron las otras suites.
 */

use App\Database\Connection;
use App\Database\StoredProcedures;
use App\Integrations\CloudinaryClient;
use App\Integrations\PayPalClient;
use App\Models\Enums\ReportTarget;
use App\Repositories\CategoryRepository;
use App\Repositories\ChatRepository;
use App\Repositories\CommentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\LearningRepository;
use App\Repositories\MaintenanceRepository;
use App\Repositories\ModerationRepository;
use App\Repositories\PaymentMethodRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\ReactionRepository;
use App\Repositories\ReviewRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\CategoryService;
use App\Services\ChatService;
use App\Services\CheckoutService;
use App\Services\CommentService;
use App\Services\CourseService;
use App\Services\CourseValidator;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\ExamAttemptService;
use App\Services\LearningService;
use App\Services\MediaService;
use App\Services\ModerationService;
use App\Services\PaymentMethodService;
use App\Services\ReactionService;
use App\Services\RegistrationValidator;
use App\Services\ReviewService;
use App\Services\Validation\UserRules;
use App\Services\VerifiedMedia;

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/app/database/conn.php';
require dirname(__DIR__) . '/scripts/support/DemoSeeder.php';

$config = testDatabase();
$procedures = new StoredProcedures(new Connection($config));
// Conexión aparte para contar filas: solo ve lo confirmado, como cualquier otro cliente.
$inspect = (new Connection($config))->pdo();
$users = new UserRepository($procedures);
$noCloudinary = new CloudinaryClient('', '', '');
$media = new MediaService($noCloudinary);
$auth = new AuthService($users, new RegistrationValidator(), $noCloudinary);
$categories = new CategoryService(new CategoryRepository($procedures));
$courseRepository = new CourseRepository($procedures);
$validator = new CourseValidator();
$courseService = new CourseService($courseRepository, $validator, $media);
$paypal = new PayPalClient('', '');
$methods = new PaymentMethodService(new PaymentMethodRepository($procedures), $paypal);
$checkout = new CheckoutService(new PurchaseRepository($procedures), $methods, $paypal);
$learning = new LearningService(new LearningRepository($procedures), $media);
$exams = new ExamAttemptService(new ExamAttemptRepository($procedures));
$reviews = new ReviewService(new ReviewRepository($procedures));
$comments = new CommentService(new CommentRepository($procedures));
$reactions = new ReactionService(new ReactionRepository($procedures));
$chats = new ChatService(new ChatRepository($procedures));
$moderation = new ModerationService(new ModerationRepository($procedures));
$maintenance = new MaintenanceRepository($procedures);
$seeder = new DemoSeeder($procedures);

function ruleCode(callable $callback): ?string
{
    return thrown(BusinessRuleException::class, $callback)?->errorCode;
}

/** @param list<scalar> $params */
function countRows(PDO $pdo, string $sql, array $params = []): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn();
}

/** @return array<string, int> filas por tabla */
function snapshot(PDO $pdo): array
{
    $counts = [];
    foreach ($pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $counts[$table] = countRows($pdo, "SELECT COUNT(*) FROM `$table`");
    }

    return $counts;
}

try {
    $admin = $users->findForLogin('admin@cursology.local') ?? throw new RuntimeException('Falta la cuenta admin@cursology.local.');
    restorePaymentMethodsAtExit($methods, $admin);
    // Empieza sin datos de demostración (de una carga o corrida anterior).
    $maintenance->purge(MaintenanceRepository::SCOPE_DEMO, true);

    $mark = date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $account = static fn (string $role, string $email, string $name): array => [
        'rol' => $role, 'nombre' => $name, 'apellido_paterno' => 'Mantenimiento', 'apellido_materno' => '',
        'genero' => 'otro', 'fecha_nacimiento' => '1996-08-30', 'email' => $email, 'password' => 'Segura#2026',
    ];
    $testInstructor = $auth->register($account('instructor', "prueba+$mark-instructor@cursology.test", 'Tomás'), null);
    $testStudent = $auth->register($account('student', "prueba+$mark-alumna@cursology.test", 'Tere'), null);
    $student = $auth->register($account('student', "real+$mark@" . DemoSeeder::DOMAIN, 'Rita'), null);
    $instructor = $auth->register($account('instructor', "real+$mark-instructor@" . DemoSeeder::DOMAIN, 'Ramiro'), null);
    printf('Cuentas de esta corrida: prueba+%s-{instructor,alumna}@cursology.test y real+%s{,-instructor}@%s%s%s', $mark, $mark, DemoSeeder::DOMAIN, PHP_EOL, PHP_EOL);

    // ---------- Un curso de prueba (TC) y uno "real" (RC) con examen, que comparten una categoría ----------
    $onlyTest = $categories->create($admin, ['nombre' => "Prueba $mark mantenimiento"]);
    $shared = $categories->create($admin, ['nombre' => "Prueba $mark compartida"]);
    $reactions->addDefaults($admin);
    $like = array_values(array_filter($reactions->active(), static fn ($r): bool => $r->reactionName() === 'Me gusta'))[0]->id();
    $usedTestReaction = $reactions->create($admin, ['emoji' => '🧪', 'nombre' => "Prueba $mark usada"]);
    $unusedTestReaction = $reactions->create($admin, ['emoji' => '🧹', 'nombre' => "Prueba $mark sin usar"]);
    $mainInput = $validator->validateContent(['tipo' => 'video_principal', 'archivo' => []]);
    $newCourse = static function ($owner, string $title, array $categoryIds) use ($courseRepository, $validator, $mainInput, $mark): array {
        $folder = 'cursology/courses/u' . $owner->id();
        $course = $courseRepository->create($owner->id(), $validator->validateCourse([
            'titulo' => "Mantenimiento $mark $title", 'descripcion' => 'Curso de prueba.', 'certificador' => '', 'precio_modo' => 'gratis',
            'precio' => '', 'categorias' => $categoryIds, 'portada' => [], 'video' => [],
        ], true), "https://res.cloudinary.com/prueba/image/upload/v1/$folder/covers/$title.jpg", "https://res.cloudinary.com/prueba/video/upload/v1/$folder/promos/$title.mp4");
        $level = $courseRepository->createLevel($owner->id(), $course->id(), $validator->validateLevel(['titulo' => "$title 1", 'descripcion' => 'Nivel.', 'costo' => '0', 'video' => []], true), "https://res.cloudinary.com/prueba/video/upload/v1/$folder/levels/$title.mp4");
        $chapter = $courseRepository->createChapter($owner->id(), $level->id(), $validator->validateChapter(['titulo' => "Video $title", 'tipo' => 'video'], true));
        $courseRepository->addContent($owner->id(), $chapter->id(), $mainInput, "Video $title", new VerifiedMedia("$folder/content/$title-$mark", 'video', 'authenticated', 'mp4', 1000, 60, 'v.mp4', 'video/mp4', ''));

        return [$course, $level, $chapter];
    };
    [$testCourse, , $testChapter] = $newCourse($testInstructor, 'TC', [$onlyTest->id(), $shared->id()]);
    $courseService->publish($testInstructor, $testCourse->id());
    [$realCourse, $realLevel, $realChapter] = $newCourse($instructor, 'RC', [$shared->id()]);
    $exam = $courseRepository->createChapter($instructor->id(), $realLevel->id(), $validator->validateChapter(['titulo' => 'Examen RC', 'tipo' => 'examen', 'minimo' => '70'], true));
    [, $examAnswers] = $courseRepository->createQuestion($instructor->id(), $exam->id(), $validator->validateQuestion(['pregunta' => '¿Se borra?', 'modo' => 'unica', 'respuestas' => [
        ['texto' => 'Solo lo de prueba', 'correcta' => true], ['texto' => 'Todo', 'correcta' => false],
    ]]));
    $courseService->publish($instructor, $realCourse->id());

    // La cuenta real usa el curso de prueba; la de prueba usa el curso real.
    $checkout->enrollFree($student, ['curso' => $testCourse->id()]);
    $learning->progress($student, $testChapter->id(), ['posicion' => 10]);
    $commentOnTestCourse = $comments->create($student, $testChapter->id(), ['texto' => 'Comentario de Rita en el curso de prueba.']);
    $checkout->enrollFree($testStudent, ['curso' => $realCourse->id()]);
    $learning->progress($testStudent, $realChapter->id(), ['terminado' => true]);
    $attempt = $exams->start($testStudent, $exam->id());
    $exams->submit($testStudent, $attempt->id, ['respuestas' => [$examAnswers[0]->id()]]);
    $testOpinion = $reviews->save($testStudent, $realCourse->id(), ['calificacion' => 4, 'opinion' => 'Opinión de prueba.'])->opinion;
    $testComment = $comments->create($testStudent, $realChapter->id(), ['texto' => 'Comentario de prueba en el curso real.']);
    $checkout->enrollFree($student, ['curso' => $realCourse->id()]);
    $replyToTest = $comments->create($student, $realChapter->id(), ['texto' => 'Rita responde al comentario de prueba.', 'responde_a' => $testComment->id]);
    $comments->react($student, $testComment->id, ['reaccion' => $like]);
    $moderation->report($student, ReportTarget::Comment, $testComment->id, ['motivo' => 'spam']);
    $reactions->reactToOpinion($student, (string) $testOpinion?->id(), ['reaccion' => $like]);
    $realComment = $comments->create($instructor, $realChapter->id(), ['texto' => 'Bienvenidos al curso real.']);
    $comments->react($student, $realComment->id, ['reaccion' => $usedTestReaction->id()]);
    [$studentChat] = $chats->start($student, ['curso' => $testCourse->id()]);
    $first = $chats->send($student, $studentChat->id(), ['texto' => 'Hola, Tomás.']);
    $chats->send($testInstructor, $studentChat->id(), ['texto' => 'Hola, Rita.', 'responde_a' => $first->id]);
    [$testChat] = $chats->start($testStudent, ['curso' => $realCourse->id()]);
    $question = $chats->send($testStudent, $testChat->id(), ['texto' => 'Una duda de prueba.']);
    $chats->send($instructor, $testChat->id(), ['texto' => 'Respuesta real.', 'responde_a' => $question->id]);
    $reactions->reactToMessage($instructor, $question->id, ['reaccion' => $like]);

    // -----------------------------------------------------------
    Report::section('Ensayo');
    check(ruleCode(static fn () => $maintenance->purge('todo', false)) === 'INVALID_SCOPE', 'un alcance desconocido -> INVALID_SCOPE');
    $before = snapshot($inspect);
    $dry = $maintenance->purge(MaintenanceRepository::SCOPE_TEST, false);
    check(!$dry->applied && snapshot($inspect) === $before, 'el ensayo no borra nada');
    check($dry->deleted['users'] >= 2 && $dry->deleted['courses'] >= 1 && $dry->deleted['comments'] >= 3, 'y cuenta las cuentas, cursos y comentarios que se borrarían');
    check($dry->deleted['chats'] >= 2 && $dry->deleted['messages'] >= 4 && $dry->deleted['reports'] >= 1 && $dry->deleted['attempts'] >= 1 && $dry->deleted['opinions'] >= 1, 'también chats, mensajes, reportes, intentos y opiniones');
    check($dry->deleted['categories'] >= 1 && $dry->deleted['test_reactions'] >= 1, 'y las categorías y reacciones "Prueba <marca>" sin usar');
    check($dry->remaining['admin'] >= 1 && $dry->remaining['student'] >= 1 && $dry->remaining['instructor'] >= 1, 'dice cuántas cuentas quedarían (con el admin)');

    // -----------------------------------------------------------
    Report::section('Borrado de los datos de prueba');
    $applied = $maintenance->purge(MaintenanceRepository::SCOPE_TEST, true);
    check($applied->applied && $applied->deleted === $dry->deleted, 'borra exactamente lo que contó el ensayo');
    check($users->findForLogin($testInstructor->email()) === null && $users->findForLogin($testStudent->email()) === null, 'las cuentas de prueba ya no existen');
    check(countRows($inspect, "SELECT COUNT(*) FROM app_user WHERE email LIKE '%@cursology.test'") === 0, 'no queda ninguna cuenta ...@cursology.test');
    check($users->findForLogin($student->email()) !== null && $users->findForLogin($instructor->email()) !== null && $users->findForLogin('admin@cursology.local') !== null, 'las otras cuentas y el admin siguen');
    check(countRows($inspect, 'SELECT COUNT(*) FROM course WHERE id = ?', [$testCourse->id()]) === 0, 'el curso de prueba se borró');
    check(countRows($inspect, 'SELECT COUNT(*) FROM course WHERE id = ? AND published_at IS NOT NULL', [$realCourse->id()]) === 1, 'el curso real sigue publicado');
    check(countRows($inspect, 'SELECT COUNT(*) FROM user_courses WHERE user_id = ?', [$student->id()]) === 1, 'Rita conserva su inscripción al curso real y pierde la del curso de prueba');
    check(countRows($inspect, 'SELECT COUNT(*) FROM chapter_comment WHERE id IN (?, ?, ?)', [$commentOnTestCourse->id, $testComment->id, $replyToTest->id]) === 0, 'se borran sus comentarios en el curso de prueba, el comentario de prueba y la respuesta de Rita');
    check(countRows($inspect, 'SELECT COUNT(*) FROM chapter_comment WHERE id = ?', [$realComment->id]) === 1, 'el comentario del instructor real sigue');
    check(countRows($inspect, 'SELECT COUNT(*) FROM chapter_comment_reaction WHERE reacted_by = ?', [$student->id()]) === 1, 'Rita solo conserva su reacción al comentario real');
    check(countRows($inspect, 'SELECT COUNT(*) FROM opinion_reaction WHERE reacted_by = ?', [$student->id()]) === 0 && countRows($inspect, 'SELECT COUNT(*) FROM course_opinion WHERE course_id = ?', [$realCourse->id()]) === 0, 'la opinión de prueba y su reacción se borran');
    check(countRows($inspect, 'SELECT COUNT(*) FROM content_report WHERE reported_by = ?', [$student->id()]) === 0, 'el reporte sobre el comentario de prueba se borra');
    check(countRows($inspect, 'SELECT COUNT(*) FROM chat WHERE student_id = ? OR instructor_id = ?', [$student->id(), $instructor->id()]) === 0, 'las conversaciones con cuentas de prueba se borran completas');
    check(countRows($inspect, 'SELECT COUNT(*) FROM test_attempt WHERE course_chapter_id = ?', [$exam->id()]) === 0, 'el intento de examen de prueba en el curso real se borra');
    check(countRows($inspect, 'SELECT COUNT(*) FROM category WHERE id = ?', [$onlyTest->id()]) === 0 && countRows($inspect, 'SELECT COUNT(*) FROM category WHERE id = ?', [$shared->id()]) === 1, 'se borra la categoría de prueba sin cursos y queda la que usa el curso real');
    check(countRows($inspect, 'SELECT COUNT(*) FROM reaction WHERE id = ?', [$unusedTestReaction->id()]) === 0 && countRows($inspect, 'SELECT COUNT(*) FROM reaction WHERE id = ?', [$usedTestReaction->id()]) === 1, 'se borra la reacción de prueba sin usar y queda la que alguien usa');
    $again = $maintenance->purge(MaintenanceRepository::SCOPE_TEST, false);
    check($again->deleted['users'] === 0 && $again->deleted['courses'] === 0, 'otro ensayo ya no encuentra cuentas ni cursos de prueba');

    // -----------------------------------------------------------
    Report::section('Datos de demostración');
    check(!$seeder->alreadySeeded(), 'todavía no hay datos de demostración');
    $notes = $seeder->run($admin, UserRules::generatePassword());
    $demoUsers = static fn (string $role): int => countRows($inspect, "SELECT COUNT(*) FROM app_user WHERE role = ? AND email LIKE '%@demo.cursology.local' AND email NOT LIKE 'real+%'", [$role]);
    check($seeder->alreadySeeded() && $demoUsers('instructor') === 3 && $demoUsers('student') === 6, 'crea 3 instructores y 6 alumnos');
    $demoCourses = "SELECT COUNT(*) FROM course c JOIN app_user u ON u.id = c.created_by WHERE u.email LIKE '%@demo.cursology.local' AND u.email NOT LIKE 'real+%'";
    check(countRows($inspect, $demoCourses) === 6 && countRows($inspect, "$demoCourses AND c.published_at IS NOT NULL") === 5, '6 cursos, 5 publicados');
    check(countRows($inspect, "$demoCourses AND (c.cover_image NOT LIKE 'https://res.cloudinary.com/demo/%' OR c.onboarding_video NOT LIKE 'https://res.cloudinary.com/demo/%')") === 0, 'las portadas y los videos son de la cuenta demo de Cloudinary');
    check(countRows($inspect, "SELECT COUNT(*) FROM user_courses uc JOIN app_user u ON u.id = uc.user_id WHERE u.email LIKE '%@demo.cursology.local' AND uc.completed_at IS NOT NULL") === 4, '4 cursos terminados (para opinar y generar certificados)');
    check(countRows($inspect, "SELECT COUNT(*) FROM purchase p JOIN app_user u ON u.id = p.user_id WHERE u.email LIKE '%@demo.cursology.local' AND p.status = 'completed' AND p.amount > 0") === 5, '5 ventas con la tarjeta simulada');
    check(countRows($inspect, "SELECT COUNT(*) FROM course_opinion o JOIN app_user u ON u.id = o.commented_by WHERE u.email LIKE '%@demo.cursology.local'") === 4, '4 opiniones');
    check(countRows($inspect, "SELECT COUNT(*) FROM test_attempt t JOIN app_user u ON u.id = t.user_id WHERE u.email LIKE '%@demo.cursology.local' AND t.passed = 0") === 1, 'un examen reprobado');
    check(countRows($inspect, "SELECT COUNT(*) FROM content_report r JOIN app_user u ON u.id = r.reported_by WHERE u.email LIKE '%@demo.cursology.local' AND r.status = 'pending'") === 2, 'el comentario de spam tiene 2 reportes pendientes');
    check(countRows($inspect, "SELECT COUNT(*) FROM chat h JOIN app_user u ON u.id = h.student_id WHERE u.email LIKE '%@demo.cursology.local'") === 3, '3 conversaciones');
    check(array_filter($notes, static fn (string $note): bool => str_contains($note, 'categoría')) !== [] || countRows($inspect, "SELECT COUNT(*) FROM category WHERE name IN ('Programación web', 'Bases de datos', 'Diseño') AND active = 1") === 3, 'usa las categorías Programación web, Bases de datos y Diseño');

    $demo = $maintenance->purge(MaintenanceRepository::SCOPE_DEMO, true);
    check($demo->deleted['users'] === 11 && $demo->deleted['courses'] === 7, "'demo' borra las 9 cuentas de demostración (y las 2 de esta prueba) con sus cursos");
    check(!$seeder->alreadySeeded() && $users->findForLogin('admin@cursology.local') !== null, 'quedan sin datos de demostración y con el admin');
    check(countRows($inspect, "SELECT COUNT(*) FROM category WHERE name IN ('Programación web', 'Bases de datos', 'Diseño')") === 3, 'las categorías se conservan');
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf('Error inesperado: %s: %s (%s:%d)%s', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine(), PHP_EOL));
    if ($exception->getPrevious() !== null) {
        fwrite(STDERR, 'Causa: ' . $exception->getPrevious()->getMessage() . PHP_EOL);
    }
    exit(1);
}

Report::finish();
