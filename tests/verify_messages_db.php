<?php

declare(strict_types=1);

/**
 * Verificación de los mensajes entre alumno e instructor contra la base LOCAL, con la migración
 * 2026_11_chat_read.sql aplicada y los SP instalados (app/database/procedures/install.sql).
 *
 *   php tests/verify_messages_db.php
 *
 * Cada corrida deja cuentas de prueba (prueba+<marca>...@cursology.test), una categoría y dos
 * cursos "Mensajes <marca>" (dados de baja al terminar), con sus conversaciones.
 */

use App\Database\Connection;
use App\Database\ProcedureException;
use App\Database\StoredProcedures;
use App\Integrations\CloudinaryClient;
use App\Integrations\PayPalClient;
use App\Models\Dto\ChatSummary;
use App\Models\Entities\AppUser;
use App\Models\Enums\UserRole;
use App\Repositories\CategoryRepository;
use App\Repositories\ChatRepository;
use App\Repositories\CourseRepository;
use App\Repositories\PaymentMethodRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\CategoryService;
use App\Services\ChatService;
use App\Services\CheckoutService;
use App\Services\CourseService;
use App\Services\CourseValidator;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;
use App\Services\MediaService;
use App\Services\PaymentMethodService;
use App\Services\RegistrationValidator;
use App\Services\VerifiedMedia;

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/app/database/conn.php';
require dirname(__DIR__) . '/app/view_helpers.php';

$connection = new Connection($DATABASES['local']);
$procedures = new StoredProcedures($connection);
$users = new UserRepository($procedures);
$noCloudinary = new CloudinaryClient('', '', '');
$auth = new AuthService($users, new RegistrationValidator(), $noCloudinary);
$categories = new CategoryService(new CategoryRepository($procedures));
$courseRepository = new CourseRepository($procedures);
$validator = new CourseValidator();
$courseService = new CourseService($courseRepository, $validator, new MediaService($noCloudinary));
$paypal = new PayPalClient('', '', static fn (): array => throw new RuntimeException('Esta prueba no usa PayPal.'));
$checkout = new CheckoutService(new PurchaseRepository($procedures), new PaymentMethodService(new PaymentMethodRepository($procedures), $paypal), $paypal);
$chatRepository = new ChatRepository($procedures);
$chats = new ChatService($chatRepository);

try {
    $procedures->call('sp_chat_unread_total', [0]);
} catch (ProcedureException $exception) {
    fwrite(STDERR, 'No se pudieron ejecutar los SP de mensajes en la base local. Revisa el .env y que MySQL esté encendido,' . PHP_EOL
        . 'aplica la migración con: SOURCE app/database/migrations/2026_11_chat_read.sql;' . PHP_EOL
        . 'e instala los SP con: SOURCE app/database/procedures/install.sql;' . PHP_EOL
        . 'Detalle: ' . ($exception->getPrevious()?->getMessage() ?? $exception->getMessage()) . PHP_EOL);
    exit(1);
}

function ruleCode(callable $callback): ?string
{
    return thrown(BusinessRuleException::class, $callback)?->errorCode;
}

/** @return list<string>|null */
function failedFields(callable $callback): ?array
{
    $exception = thrown(ValidationException::class, $callback);

    return $exception === null ? null : array_keys($exception->fields);
}

/** @param list<ChatSummary> $list */
function summaryOf(array $list, string $chatId): ?ChatSummary
{
    foreach ($list as $summary) {
        if ($summary->id === $chatId) {
            return $summary;
        }
    }

    return null;
}

/**
 * Ejecuta un archivo .sql de sentencias simples (sin DELIMITER), como lo haría SOURCE.
 */
function runSqlFile(Connection $connection, string $path): void
{
    $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($path));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $connection->pdo()->exec($statement);
    }
}

/** Separa en el tiempo dos pasos cuyo orden importa (las fechas son DATETIME(6)). */
function tick(): void
{
    usleep(3000);
}

try {
    $mark = date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $account = static fn (string $role, string $suffix, string $name): array => [
        'rol' => $role, 'nombre' => $name, 'apellido_paterno' => 'Mensajes', 'apellido_materno' => '',
        'genero' => 'otro', 'fecha_nacimiento' => '1997-03-14', 'email' => "prueba+$mark-$suffix@cursology.test", 'password' => 'Segura#2026',
    ];
    $admin = $users->findForLogin('admin@cursology.local') ?? throw new RuntimeException('Falta la cuenta admin@cursology.local.');

    // -----------------------------------------------------------
    Report::section('Migración');
    $migration = dirname(__DIR__) . '/app/database/migrations/2026_11_chat_read.sql';
    check(thrown(Throwable::class, static fn () => runSqlFile($connection, $migration)) === null, 'primera corrida sin error');
    check(thrown(Throwable::class, static fn () => runSqlFile($connection, $migration)) === null, 'segunda corrida sin error (idempotente)');
    $columns = $connection->pdo()->query("SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat' AND COLUMN_NAME LIKE '%_last_read_at' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
    check(array_map(static fn (array $c): string => implode('|', $c), $columns) === ['student_last_read_at|YES|datetime(6)', 'instructor_last_read_at|YES|datetime(6)'], 'las dos columnas, DATETIME(6) NULL y una sola vez');

    $instructor = $auth->register($account('instructor', 'instructor', 'Irene'), null);
    $otherInstructor = $auth->register($account('instructor', 'otro', 'Omar'), null);
    $ana = $auth->register($account('student', 'alumna-a', 'Ana'), null);
    $beto = $auth->register($account('student', 'alumno-b', 'Beto'), null);
    $carla = $auth->register($account('student', 'alumna-c', 'Carla'), null);
    printf('Cuentas de prueba de esta corrida: prueba+%s-{instructor,otro,alumna-a,alumno-b,alumna-c}@cursology.test%s%s', $mark, PHP_EOL, PHP_EOL);

    // ---------- Cursos gratis: X de Irene (Ana y Beto inscritos) y W de Omar (Ana inscrita) ----------
    $category = $categories->create($admin, ['nombre' => "Prueba $mark mensajes"]);
    $mainInput = $validator->validateContent(['tipo' => 'video_principal', 'archivo' => []]);
    $publishedCourse = static function (AppUser $owner, string $title) use ($courseRepository, $courseService, $validator, $mainInput, $category, $mark) {
        $folder = 'cursology/courses/u' . $owner->id();
        $course = $courseRepository->create($owner->id(), $validator->validateCourse([
            'titulo' => "Mensajes $mark $title", 'descripcion' => 'Curso de prueba.', 'certificador' => '', 'precio_modo' => 'gratis',
            'precio' => '', 'categorias' => [$category->id()], 'portada' => [], 'video' => [],
        ], true), "https://res.cloudinary.com/prueba/image/upload/v1/$folder/covers/$title.jpg", "https://res.cloudinary.com/prueba/video/upload/v1/$folder/promos/$title.mp4");
        $level = $courseRepository->createLevel($owner->id(), $course->id(), $validator->validateLevel(['titulo' => "$title 1", 'descripcion' => 'Nivel.', 'costo' => '0', 'video' => []], true), "https://res.cloudinary.com/prueba/video/upload/v1/$folder/levels/$title-$mark.mp4");
        $chapter = $courseRepository->createChapter($owner->id(), $level->id(), $validator->validateChapter(['titulo' => "$title capítulo", 'tipo' => 'video'], true));
        $courseRepository->addContent($owner->id(), $chapter->id(), $mainInput, "$title capítulo", new VerifiedMedia("$folder/content/$title-$mark", 'video', 'authenticated', 'mp4', 1000, 60, 'v.mp4', 'video/mp4', ''));
        $courseService->publish($owner, $course->id());

        return $course;
    };
    $courseX = $publishedCourse($instructor, 'X');
    $courseW = $publishedCourse($otherInstructor, 'W');
    $checkout->enrollFree($ana, ['curso' => $courseX->id()]);
    $checkout->enrollFree($beto, ['curso' => $courseX->id()]);
    $checkout->enrollFree($ana, ['curso' => $courseW->id()]);

    // -----------------------------------------------------------
    Report::section('Iniciar una conversación');
    [$chatA, $created] = $chats->start($ana, ['curso' => $courseX->id()]);
    check($created && $chatA->courseId() === $courseX->id() && $chatA->instructorId() === $instructor->id() && $chatA->studentId() === $ana->id(), 'la alumna inscrita la inicia con el instructor dueño del curso');
    check($chatA->studentLastReadAt() === null && $chatA->instructorLastReadAt() === null, 'recién creada, nadie la ha leído');
    [$again, $createdAgain] = $chats->start($ana, ['curso' => (int) $courseX->id()]);
    check($again->id() === $chatA->id() && !$createdAgain, 'iniciarla otra vez devuelve la misma (created = false)');
    [$fromInstructor, $createdByInstructor] = $chats->start($instructor, ['curso' => $courseX->id(), 'alumno' => $ana->id()]);
    check($fromInstructor->id() === $chatA->id() && !$createdByInstructor, 'el instructor llega a la misma conversación con esa alumna');
    check(ruleCode(static fn () => $chats->start($carla, ['curso' => $courseX->id()])) === 'NOT_ENROLLED', 'una alumna no inscrita -> NOT_ENROLLED');
    check(ruleCode(static fn () => $chats->start($otherInstructor, ['curso' => $courseX->id(), 'alumno' => $ana->id()])) === 'NOT_ALLOWED', 'otro instructor -> NOT_ALLOWED');
    check(ruleCode(static fn () => $chats->start($instructor, ['curso' => $courseX->id(), 'alumno' => $carla->id()])) === 'STUDENT_NOT_ENROLLED', 'el instructor con una alumna no inscrita -> STUDENT_NOT_ENROLLED');
    check(ruleCode(static fn () => $chats->start($instructor, ['curso' => $courseX->id(), 'alumno' => $instructor->id()])) === 'NOT_ALLOWED', 'el instructor consigo mismo -> NOT_ALLOWED');
    check(ruleCode(static fn () => $chats->start($ana, ['curso' => '999999999999'])) === 'COURSE_NOT_FOUND', 'un curso que no existe -> COURSE_NOT_FOUND');
    check(failedFields(static fn () => $chats->start($instructor, ['curso' => $courseX->id()])) === ['alumno'], 'el instructor sin alumno -> alumno');
    tick();
    [$chatB, $createdB] = $chats->start($instructor, ['curso' => $courseX->id(), 'alumno' => $beto->id()]);
    check($createdB && $chatB->studentId() === $beto->id() && $chatB->id() !== $chatA->id(), 'el instructor dueño la inicia con un alumno inscrito');
    [$chatW] = $chats->start($ana, ['curso' => $courseW->id()]);
    check($chatW->instructorId() === $otherInstructor->id(), 'otro curso, otra conversación (con su instructor)');

    // -----------------------------------------------------------
    Report::section('Enviar y responder');
    tick();
    $m1 = $chats->send($ana, $chatA->id(), ['texto' => "  Hola profe,\n¿el capítulo 2 tiene tarea? <b>gracias</b>  "]);
    check($m1->sentBy === $ana->id() && $m1->senderName === 'Ana Mensajes' && $m1->content === "Hola profe,\n¿el capítulo 2 tiene tarea? <b>gracias</b>", 'se guarda sin los espacios de los lados y tal cual (el HTML se escapa en la vista)');
    check($m1->replyTo === null && $m1->replySenderName === null, 'sin respuesta');
    tick();
    $openedByInstructor = $chats->open($instructor, $chatA->id());
    check(array_map(static fn ($m): string => $m->id, $openedByInstructor->messages) === [$m1->id], 'el instructor ve el mensaje');
    check($openedByInstructor->viewerRole === UserRole::Instructor && $openedByInstructor->otherName() === 'Ana Mensajes' && $openedByInstructor->courseTitle === "Mensajes $mark X", 'con la alumna y el curso');
    tick();
    $m2 = $chats->send($instructor, $chatA->id(), ['texto' => 'No, solo el examen del final.', 'responde_a' => $m1->id]);
    check($m2->replyTo === $m1->id && $m2->replySenderName === 'Ana Mensajes' && $m2->replyContent === $m1->content, 'la respuesta trae el autor y el texto del mensaje citado');
    $mb = $chats->send($beto, $chatB->id(), ['texto' => 'Hola, soy Beto.']);
    check(ruleCode(static fn () => $chats->send($ana, $chatA->id(), ['texto' => 'Hola', 'responde_a' => $mb->id])) === 'MESSAGE_NOT_FOUND', 'responder con un mensaje de otra conversación -> MESSAGE_NOT_FOUND');
    check(ruleCode(static fn () => $chats->send($ana, $chatA->id(), ['texto' => 'Hola', 'responde_a' => '999999999999'])) === 'MESSAGE_NOT_FOUND', 'responder a un mensaje que no existe -> MESSAGE_NOT_FOUND');
    check(ruleCode(static fn () => $chatRepository->send($ana->id(), $chatA->id(), '   ', null)) === 'INVALID_MESSAGE', 'el SP también rechaza el texto vacío -> INVALID_MESSAGE');
    check(ruleCode(static fn () => $chatRepository->send($ana->id(), $chatA->id(), str_repeat('a', 2001), null)) === 'INVALID_MESSAGE', 'y el de más de 2000 caracteres');

    // -----------------------------------------------------------
    Report::section('Solo los participantes');
    foreach (['Carla (alumna ajena)' => $carla, 'Beto (alumno de otra conversación)' => $beto, 'Omar (otro instructor)' => $otherInstructor] as $label => $outsider) {
        check(ruleCode(static fn () => $chats->open($outsider, $chatA->id())) === 'CHAT_NOT_FOUND', "$label no la abre -> CHAT_NOT_FOUND");
        check(ruleCode(static fn () => $chats->send($outsider, $chatA->id(), ['texto' => 'Hola'])) === 'CHAT_NOT_FOUND', "$label no escribe en ella -> CHAT_NOT_FOUND");
    }
    check(ruleCode(static fn () => $chats->open($ana, '999999999999')) === 'CHAT_NOT_FOUND', 'una conversación que no existe -> CHAT_NOT_FOUND');
    check(summaryOf($chats->list($carla), $chatA->id()) === null && $chats->list($carla) === [], 'Carla no tiene conversaciones');

    // -----------------------------------------------------------
    Report::section('No leídos');
    check($chats->unreadTotal($ana) === 1, 'Ana: 1 no leído (la respuesta del instructor)');
    $anaSummary = summaryOf($chats->list($ana), $chatA->id());
    check($anaSummary?->unreadCount === 1 && $anaSummary->lastMessageId === $m2->id && $anaSummary->lastMessageMine === false, 'en su lista: 1 no leído y el último es del instructor');
    check($anaSummary->viewerRole === UserRole::Student && $anaSummary->otherName === 'Irene Mensajes' && $anaSummary->excerpt() === 'No, solo el examen del final.', 'con el instructor y el extracto');
    check($chats->unreadTotal($instructor) === 1, 'Irene: 1 no leído (el de Beto); lo que ella envía no cuenta');
    check(summaryOf($chats->list($instructor), $chatA->id())?->unreadCount === 0, 'la conversación con Ana ya está leída para Irene (la abrió y respondió)');
    tick();
    $anaOpen = $chats->open($ana, $chatA->id());
    check($anaOpen->unreadTotal === 0 && $chats->unreadTotal($ana) === 0, 'al abrirla, Ana queda en 0');
    check(array_map(static fn ($m): string => $m->id, $anaOpen->messages) === [$m1->id, $m2->id], 'los mensajes, del más antiguo al más nuevo');
    tick();
    $chats->send($beto, $chatB->id(), ['texto' => 'Otra duda.']);
    check($chats->unreadTotal($instructor) === 2 && summaryOf($chats->list($instructor), $chatB->id())?->unreadCount === 2, 'Irene: 2 de Beto en total y en la lista');
    tick();
    check($chats->open($instructor, $chatB->id())->unreadTotal === 0 && $chats->unreadTotal($instructor) === 0, 'al abrir la de Beto, el total del menú baja a 0');
    check($chats->unreadTotal($otherInstructor) === 0, 'Omar no tiene no leídos');

    // -----------------------------------------------------------
    Report::section('Solo los nuevos (after)');
    check($chats->open($ana, $chatA->id(), $m2->id)->messages === [], 'después del último: nada nuevo');
    tick();
    $m3 = $chats->send($instructor, $chatA->id(), ['texto' => 'Y revisa los recursos del nivel.']);
    $newOnes = $chats->open($ana, $chatA->id(), $m2->id);
    check(array_map(static fn ($m): string => $m->id, $newOnes->messages) === [$m3->id] && $newOnes->unreadTotal === 0, 'trae solo el nuevo y lo marca leído');
    check(array_map(static fn ($m): string => $m->id, $chats->open($ana, $chatA->id(), $m1->id)->messages) === [$m2->id, $m3->id], 'después del primero: los dos siguientes');
    check(array_map(static fn ($m): string => $m->id, $chatRepository->open($ana->id(), $chatA->id(), null, 2)->messages) === [$m2->id, $m3->id], 'sin after y con límite 2: los últimos 2, en orden');
    check(array_map(static fn ($m): string => $m->id, $chatRepository->open($ana->id(), $chatA->id(), $m1->id, 1)->messages) === [$m2->id], 'con after y límite 1: el siguiente');
    check($chats->open($ana, $chatA->id())->lastMessageId() === $m3->id, 'lastMessageId es el más nuevo');

    // -----------------------------------------------------------
    Report::section('Orden de la lista');
    $order = static fn (AppUser $user): array => array_map(static fn (ChatSummary $s): string => $s->id, $chats->list($user));
    check($order($instructor) === [$chatA->id(), $chatB->id()], 'Irene: primero la de actividad más reciente (Ana)');
    tick();
    $chats->send($beto, $chatB->id(), ['texto' => '¡Gracias!']);
    check($order($instructor) === [$chatB->id(), $chatA->id()], 'un mensaje nuevo de Beto la sube');
    check($order($ana) === [$chatA->id(), $chatW->id()], 'Ana: la que tiene mensajes va antes que la vacía (creada antes)');
    $emptyW = summaryOf($chats->list($ana), $chatW->id());
    check($emptyW?->lastMessageId === null && $emptyW->excerpt() === '' && $emptyW->otherName === 'Omar Mensajes' && $emptyW->unreadCount === 0, 'la vacía: sin último mensaje, con su instructor');

    // -----------------------------------------------------------
    Report::section('Curso dado de baja');
    $courseService->setActive($instructor, $courseX->id(), ['activo' => false]);
    [$stillThere, $createdAfter] = $chats->start($ana, ['curso' => $courseX->id()]);
    check($stillThere->id() === $chatA->id() && !$createdAfter, 'la conversación sigue disponible');
    $late = $chats->send($ana, $chatA->id(), ['texto' => 'Aunque ya no se venda, gracias.']);
    check($late->content === 'Aunque ya no se venda, gracias.', 'y se puede seguir escribiendo');
    check(summaryOf($chats->list($instructor), $chatA->id())?->courseActive === false, 'la lista indica que el curso está dado de baja');

    // Limpieza: cursos de prueba dados de baja y su categoría desactivada.
    $courseService->setActive($otherInstructor, $courseW->id(), ['activo' => false]);
    $categories->update($admin, $category->id(), ['activa' => false]);
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf('Error inesperado: %s: %s (%s:%d)%s', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine(), PHP_EOL));
    if ($exception->getPrevious() !== null) {
        fwrite(STDERR, 'Causa: ' . $exception->getPrevious()->getMessage() . PHP_EOL);
    }
    exit(1);
}

Report::finish();
