<?php

declare(strict_types=1);

/**
 * Verificación de los comentarios por capítulo y las reacciones contra la base LOCAL, con los SP
 * instalados (app/database/procedures/install.sql).
 *
 *   php tests/verify_comments_db.php
 *
 * Cada corrida deja cuentas de prueba (prueba+<marca>...@cursology.test), una categoría y dos
 * cursos "Comentarios <marca>" (dados de baja al terminar) y una reacción de prueba (desactivada
 * al terminar). Si faltan, agrega al catálogo las 5 reacciones sugeridas (quedan activas). Las
 * formas de pago vuelven a quedar como estaban.
 */

use App\Database\Connection;
use App\Database\StoredProcedures;
use App\Integrations\CloudinaryClient;
use App\Integrations\PayPalClient;
use App\Models\Dto\ChapterComment;
use App\Models\Dto\ReactionSummary;
use App\Models\Entities\AppUser;
use App\Models\Entities\Reaction;
use App\Repositories\CategoryRepository;
use App\Repositories\ChatRepository;
use App\Repositories\CommentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\LearningRepository;
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
use App\Services\Exceptions\ValidationException;
use App\Services\LearningService;
use App\Services\MediaService;
use App\Services\PaymentMethodService;
use App\Services\ReactionService;
use App\Services\RegistrationValidator;
use App\Services\ReviewService;
use App\Services\VerifiedMedia;

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/app/database/conn.php';
require dirname(__DIR__) . '/app/view_helpers.php';

$procedures = new StoredProcedures(new Connection($DATABASES['local']));
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
$methods = new PaymentMethodService(new PaymentMethodRepository($procedures), $paypal);
$checkout = new CheckoutService(new PurchaseRepository($procedures), $methods, $paypal);
$comments = new CommentService(new CommentRepository($procedures));
$reactions = new ReactionService(new ReactionRepository($procedures));
$reviews = new ReviewService(new ReviewRepository($procedures));
$chats = new ChatService(new ChatRepository($procedures));

try {
    $procedures->call('sp_reaction_list', [1]);
} catch (Throwable $exception) {
    fwrite(STDERR, 'No se pudieron ejecutar los SP de comentarios y reacciones en la base local. Revisa el .env y que MySQL esté encendido,' . PHP_EOL
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

/** @param list<ChapterComment> $list @return list<string> */
function ids(array $list): array
{
    return array_map(static fn (ChapterComment $comment): string => $comment->id, $list);
}

/** "emoji:total" de cada reacción, con * en la propia. */
function shape(ReactionSummary $summary): string
{
    return implode(' ', array_map(static fn (array $c): string => $c['emoji'] . ':' . $c['total'] . ($c['reactionId'] === $summary->mine ? '*' : ''), $summary->counts));
}

/** Separa en el tiempo dos pasos cuyo orden importa (las fechas son DATETIME(6)). */
function tick(): void
{
    usleep(3000);
}

try {
    $mark = date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $account = static fn (string $role, string $suffix, string $name): array => [
        'rol' => $role, 'nombre' => $name, 'apellido_paterno' => 'Comentarios', 'apellido_materno' => '',
        'genero' => 'otro', 'fecha_nacimiento' => '1997-03-14', 'email' => "prueba+$mark-$suffix@cursology.test", 'password' => 'Segura#2026',
    ];
    $admin = $users->findForLogin('admin@cursology.local') ?? throw new RuntimeException('Falta la cuenta admin@cursology.local.');
    restorePaymentMethodsAtExit($methods, $admin);
    $methods->setActive($admin, ['codigo' => 'simulated_card', 'activa' => true]);

    $instructor = $auth->register($account('instructor', 'instructor', 'Irene'), null);
    $otherInstructor = $auth->register($account('instructor', 'otro', 'Omar'), null);
    $ana = $auth->register($account('student', 'alumna-a', 'Ana'), null);
    $beto = $auth->register($account('student', 'alumno-b', 'Beto'), null);
    $carla = $auth->register($account('student', 'alumna-c', 'Carla'), null);
    printf('Cuentas de prueba de esta corrida: prueba+%s-{instructor,otro,alumna-a,alumno-b,alumna-c}@cursology.test%s%s', $mark, PHP_EOL, PHP_EOL);

    // ---------- Curso X por niveles (X1 gratis: video + examen; X2 $150: video) y curso Y gratis ----------
    $category = $categories->create($admin, ['nombre' => "Prueba $mark comentarios"]);
    $folder = 'cursology/courses/u' . $instructor->id();
    $mainInput = $validator->validateContent(['tipo' => 'video_principal', 'archivo' => []]);
    $newCourse = static fn (string $title, string $mode) => $courseRepository->create($instructor->id(), $validator->validateCourse([
        'titulo' => "Comentarios $mark $title", 'descripcion' => 'Curso de prueba.', 'certificador' => '', 'precio_modo' => $mode,
        'precio' => '', 'categorias' => [$category->id()], 'portada' => [], 'video' => [],
    ], true), "https://res.cloudinary.com/prueba/image/upload/v1/$folder/covers/$title.jpg", "https://res.cloudinary.com/prueba/video/upload/v1/$folder/promos/$title.mp4");
    $newLevel = static fn (string $courseId, string $title, string $cost) => $courseRepository->createLevel($instructor->id(), $courseId, $validator->validateLevel(['titulo' => $title, 'descripcion' => "Nivel $title.", 'costo' => $cost, 'video' => []], true), "https://res.cloudinary.com/prueba/video/upload/v1/$folder/levels/$title-$mark.mp4");
    $newVideo = static function (string $levelId, string $title) use ($courseRepository, $validator, $instructor, $mainInput, $folder, $mark) {
        $chapter = $courseRepository->createChapter($instructor->id(), $levelId, $validator->validateChapter(['titulo' => $title, 'tipo' => 'video'], true));
        $courseRepository->addContent($instructor->id(), $chapter->id(), $mainInput, $title, new VerifiedMedia("$folder/content/$title-$mark", 'video', 'authenticated', 'mp4', 1000, 60, 'v.mp4', 'video/mp4', ''));

        return $chapter;
    };
    $courseX = $newCourse('X', 'niveles');
    $x1 = $newLevel($courseX->id(), 'X1', '0');
    $x2 = $newLevel($courseX->id(), 'X2', '150');
    $v1 = $newVideo($x1->id(), 'Variables');
    $exam = $courseRepository->createChapter($instructor->id(), $x1->id(), $validator->validateChapter(['titulo' => 'Examen X1', 'tipo' => 'examen', 'minimo' => '70'], true));
    $courseRepository->createQuestion($instructor->id(), $exam->id(), $validator->validateQuestion(['pregunta' => '¿Dónde corre PHP?', 'modo' => 'unica', 'respuestas' => [
        ['texto' => 'En el servidor', 'correcta' => true], ['texto' => 'En el navegador', 'correcta' => false],
    ]]));
    $v2 = $newVideo($x2->id(), 'Funciones');
    $courseService->publish($instructor, $courseX->id());
    $courseY = $newCourse('Y', 'gratis');
    $y1 = $newLevel($courseY->id(), 'Y1', '0');
    $yChapter = $newVideo($y1->id(), 'Repaso');
    $courseService->publish($instructor, $courseY->id());

    $checkout->enrollFree($ana, ['curso' => $courseX->id()]);
    $checkout->payWithCard($ana, ['curso' => $courseX->id(), 'nivel' => $x2->id(), 'titular' => 'Ana', 'numero' => '4242 4242 4242 4242', 'vencimiento' => '12/30', 'cvv' => '123']);
    $checkout->enrollFree($beto, ['curso' => $courseX->id()]);

    // -----------------------------------------------------------
    Report::section('Quién comenta');
    $c1 = $comments->create($ana, $v1->id(), ['texto' => "  ¿El ejercicio de variables\nes obligatorio?  "]);
    check($c1->content === "¿El ejercicio de variables\nes obligatorio?" && $c1->parentId === null && !$c1->authorIsInstructor && $c1->authorName === 'Ana Comentarios', 'la alumna con acceso comenta (sin los espacios de los lados)');
    tick();
    $c2 = $comments->create($beto, $v1->id(), ['texto' => 'Muy claro el video.']);
    check($c2->chapterId === $v1->id(), 'otro alumno del mismo nivel también');
    check(ruleCode(static fn () => $comments->create($beto, $v2->id(), ['texto' => 'Hola'])) === 'NO_LEVEL_ACCESS', 'en un nivel que no tiene -> NO_LEVEL_ACCESS');
    check(ruleCode(static fn () => $comments->create($carla, $v1->id(), ['texto' => 'Hola'])) === 'NO_LEVEL_ACCESS', 'una alumna no inscrita -> NO_LEVEL_ACCESS');
    check(ruleCode(static fn () => $comments->create($otherInstructor, $v1->id(), ['texto' => 'Hola'])) === 'NO_LEVEL_ACCESS', 'otro instructor -> NO_LEVEL_ACCESS');
    check(ruleCode(static fn () => $comments->create($ana, $exam->id(), ['texto' => 'Hola'])) === 'NO_COMMENTS_ON_EXAM', 'en un examen -> NO_COMMENTS_ON_EXAM');
    check(ruleCode(static fn () => $comments->list($ana, $exam->id())) === 'NO_COMMENTS_ON_EXAM', 'ni se listan');
    check(ruleCode(static fn () => $comments->create($ana, '999999999999', ['texto' => 'Hola'])) === 'CHAPTER_NOT_FOUND', 'un capítulo que no existe -> CHAPTER_NOT_FOUND');
    check(ruleCode(static fn () => (new CommentRepository($procedures))->create($ana->id(), $v1->id(), null, '   ')) === 'INVALID_COMMENT', 'el SP también rechaza el texto vacío -> INVALID_COMMENT');

    // -----------------------------------------------------------
    Report::section('Respuestas');
    tick();
    $r1 = $comments->create($instructor, $v1->id(), ['texto' => 'Es opcional, pero ayuda.', 'responde_a' => $c1->id]);
    check($r1->parentId === $c1->id && $r1->authorIsInstructor, 'el instructor dueño responde, marcado como instructor');
    tick();
    $r2 = $comments->create($beto, $v1->id(), ['texto' => 'Gracias, profe', 'responde_a' => (int) $r1->id]);
    check($r2->parentId === $c1->id, 'responder a una respuesta la cuelga del comentario principal');
    tick();
    $c3 = $comments->create($ana, $v2->id(), ['texto' => '¿Las funciones pueden regresar arreglos?']);
    check(ruleCode(static fn () => $comments->create($ana, $v1->id(), ['texto' => 'Hola', 'responde_a' => $c3->id])) === 'COMMENT_NOT_FOUND', 'responder a un comentario de otro capítulo -> COMMENT_NOT_FOUND');
    $listAna = $comments->list($ana, $v1->id());
    check(ids($listAna->comments) === [$c2->id, $c1->id] && $listAna->total === 2 && !$listAna->viewerIsOwner, 'lista: los principales del más nuevo al más antiguo');
    $thread = $listAna->comments[1];
    check(ids($thread->replies) === [$r1->id, $r2->id] && $thread->replyCount === 2, 'cada uno con sus respuestas, de la más antigua a la más nueva');
    check($comments->list($instructor, $v1->id())->viewerIsOwner, 'el instructor del curso la ve como dueño (puede ocultar)');

    // -----------------------------------------------------------
    Report::section('Editar y eliminar');
    tick();
    $edited = $comments->update($ana, $c1->id, ['texto' => '¿El ejercicio de variables es obligatorio para el examen?']);
    check($edited->content === '¿El ejercicio de variables es obligatorio para el examen?' && $edited->wasEdited(), 'el autor edita y queda "editado"');
    check(!$comments->list($ana, $v1->id())->comments[0]->wasEdited(), 'los que no se editaron no lo dicen');
    check(ruleCode(static fn () => $comments->update($beto, $c1->id, ['texto' => 'Otro'])) === 'COMMENT_NOT_FOUND', 'otro no lo edita -> COMMENT_NOT_FOUND');
    check(ruleCode(static fn () => $comments->delete($beto, $c1->id)) === 'COMMENT_NOT_FOUND', 'ni lo elimina');
    $comments->delete($beto, $r2->id);
    check(ids($comments->list($ana, $v1->id())->comments[1]->replies) === [$r1->id], 'el autor elimina su respuesta y ya no se lista');
    $comments->delete($beto, $c2->id);
    $afterDelete = $comments->list($ana, $v1->id());
    check(ids($afterDelete->comments) === [$c1->id] && $afterDelete->total === 1, 'un principal sin respuestas eliminado desaparece');
    $removed = $comments->delete($ana, $c1->id);
    check($removed->isRemoved() && $removed->content === null && $removed->replyCount === 1, 'eliminar uno con respuestas: queda sin texto');
    $withRemoved = $comments->list($beto, $v1->id())->comments;
    check(ids($withRemoved) === [$c1->id] && $withRemoved[0]->content === null && ids($withRemoved[0]->replies) === [$r1->id], 'y se lista como "Comentario eliminado" con su respuesta');
    check(ruleCode(static fn () => $comments->update($ana, $c1->id, ['texto' => 'Otra vez'])) === 'COMMENT_NOT_FOUND', 'ya no se edita');
    check(ruleCode(static fn () => $comments->create($beto, $v1->id(), ['texto' => 'Hola', 'responde_a' => $c1->id])) === 'COMMENT_NOT_FOUND', 'ni se le responde directo...');
    check($comments->create($beto, $v1->id(), ['texto' => 'Sigo con la duda', 'responde_a' => $r1->id])->parentId === $c1->id, '...pero sí a sus respuestas, en el mismo hilo');

    // -----------------------------------------------------------
    Report::section('Ocultar');
    tick();
    $c4 = $comments->create($beto, $v1->id(), ['texto' => 'La respuesta del examen es la A']);
    check(ruleCode(static fn () => $comments->hide($otherInstructor, $c4->id, ['motivo' => 'Spoiler'])) === 'COMMENT_NOT_FOUND', 'otro instructor no lo oculta -> COMMENT_NOT_FOUND');
    check(ruleCode(static fn () => $comments->hide($ana, $c4->id, ['motivo' => 'Spoiler'])) === 'COMMENT_NOT_FOUND', 'una alumna tampoco');
    $hidden = $comments->hide($instructor, $c4->id, ['motivo' => 'Comparte las respuestas del examen']);
    check($hidden->isRemoved() && !in_array($c4->id, ids($comments->list($ana, $v1->id())->comments), true), 'el instructor del curso lo oculta y deja de listarse');
    check(ruleCode(static fn () => $comments->update($beto, $c4->id, ['texto' => 'Perdón'])) === 'COMMENT_NOT_FOUND', 'el autor ya no lo edita');
    $c5 = $comments->create($beto, $v1->id(), ['texto' => 'Spam spam']);
    check($comments->hide($admin, $c5->id, ['motivo' => 'Spam'])->isRemoved(), 'un admin también oculta');

    // -----------------------------------------------------------
    Report::section('Paginación');
    for ($i = 1; $i <= 11; $i++) {
        $comments->create($beto, $v1->id(), ['texto' => "Comentario $i"]);
    }
    $page1 = $comments->list($ana, $v1->id());
    $page2 = $comments->list($ana, $v1->id(), ['pagina' => '2']);
    check(count($page1->comments) === 10 && $page1->total === 12 && $page1->pages === 2 && $page1->hasMore(), '12 principales visibles: 10 en la primera página');
    check(count($page2->comments) === 2 && !$page2->hasMore() && $page2->comments[1]->id === $c1->id, 'y 2 en la segunda (la última es la eliminada con respuestas)');
    check(array_intersect(ids($page1->comments), ids($page2->comments)) === [], 'sin repetidos entre páginas');

    // -----------------------------------------------------------
    Report::section('Vista del instructor');
    $view = $comments->forInstructor($instructor, $courseX->id());
    check(array_map(static fn (array $c): string => $c['id'], $view->chapters) === [$v1->id(), $v2->id()], 'los capítulos de video del curso (sin el examen)');
    check($view->chapters[0]['commentCount'] === 12 && $view->chapters[0]['unansweredCount'] === 11 && $view->chapters[1]['unansweredCount'] === 1, 'cuántos tiene cada uno y cuántos sin responder');
    check($view->total === 13 && count($view->threads) === 13, 'todos los hilos del curso en una página (20 por página)');
    $unanswered = $comments->forInstructor($instructor, $courseX->id(), ['estado' => 'sin_responder']);
    check($unanswered->total === 12 && !in_array($c1->id, ids($unanswered->threads), true), '"Sin responder" deja fuera el que ya respondió');
    $onlyV2 = $comments->forInstructor($instructor, $courseX->id(), ['capitulo' => $v2->id()]);
    check(ids($onlyV2->threads) === [$c3->id] && $onlyV2->threads[0]->answered === false && $onlyV2->threads[0]->chapterTitle === 'Funciones', 'filtro por capítulo, con su título y "sin responder"');
    $answered = array_values(array_filter($view->threads, static fn (ChapterComment $t): bool => $t->id === $c1->id))[0];
    check($answered->answered === true && ids($answered->replies) !== [], 'el respondido trae answered = true y sus respuestas');
    check(ruleCode(static fn () => $comments->forInstructor($otherInstructor, $courseX->id())) === 'COURSE_NOT_FOUND', 'otro instructor -> COURSE_NOT_FOUND');
    $recent = $comments->recentForInstructor($instructor);
    check(count($recent) === 5 && $recent[0]->content === 'Comentario 11' && !$recent[0]->answered && $recent[0]->courseId === $courseX->id(), 'panel: los 5 más recientes de alumnos, sin responder');
    check(ruleCode(static fn () => $comments->recentForInstructor($ana)) === 'NOT_INSTRUCTOR', 'una alumna no tiene ese panel -> NOT_INSTRUCTOR');

    // -----------------------------------------------------------
    Report::section('Catálogo de reacciones');
    check(ruleCode(static fn () => $reactions->create($ana, ['emoji' => '👏', 'nombre' => "Prueba $mark"])) === 'NOT_ADMIN', 'solo el admin crea reacciones -> NOT_ADMIN');
    // Primero las sugeridas: así la de prueba queda al final del catálogo (el orden es por id).
    $reactions->addDefaults($admin);
    check($reactions->addDefaults($admin) === [], '"Agregar las sugeridas" no repite las que ya están');
    $test = $reactions->create($admin, ['emoji' => '👏', 'nombre' => "Prueba $mark aplausos"]);
    check($test->active() && $test->emoji() === '👏', 'el admin crea una, activa');
    check(ruleCode(static fn () => $reactions->create($admin, ['emoji' => '🎉', 'nombre' => mb_strtoupper("Prueba $mark aplausos")])) === 'REACTION_NAME_TAKEN', 'nombre repetido (sin importar mayúsculas) -> REACTION_NAME_TAKEN');
    check(failedFields(static fn () => $reactions->create($admin, ['emoji' => 'ab', 'nombre' => 'x'])) === ['emoji'], 'emoji inválido -> emoji');
    $test = $reactions->update($admin, $test->id(), ['emoji' => '🙌', 'nombre' => "Prueba $mark manos"]);
    check($test->emoji() === '🙌' && $test->reactionName() === "Prueba $mark manos", 'se edita emoji y nombre');
    check(ruleCode(static fn () => $reactions->update($admin, '999999999999', ['activa' => false])) === 'REACTION_NOT_FOUND', 'una que no existe -> REACTION_NOT_FOUND');
    $byName = [];
    foreach ($reactions->all() as $reaction) {
        $byName[$reaction->reactionName()] = $reaction;
    }
    foreach (ReactionService::DEFAULTS as $default) {
        check(isset($byName[$default['nombre']]), "la sugerida {$default['nombre']} está en el catálogo");
    }
    $like = $byName['Me gusta'];
    $love = $byName['Me encanta'];
    if (!$like->active()) {
        $like = $reactions->update($admin, $like->id(), ['activa' => true]);
    }
    if (!$love->active()) {
        $love = $reactions->update($admin, $love->id(), ['activa' => true]);
    }
    $activeIds = array_map(static fn (Reaction $r): string => $r->id(), $reactions->active());
    check(in_array($test->id(), $activeIds, true) && in_array($like->id(), $activeIds, true), 'las activas se ofrecen en el selector');

    // -----------------------------------------------------------
    Report::section('Reacciones a comentarios');
    check(shape($comments->react($ana, $r1->id, ['reaccion' => $like->id()])) === '👍:1*', 'la alumna reacciona');
    check(shape($comments->react($beto, $r1->id, ['reaccion' => $like->id()])) === '👍:2*', 'otro alumno con la misma: 2');
    check(shape($comments->react($ana, $r1->id, ['reaccion' => $love->id()])) === '👍:1 ❤️:1*', 'cambiarla: una de cada una');
    check(shape($comments->react($instructor, $r1->id, ['reaccion' => $test->id()])) === '👍:1 ❤️:1 🙌:1*', 'el instructor del curso también reacciona');
    check(shape($comments->react($ana, $r1->id, ['reaccion' => null])) === '👍:1 🙌:1', 'null la quita');
    $reactions->update($admin, $test->id(), ['activa' => false]);
    check(shape($comments->list($beto, $v1->id(), ['pagina' => '2'])->comments[1]->replies[0]->reactions) === '👍:1*', 'una reacción desactivada deja de contarse (y la lista marca la propia)');
    check(ruleCode(static fn () => $comments->react($ana, $r1->id, ['reaccion' => $test->id()])) === 'REACTION_NOT_FOUND', 'ni se puede elegir -> REACTION_NOT_FOUND');
    check(ruleCode(static fn () => $comments->react($ana, $r1->id, ['reaccion' => '999999999999'])) === 'REACTION_NOT_FOUND', 'una que no existe -> REACTION_NOT_FOUND');
    check(ruleCode(static fn () => $comments->react($carla, $r1->id, ['reaccion' => $like->id()])) === 'COMMENT_NOT_FOUND', 'sin acceso al capítulo no se reacciona -> COMMENT_NOT_FOUND');
    check(ruleCode(static fn () => $comments->react($otherInstructor, $r1->id, ['reaccion' => $like->id()])) === 'COMMENT_NOT_FOUND', 'otro instructor tampoco');
    check(ruleCode(static fn () => $comments->react($beto, $c3->id, ['reaccion' => $like->id()])) === 'COMMENT_NOT_FOUND', 'ni en un nivel que no tiene');
    check(ruleCode(static fn () => $comments->react($ana, $c4->id, ['reaccion' => $like->id()])) === 'COMMENT_NOT_FOUND', 'ni a un comentario oculto');

    // -----------------------------------------------------------
    Report::section('Reacciones a opiniones');
    $checkout->enrollFree($ana, ['curso' => $courseY->id()]);
    $learning->progress($ana, $yChapter->id(), ['terminado' => true]);
    $opinion = $reviews->save($ana, $courseY->id(), ['calificacion' => 5, 'opinion' => 'Excelente repaso.'])->opinion;
    check(shape($reactions->reactToOpinion($beto, (string) $opinion?->id(), ['reaccion' => $like->id()])) === '👍:1*', 'cualquier alumno con sesión reacciona a una opinión (aunque no esté inscrito)');
    check(shape($reactions->reactToOpinion($otherInstructor, (string) $opinion?->id(), ['reaccion' => $love->id()])) === '👍:1 ❤️:1*', 'y cualquier instructor');
    $forBeto = $reviews->list($courseY->id(), [], $beto)->reviews[0]->reactions;
    $forVisitor = $reviews->list($courseY->id())->reviews[0]->reactions;
    check(shape($forBeto) === '👍:1* ❤️:1' && $forVisitor->mine === null && $forVisitor->total() === 2, 'la lista de opiniones marca la de quien consulta; el visitante solo ve los números');
    $reviews->hide($instructor, (string) $opinion?->id(), ['motivo' => 'Prueba de reacciones']);
    check(ruleCode(static fn () => $reactions->reactToOpinion($carla, (string) $opinion?->id(), ['reaccion' => $like->id()])) === 'OPINION_NOT_FOUND', 'a una opinión oculta ya no -> OPINION_NOT_FOUND');

    // -----------------------------------------------------------
    Report::section('Reacciones a mensajes');
    [$chat] = $chats->start($ana, ['curso' => $courseX->id()]);
    $message = $chats->send($ana, $chat->id(), ['texto' => 'Profe, ¿hay tarea?']);
    check(shape($reactions->reactToMessage($instructor, $message->id, ['reaccion' => $like->id()])) === '👍:1*', 'el instructor reacciona al mensaje de la alumna');
    check(shape($reactions->reactToMessage($ana, $message->id, ['reaccion' => $like->id()])) === '👍:2*', 'y ella al suyo');
    check(ruleCode(static fn () => $reactions->reactToMessage($beto, $message->id, ['reaccion' => $like->id()])) === 'MESSAGE_NOT_FOUND', 'quien no participa -> MESSAGE_NOT_FOUND');
    check(shape($chats->open($instructor, $chat->id())->messages[0]->reactions) === '👍:2*', 'la conversación abierta trae las reacciones de cada mensaje');
    check($chats->open($instructor, $chat->id(), $message->id)->messages === [], 'el sondeo (after) no repite mensajes');

    // Limpieza: cursos dados de baja, categoría y reacción de prueba desactivadas.
    $courseService->setActive($instructor, $courseX->id(), ['activo' => false]);
    $courseService->setActive($instructor, $courseY->id(), ['activo' => false]);
    $categories->update($admin, $category->id(), ['activa' => false]);
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf('Error inesperado: %s: %s (%s:%d)%s', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine(), PHP_EOL));
    if ($exception->getPrevious() !== null) {
        fwrite(STDERR, 'Causa: ' . $exception->getPrevious()->getMessage() . PHP_EOL);
    }
    exit(1);
}

Report::finish();
