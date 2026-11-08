<?php

declare(strict_types=1);

/**
 * Verificación de la moderación (reportes, cola, todo el contenido, restaurar) y de las cuentas
 * (suspender, quitar la suspensión, dar de baja, reactivar e historial) contra la base LOCAL, con
 * la migración 2026_11_content_report.sql aplicada y los SP instalados.
 *
 *   php tests/verify_moderation_db.php
 *
 * Cada corrida deja cuentas de prueba (prueba+<marca>...@cursology.test), una categoría y un
 * curso "Moderación <marca>" (dado de baja al terminar), con sus comentarios, opiniones y
 * reportes. Todas las cuentas de prueba terminan activas y sin ban.
 */

use App\Database\Connection;
use App\Database\StoredProcedures;
use App\Integrations\CloudinaryClient;
use App\Integrations\PayPalClient;
use App\Models\Dto\ModerationItem;
use App\Models\Dto\ModerationReport;
use App\Models\Entities\AppUser;
use App\Models\Enums\AccountStatus;
use App\Models\Enums\ReportReason;
use App\Models\Enums\ReportTarget;
use App\Repositories\CatalogRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\CommentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\LearningRepository;
use App\Repositories\ModerationRepository;
use App\Repositories\PaymentMethodRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\ReviewRepository;
use App\Repositories\UserRepository;
use App\Services\AccountAdminService;
use App\Services\AuthService;
use App\Services\CatalogService;
use App\Services\CategoryService;
use App\Services\CheckoutService;
use App\Services\CommentService;
use App\Services\CourseService;
use App\Services\CourseValidator;
use App\Services\Exceptions\AccountLockedException;
use App\Services\Exceptions\AccountUnavailableException;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\InvalidCredentialsException;
use App\Services\Exceptions\ValidationException;
use App\Services\LearningService;
use App\Services\MediaService;
use App\Services\ModerationService;
use App\Services\PaymentMethodService;
use App\Services\ProfileService;
use App\Services\ProfileValidator;
use App\Services\RegistrationValidator;
use App\Services\ReviewService;
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
$media = new MediaService($noCloudinary);
$courseService = new CourseService($courseRepository, $validator, $media);
$learning = new LearningService(new LearningRepository($procedures), $media);
$paypal = new PayPalClient('', '', static fn (): array => throw new RuntimeException('Esta prueba no usa PayPal.'));
$checkout = new CheckoutService(new PurchaseRepository($procedures), new PaymentMethodService(new PaymentMethodRepository($procedures), $paypal), $paypal);
$catalog = new CatalogService(new CatalogRepository($procedures));
$comments = new CommentService(new CommentRepository($procedures));
$reviews = new ReviewService(new ReviewRepository($procedures));
$moderationRepository = new ModerationRepository($procedures);
$moderation = new ModerationService($moderationRepository);
$accounts = new AccountAdminService($users);
$profiles = new ProfileService($users, new ProfileValidator(), $noCloudinary);

/** Ejecuta un archivo .sql de sentencias simples (sin DELIMITER), como lo haría SOURCE. */
function runSqlFile(Connection $connection, string $path): void
{
    $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($path));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $connection->pdo()->exec($statement);
    }
}

try {
    runSqlFile($connection, dirname(__DIR__) . '/app/database/migrations/2026_11_content_report.sql');
    $procedures->call('sp_moderation_reports', [0, 1, 0]);
} catch (Throwable $exception) {
    if (!($exception instanceof App\Database\ProcedureException) || $exception->businessCode !== 'NOT_ADMIN') {
        fwrite(STDERR, 'No se pudieron ejecutar los SP de moderación en la base local. Revisa el .env y que MySQL esté encendido,' . PHP_EOL
            . 'aplica la migración con: SOURCE app/database/migrations/2026_11_content_report.sql;' . PHP_EOL
            . 'e instala los SP con: SOURCE app/database/procedures/install.sql;' . PHP_EOL
            . 'Detalle: ' . ($exception->getPrevious()?->getMessage() ?? $exception->getMessage()) . PHP_EOL);
        exit(1);
    }
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

/** @param list<ModerationReport|ModerationItem> $items */
function findItem(array $items, ReportTarget $type, string $id): ModerationReport|ModerationItem|null
{
    foreach ($items as $item) {
        if ($item->targetType === $type && $item->targetId === $id) {
            return $item;
        }
    }

    return null;
}

/** Separa en el tiempo dos pasos cuyo orden importa (las fechas son DATETIME(6)). */
function tick(): void
{
    usleep(3000);
}

try {
    $mark = date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $account = static fn (string $role, string $suffix, string $name): array => [
        'rol' => $role, 'nombre' => $name, 'apellido_paterno' => 'Moderación', 'apellido_materno' => '',
        'genero' => 'otro', 'fecha_nacimiento' => '1997-03-14', 'email' => "prueba+$mark-$suffix@cursology.test", 'password' => 'Segura#2026',
    ];
    $admin = $users->findForLogin('admin@cursology.local') ?? throw new RuntimeException('Falta la cuenta admin@cursology.local.');

    // -----------------------------------------------------------
    Report::section('Migración');
    check(thrown(Throwable::class, static fn () => runSqlFile($connection, dirname(__DIR__) . '/app/database/migrations/2026_11_content_report.sql')) === null, 'correrla otra vez no falla (idempotente)');
    $columns = (int) $connection->pdo()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_report'")->fetchColumn();
    check($columns === 11, 'content_report existe con sus 11 columnas');

    $instructor = $auth->register($account('instructor', 'instructor', 'Irene'), null);
    $otherInstructor = $auth->register($account('instructor', 'otro', 'Omar'), null);
    $ana = $auth->register($account('student', 'alumna-a', 'Ana'), null);
    $beto = $auth->register($account('student', 'alumno-b', 'Beto'), null);
    $carla = $auth->register($account('student', 'alumna-c', 'Carla'), null);
    printf('Cuentas de prueba de esta corrida: prueba+%s-{instructor,otro,alumna-a,alumno-b,alumna-c}@cursology.test%s%s', $mark, PHP_EOL, PHP_EOL);

    // ---------- Curso gratis de Irene con un capítulo de video; Ana y Beto inscritos ----------
    $category = $categories->create($admin, ['nombre' => "Prueba $mark moderación"]);
    $folder = 'cursology/courses/u' . $instructor->id();
    $course = $courseRepository->create($instructor->id(), $validator->validateCourse([
        'titulo' => "Moderación $mark", 'descripcion' => 'Curso de prueba.', 'certificador' => '', 'precio_modo' => 'gratis',
        'precio' => '', 'categorias' => [$category->id()], 'portada' => [], 'video' => [],
    ], true), "https://res.cloudinary.com/prueba/image/upload/v1/$folder/covers/m.jpg", "https://res.cloudinary.com/prueba/video/upload/v1/$folder/promos/m.mp4");
    $level = $courseRepository->createLevel($instructor->id(), $course->id(), $validator->validateLevel(['titulo' => 'Nivel', 'descripcion' => 'Nivel.', 'costo' => '0', 'video' => []], true), "https://res.cloudinary.com/prueba/video/upload/v1/$folder/levels/m-$mark.mp4");
    $chapter = $courseRepository->createChapter($instructor->id(), $level->id(), $validator->validateChapter(['titulo' => 'Capítulo', 'tipo' => 'video'], true));
    $courseRepository->addContent($instructor->id(), $chapter->id(), $validator->validateContent(['tipo' => 'video_principal', 'archivo' => []]), 'Capítulo',
        new VerifiedMedia("$folder/content/m-$mark", 'video', 'authenticated', 'mp4', 1000, 60, 'v.mp4', 'video/mp4', ''));
    $courseService->publish($instructor, $course->id());
    $checkout->enrollFree($ana, ['curso' => $course->id()]);
    $checkout->enrollFree($beto, ['curso' => $course->id()]);
    $learning->progress($ana, $chapter->id(), ['terminado' => true]);
    $opinion = $reviews->save($ana, $course->id(), ['calificacion' => 2, 'opinion' => "Opinión $mark: no me gustó."])->opinion
        ?? throw new RuntimeException('La opinión no se guardó.');
    $c1 = $comments->create($beto, $chapter->id(), ['texto' => "Comentario $mark: compro seguidores baratos"]);
    tick();
    $c2 = $comments->create($beto, $chapter->id(), ['texto' => "Comentario $mark: la respuesta del examen es la B"]);
    tick();
    $c3 = $comments->create($beto, $chapter->id(), ['texto' => "Comentario $mark: me equivoqué"]);

    // -----------------------------------------------------------
    Report::section('Reportar');
    check($moderation->report($ana, ReportTarget::Comment, $c1->id, ['motivo' => 'spam']) === true, 'la alumna reporta un comentario de otro');
    check($moderation->report($ana, ReportTarget::Comment, $c1->id, ['motivo' => 'offensive']) === false, 'reportarlo otra vez no hace nada');
    check(ruleCode(static fn () => $moderation->report($beto, ReportTarget::Comment, $c1->id, ['motivo' => 'spam'])) === 'CANNOT_REPORT_OWN', 'el propio no -> CANNOT_REPORT_OWN');
    check(ruleCode(static fn () => $moderation->report($carla, ReportTarget::Comment, $c1->id, ['motivo' => 'spam'])) === 'COMMENT_NOT_FOUND', 'sin acceso al capítulo -> COMMENT_NOT_FOUND');
    check(ruleCode(static fn () => $moderation->report($ana, ReportTarget::Comment, '999999999999', ['motivo' => 'spam'])) === 'COMMENT_NOT_FOUND', 'un comentario que no existe');
    check(ruleCode(static fn () => $moderationRepository->report($ana->id(), ReportTarget::Comment, $c1->id, ReportReason::Other, null)) === 'INVALID_REPORT', 'el SP también exige el detalle con "Otro" -> INVALID_REPORT');
    $moderation->report($ana, ReportTarget::Comment, $c2->id, ['motivo' => 'answers']);
    $moderation->report($ana, ReportTarget::Comment, $c3->id, ['motivo' => 'spam']);
    tick();
    check($moderation->report($otherInstructor, ReportTarget::Opinion, $opinion->id(), ['motivo' => 'offensive']) === true, 'un instructor reporta una opinión (sin estar inscrito)');
    tick();
    check($moderation->report($beto, ReportTarget::Opinion, $opinion->id(), ['motivo' => 'other', 'detalle' => 'Insulta al instructor']) === true, 'otro alumno con "Otro" y detalle');
    check(ruleCode(static fn () => $moderation->report($ana, ReportTarget::Opinion, $opinion->id(), ['motivo' => 'spam'])) === 'CANNOT_REPORT_OWN', 'la autora no reporta su opinión');
    $anaView = $comments->list($ana, $chapter->id())->comments;
    $betoView = $comments->list($beto, $chapter->id())->comments;
    check(array_values(array_filter($anaView, static fn ($c): bool => $c->id === $c1->id))[0]->reportedByMe
        && !array_values(array_filter($betoView, static fn ($c): bool => $c->id === $c1->id))[0]->reportedByMe, 'la lista marca "Reportado" solo para quien reportó');
    $forBeto = $reviews->list($course->id(), [], $beto)->reviews[0];
    $forAna = $reviews->list($course->id(), [], $ana)->reviews[0];
    check($forBeto->reportedByMe && !$forBeto->mine && $forAna->mine && !$forAna->reportedByMe, 'en las opiniones: reportedByMe y mine por quien consulta');
    check(!$reviews->list($course->id())->reviews[0]->reportedByMe, 'un visitante no ve nada reportado');

    // -----------------------------------------------------------
    Report::section('Cola de moderación');
    check(ruleCode(static fn () => $moderation->reports($ana)) === 'NOT_ADMIN', 'solo el admin ve la cola -> NOT_ADMIN');
    $queue = $moderation->reports($admin);
    $opinionRow = findItem($queue->items, ReportTarget::Opinion, $opinion->id());
    $c1Row = findItem($queue->items, ReportTarget::Comment, $c1->id);
    check($opinionRow !== null && $c1Row !== null && $queue->total >= 4 && $queue->reportTotal >= 5, 'un renglón por contenido reportado (4) con sus reportes (5)');
    check($opinionRow->reportCount === 2 && $opinionRow->reasonCounts === ['spam' => 0, 'offensive' => 1, 'answers' => 0, 'other' => 1] && $opinionRow->lastDetails === 'Insulta al instructor', 'la opinión: 2 reportes, sus motivos y el último detalle');
    check($opinionRow->chapterId === null && $c1Row->chapterTitle === 'Capítulo' && $c1Row->authorEmail === $beto->email() && $c1Row->content === $c1->content, 'con el texto, el autor y dónde está');
    check($queue->items[0]->targetType === ReportTarget::Opinion && $queue->items[0]->targetId === $opinion->id(), 'del reporte más reciente al más antiguo');
    check(count($moderation->summary($admin)->items) <= 3 && $moderation->summary($admin)->total === $queue->total, 'el resumen del panel: los 3 más recientes y el mismo total');

    // -----------------------------------------------------------
    Report::section('Resolver la cola');
    $comments->hide($admin, $c1->id, ['motivo' => 'Spam']);
    check(findItem($moderation->reports($admin)->items, ReportTarget::Comment, $c1->id) === null, 'ocultar resuelve sus reportes: sale de la cola');
    $comments->hide($instructor, $c2->id, ['motivo' => 'Comparte las respuestas']);
    check(findItem($moderation->reports($admin)->items, ReportTarget::Comment, $c2->id) === null, 'también si lo oculta el instructor del curso');
    $comments->delete($beto, $c3->id);
    check(findItem($moderation->reports($admin)->items, ReportTarget::Comment, $c3->id) === null, 'y si su autor lo elimina');
    check($moderation->dismiss($admin, ReportTarget::Opinion, $opinion->id()) === 2, '"Descartar" descarta sus 2 reportes');
    check(findItem($moderation->reports($admin)->items, ReportTarget::Opinion, $opinion->id()) === null && $reviews->list($course->id())->total === 1, 'sale de la cola y la opinión sigue visible');
    check($moderation->dismiss($admin, ReportTarget::Opinion, $opinion->id()) === 0, 'descartar otra vez: 0');
    check($moderation->report($beto, ReportTarget::Opinion, $opinion->id(), ['motivo' => 'spam']) === false && findItem($moderation->reports($admin)->items, ReportTarget::Opinion, $opinion->id()) === null, 'quien ya la había reportado no la vuelve a poner en la cola');
    $moderation->report($carla, ReportTarget::Opinion, $opinion->id(), ['motivo' => 'spam']);
    check(findItem($moderation->reports($admin)->items, ReportTarget::Opinion, $opinion->id())?->reportCount === 1, 'un reporte nuevo de otra persona sí la regresa (con 1)');
    check(ruleCode(static fn () => $moderation->report($carla, ReportTarget::Comment, $c1->id, ['motivo' => 'spam'])) === 'COMMENT_NOT_FOUND', 'lo oculto ya no se reporta');

    // -----------------------------------------------------------
    Report::section('Todo el contenido');
    check(ruleCode(static fn () => $moderation->content($beto)) === 'NOT_ADMIN', 'solo el admin -> NOT_ADMIN');
    $mine = $moderation->content($admin, ['q' => "Comentario $mark"]);
    check($mine->total === 3 && count($mine->items) === 3, 'la búsqueda encuentra los 3 comentarios de la prueba (también los ocultos)');
    check(array_map(static fn (ModerationItem $i): string => $i->targetId, $mine->items) === [$c3->id, $c2->id, $c1->id], 'del más reciente al más antiguo');
    $hidden = $moderation->content($admin, ['q' => $mark, 'estado' => 'ocultos', 'tipo' => 'comment']);
    $c1Item = findItem($hidden->items, ReportTarget::Comment, $c1->id);
    $c3Item = findItem($hidden->items, ReportTarget::Comment, $c3->id);
    check($hidden->total === 3 && $c1Item?->restorable === true && $c1Item->statusLabel() === 'Oculto por ' . $admin->name() . ' ' . $admin->fatherLastName() . ($admin->motherLastName() ? ' ' . $admin->motherLastName() : '') . ': Spam', 'ocultos: por quién y por qué');
    check($c3Item?->removedByAuthor === true && $c3Item->restorable === false && $c3Item->statusLabel() === 'Eliminado por su autor', 'el eliminado por su autor no se restaura');
    check($moderation->content($admin, ['q' => $mark, 'tipo' => 'opinion'])->items[0]->reportCount === 3, 'opiniones: con cuántos reportes ha tenido');
    check($moderation->content($admin, ['q' => $beto->email(), 'estado' => 'visibles'])->total === 0, 'por email del autor: Beto ya no tiene visibles');
    check($moderation->content($admin, ['q' => '%_' . $mark])->total === 0, 'los % y _ de la búsqueda son literales');

    // -----------------------------------------------------------
    Report::section('Restaurar');
    $moderation->restore($admin, ReportTarget::Comment, $c1->id);
    check(in_array($c1->id, array_map(static fn ($c): string => $c->id, $comments->list($ana, $chapter->id())->comments), true), 'el comentario oculto vuelve a verse');
    check(ruleCode(static fn () => $moderation->restore($admin, ReportTarget::Comment, $c1->id)) === 'NOT_RESTORABLE', 'restaurar uno visible -> NOT_RESTORABLE');
    check(ruleCode(static fn () => $moderation->restore($admin, ReportTarget::Comment, $c3->id)) === 'NOT_RESTORABLE', 'ni lo que eliminó su autor');
    check(ruleCode(static fn () => $moderation->restore($instructor, ReportTarget::Comment, $c2->id)) === 'NOT_ADMIN', 'solo el admin restaura -> NOT_ADMIN');
    $reviews->hide($instructor, $opinion->id(), ['motivo' => 'No aporta']);
    check($reviews->list($course->id())->summary->ratingCount === 0, 'con la opinión oculta sus estrellas no cuentan');
    $moderation->restore($admin, ReportTarget::Opinion, $opinion->id());
    check($reviews->list($course->id())->total === 1 && $reviews->list($course->id())->summary->ratingAvg === '2.0', 'al restaurarla vuelve a listarse y a contar');

    // -----------------------------------------------------------
    Report::section('Suspender (ban)');
    check(ruleCode(static fn () => $accounts->ban($ana, $beto->id(), ['motivo' => 'Spam'])) === 'NOT_ADMIN', 'solo el admin suspende -> NOT_ADMIN');
    check(ruleCode(static fn () => $accounts->ban($admin, $admin->id(), ['motivo' => 'Prueba'])) === 'CANNOT_BAN_ADMIN', 'a un admin (tampoco a sí mismo) -> CANNOT_BAN_ADMIN');
    $inCatalog = static fn (): bool => in_array($course->id(), array_map(static fn ($c): string => $c->id, $catalog->search(['q' => "Moderación $mark"])->courses), true);
    check($inCatalog(), 'antes: el curso está en el catálogo');
    $banned = $accounts->ban($admin, $instructor->id(), ['motivo' => 'Publica contenido engañoso']);
    check($banned->ban() && $banned->banReason() === 'Publica contenido engañoso', 'la instructora queda suspendida con su motivo');
    check(ruleCode(static fn () => $accounts->ban($admin, $instructor->id(), ['motivo' => 'Otra vez'])) === 'ALREADY_BANNED', 'suspenderla otra vez -> ALREADY_BANNED');
    $loginError = thrown(AccountUnavailableException::class, static fn () => $auth->login($instructor->email(), 'Segura#2026'));
    check($loginError !== null && $loginError->banned && $loginError->getMessage() === AccountUnavailableException::BANNED_MESSAGE, 'ya no inicia sesión: "suspendida"');
    check($auth->userForSession($instructor->id()) === null, 'y su sesión abierta se corta');
    check(!$inCatalog() && ruleCode(static fn () => $catalog->course($course->id())) === 'COURSE_NOT_FOUND', 'su curso sale del catálogo y de su página pública');
    check(ruleCode(static fn () => $checkout->enrollFree($carla, ['curso' => $course->id()])) === 'COURSE_NOT_FOUND', 'y ya no se inscribe nadie nuevo');
    check($learning->course($ana, $course->id())->id === $course->id(), 'pero sus alumnos conservan el acceso');
    check(in_array($instructor->id(), array_map(static fn ($r): string => $r->id, $accounts->users($admin, ['q' => $mark, 'estado' => 'suspendidas'])->users), true), 'aparece en el filtro "Suspendidas"');
    $accounts->unban($admin, $instructor->id());
    check($auth->login($instructor->email(), 'Segura#2026')->id() === $instructor->id() && $inCatalog(), 'al quitarle la suspensión vuelve a entrar y su curso al catálogo');
    check(ruleCode(static fn () => $accounts->unban($admin, $instructor->id())) === 'NOT_BANNED', 'quitarla otra vez -> NOT_BANNED');

    // -----------------------------------------------------------
    Report::section('Dar de baja la cuenta y reactivar');
    check(failedFields(static fn () => $profiles->deactivate($beto, ['password' => 'Mala#2026'])) === ['password'], 'con la contraseña incorrecta no se da de baja');
    check(ruleCode(static fn () => $profiles->deactivate($admin, ['password' => 'x'])) === 'ADMIN_CANNOT_DEACTIVATE', 'un admin no se da de baja');
    $profiles->deactivate($beto, ['password' => 'Segura#2026', 'motivo' => 'Ya terminé lo que necesitaba']);
    $betoNow = $users->findById($beto->id());
    check($betoNow?->accountStatus() === AccountStatus::Deleted, 'el alumno se da de baja: deleted');
    $deletedLogin = thrown(AccountUnavailableException::class, static fn () => $auth->login($beto->email(), 'Segura#2026'));
    check($deletedLogin !== null && !$deletedLogin->banned && $deletedLogin->getMessage() === AccountUnavailableException::DELETED_MESSAGE, 'ya no inicia sesión: "dada de baja"');
    check($auth->userForSession($beto->id()) === null, 'y su sesión se corta');
    check(array_map(static fn ($c): string => $c->id, $comments->list($ana, $chapter->id())->comments) === [$c1->id], 'lo que publicó sigue visible (el comentario restaurado)');
    check(in_array($beto->id(), array_map(static fn ($r): string => $r->id, $accounts->users($admin, ['q' => $mark, 'estado' => 'de_baja'])->users), true), 'aparece en "Dadas de baja"');
    $profiles->deactivate($instructor, ['password' => 'Segura#2026']);
    check(!$inCatalog(), 'si se da de baja un instructor, su curso también sale del catálogo');
    $accounts->reactivate($admin, $beto->id());
    $accounts->reactivate($admin, $instructor->id());
    check($auth->login($beto->email(), 'Segura#2026')->id() === $beto->id() && $inCatalog(), '"Reactivar" las devuelve: entran con su contraseña y el curso regresa');
    check(ruleCode(static fn () => $accounts->reactivate($admin, $beto->id())) === 'USER_ALREADY_ACTIVE', 'reactivar una activa -> USER_ALREADY_ACTIVE');
    for ($i = 0; $i < 3; $i++) {
        thrown(Throwable::class, static fn () => $auth->login($carla->email(), 'Mala#2026'));
    }
    check(thrown(AccountLockedException::class, static fn () => $auth->login($carla->email(), 'Segura#2026')) !== null, 'una cuenta bloqueada por 3 intentos...');
    $accounts->reactivate($admin, $carla->id());
    check($auth->login($carla->email(), 'Segura#2026')->id() === $carla->id(), '...también se reactiva desde "Cuentas"');
    check(thrown(InvalidCredentialsException::class, static fn () => $auth->login($carla->email(), 'Mala#2026')) !== null, 'y su contador vuelve a empezar');

    // -----------------------------------------------------------
    Report::section('Lista de cuentas e historial');
    $all = $accounts->users($admin, ['q' => $mark]);
    check($all->total === 5 && $all->users[0]->id === $carla->id(), 'la búsqueda trae las 5 cuentas de la prueba, de la más reciente a la más antigua');
    check($accounts->users($admin, ['q' => $mark, 'rol' => 'instructor'])->total === 2 && $accounts->users($admin, ['q' => $mark, 'estado' => 'activas'])->total === 5, 'filtros por rol y "Activas"');
    check(ruleCode(static fn () => $accounts->users($ana)) === 'NOT_ADMIN', 'solo el admin -> NOT_ADMIN');
    $events = $accounts->events($admin, $instructor->id());
    check(array_map(static fn ($e): string => $e->type->value, $events) === ['reactivated', 'deleted', 'unbanned', 'banned'], 'el historial de la instructora, del más reciente al más antiguo');
    check($events[3]->reason === 'Publica contenido engañoso' && $events[3]->actorLabel($instructor->id()) !== 'Sistema' && $events[1]->actorLabel($instructor->id()) === 'La propia cuenta', 'con el motivo y quién lo hizo');
    check($events[1]->reason === ProfileService::DEACTIVATE_REASON && $accounts->events($admin, $beto->id())[1]->reason === 'Ya terminé lo que necesitaba', 'la baja guarda el motivo que escribió (o el de por defecto)');
    $carlaEvents = $accounts->events($admin, $carla->id());
    check($carlaEvents[1]->type->value === 'locked' && $carlaEvents[1]->actorLabel($carla->id()) === 'Sistema', 'el bloqueo automático aparece como "Sistema"');
    check(ruleCode(static fn () => $accounts->events($admin, '999999999999')) === 'USER_NOT_FOUND', 'una cuenta que no existe -> USER_NOT_FOUND');

    // Limpieza: curso dado de baja y categoría desactivada; las cuentas quedan activas y sin ban.
    $courseService->setActive($instructor, $course->id(), ['activo' => false]);
    $categories->update($admin, $category->id(), ['activa' => false]);
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf('Error inesperado: %s: %s (%s:%d)%s', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine(), PHP_EOL));
    if ($exception->getPrevious() !== null) {
        fwrite(STDERR, 'Causa: ' . $exception->getPrevious()->getMessage() . PHP_EOL);
    }
    exit(1);
}

Report::finish();
