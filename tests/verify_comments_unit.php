<?php

declare(strict_types=1);

/**
 * Verificación sin base de datos de los comentarios y las reacciones: validaciones, lectura de
 * las filas de los SP, hilos, permisos ("can"), el JSON de la API y que los scripts no armen HTML
 * con texto.
 *
 *   php tests/verify_comments_unit.php
 */

use App\Database\Connection;
use App\Database\StoredProcedures;
use App\Http\Presenters\CommentPresenter;
use App\Http\Presenters\ReactionPresenter;
use App\Models\Dto\ChapterComment;
use App\Models\Dto\CommentPage;
use App\Models\Dto\InstructorCommentPage;
use App\Models\Dto\ReactionSummary;
use App\Models\Dto\RecentComment;
use App\Models\Entities\AppUser;
use App\Models\Entities\Reaction;
use App\Models\Enums\UserRole;
use App\Repositories\CommentRepository;
use App\Repositories\ReactionRepository;
use App\Services\CommentService;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;
use App\Services\ReactionService;

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/app/view_helpers.php';

/** @return list<string>|null */
function failedFields(callable $callback): ?array
{
    $exception = thrown(ValidationException::class, $callback);

    return $exception === null ? null : array_keys($exception->fields);
}

function ruleCode(callable $callback): ?string
{
    return thrown(BusinessRuleException::class, $callback)?->errorCode;
}

function user(UserRole $role): AppUser
{
    $timestamp = '2026-10-25 12:00:00.000000';

    return AppUser::fromRow([
        'id' => 7, 'name' => 'Ana', 'father_last_name' => 'López', 'mother_last_name' => null, 'gender' => 'femenino',
        'birthday' => '2000-05-17', 'avatar' => 'img/avatar-default.svg', 'email' => 'ana@example.com',
        'password_hash' => '$2y$10$hashDePrueba', 'role' => $role->value, 'description' => null,
        'account_status' => 'active', 'active' => 1, 'consecutive_failed_logins' => 0, 'status_changed_at' => $timestamp,
        'ban' => 0, 'banned_by' => null, 'banned_at' => null, 'ban_reason' => null, 'created_at' => $timestamp,
        'updated_at' => $timestamp, 'last_login_at' => null,
    ]);
}

/** @param array<string, mixed> $overrides */
function commentRow(array $overrides = []): array
{
    return $overrides + [
        'id' => '10', 'course_chapter_id' => '3', 'comment_parent_id' => null, 'commented_by' => '7',
        'author_name' => 'Ana López', 'author_avatar' => 'img/a.svg', 'author_is_instructor' => 0,
        'comment' => "¿El ejercicio\nes obligatorio?", 'commented_at' => '2026-10-25 18:00:00.000000',
        'updated_at' => '2026-10-25 18:00:00.000000', 'active' => 1, 'reply_count' => 0,
    ];
}

// La conexión es perezosa: estas pruebas fallan antes de llegar a la base.
$noDatabase = new StoredProcedures(new Connection(['host' => '127.0.0.1', 'port' => 1, 'name' => 'sin_base', 'user' => 'nadie', 'password' => '']));
$comments = new CommentService(new CommentRepository($noDatabase));
$reactions = new ReactionService(new ReactionRepository($noDatabase));
$student = user(UserRole::Student);

// -----------------------------------------------------------
Report::section('Validación de comentarios');
check(failedFields(static fn () => CommentService::validateText([])) === ['texto'], 'sin texto -> texto');
check(failedFields(static fn () => CommentService::validateText(['texto' => "  \n "])) === ['texto'], 'solo espacios -> texto');
check(failedFields(static fn () => CommentService::validateText(['texto' => ['hola']])) === ['texto'], 'un arreglo -> texto');
check(failedFields(static fn () => CommentService::validateText(['texto' => "\xC3\x28"])) === ['texto'], 'texto que no es UTF-8 -> texto');
check(CommentService::validateText(['texto' => str_repeat('ñ', 2000)]) === str_repeat('ñ', 2000), '2000 caracteres (no bytes) se aceptan');
check(failedFields(static fn () => CommentService::validateText(['texto' => str_repeat('a', 2001)])) === ['texto'], '2001 caracteres -> texto');
check(CommentService::validateText(['texto' => "  Hola\nprofe  "]) === "Hola\nprofe", 'quita los espacios de los lados y conserva los saltos de línea');
check(CommentService::instructorFilters([]) === ['capitulo' => '', 'estado' => ''], 'sin filtros');
check(CommentService::instructorFilters(['capitulo' => '12', 'estado' => 'sin_responder']) === ['capitulo' => '12', 'estado' => 'sin_responder'], 'filtros válidos');
check(CommentService::instructorFilters(['capitulo' => 'abc', 'estado' => 'todos']) === ['capitulo' => '', 'estado' => ''], 'los inválidos se ignoran');
check(CommentService::PAGE_SIZE === 10 && CommentService::RECENT_COMMENTS === 5, '10 por página en el reproductor y 5 recientes en el panel');

// -----------------------------------------------------------
Report::section('Ids, motivo y reacción (antes de la base)');
check(ruleCode(static fn () => $comments->list($student, 'abc')) === 'CHAPTER_NOT_FOUND', 'capítulo inválido -> CHAPTER_NOT_FOUND');
check(failedFields(static fn () => $comments->create($student, '3', ['texto' => 'Hola', 'responde_a' => 'x'])) === ['responde_a'], 'responde_a inválido -> responde_a');
check(failedFields(static fn () => $comments->create($student, '3', ['texto' => ''])) === ['texto'], 'comentario vacío -> texto');
foreach (['update' => static fn () => $comments->update($student, '0', ['texto' => 'x']), 'delete' => static fn () => $comments->delete($student, 'abc'),
          'hide' => static fn () => $comments->hide($student, '-1', ['motivo' => 'Spam']), 'react' => static fn () => $comments->react($student, '', ['reaccion' => '1'])] as $action => $call) {
    check(ruleCode($call) === 'COMMENT_NOT_FOUND', "$action con id inválido -> COMMENT_NOT_FOUND");
}
check(failedFields(static fn () => $comments->hide($student, '10', ['motivo' => 'no'])) === ['motivo'], 'motivo de menos de 3 caracteres -> motivo');
check(failedFields(static fn () => $comments->react($student, '10', [])) === ['reaccion'], 'sin el campo reaccion -> reaccion');
check(ruleCode(static fn () => $comments->forInstructor($student, 'x')) === 'COURSE_NOT_FOUND', 'vista del instructor con curso inválido -> COURSE_NOT_FOUND');
check(ReactionService::reactionInput(['reaccion' => null]) === null, 'reaccion null = quitarla');
check(ReactionService::reactionInput(['reaccion' => 5]) === '5' && ReactionService::reactionInput(['reaccion' => ' 5 ']) === '5', 'reaccion como número o texto');
foreach (['abc', '0', '-2', '1.5', true] as $bad) {
    check(failedFields(static fn () => ReactionService::reactionInput(['reaccion' => $bad])) === ['reaccion'], 'reaccion ' . var_export($bad, true) . ' -> reaccion');
}
check(ruleCode(static fn () => $reactions->reactToOpinion($student, 'x', ['reaccion' => null])) === 'OPINION_NOT_FOUND', 'opinión inválida -> OPINION_NOT_FOUND');
check(ruleCode(static fn () => $reactions->reactToMessage($student, '0', ['reaccion' => null])) === 'MESSAGE_NOT_FOUND', 'mensaje inválido -> MESSAGE_NOT_FOUND');
check(ruleCode(static fn () => $reactions->update(user(UserRole::Admin), 'x', ['activa' => true])) === 'REACTION_NOT_FOUND', 'reacción inválida -> REACTION_NOT_FOUND');
check(failedFields(static fn () => $reactions->update(user(UserRole::Admin), '1', [])) === ['nombre'], 'editar sin cambios -> nombre');
check(failedFields(static fn () => $reactions->update(user(UserRole::Admin), '1', ['activa' => 'si'])) === ['activa'], 'activa que no es booleano -> activa');

// -----------------------------------------------------------
Report::section('Catálogo de reacciones');
foreach (['👍', '❤️', '👍🏽', '👨‍👩‍👧', '🇲🇽', '💡'] as $good) {
    check(ReactionService::emojiError($good) === null, "\"$good\" es un emoji válido");
}
foreach (['' => 'vacío', 'a' => 'una letra', '7' => 'un número', ':)' => 'puntuación', '👍 x' => 'con texto', '<b>' => 'etiquetas', '👍👍👍👍👍👍👍👍👍' => 'más de 8'] as $bad => $label) {
    check(ReactionService::emojiError((string) $bad) !== null, "emoji $label se rechaza");
}
check(failedFields(static fn () => ReactionService::validate(['emoji' => '👍'])) === ['nombre'], 'sin nombre -> nombre');
check(failedFields(static fn () => ReactionService::validate(['emoji' => '👍', 'nombre' => str_repeat('x', 61)])) === ['nombre'], 'nombre de 61 caracteres -> nombre');
check(ReactionService::validate(['emoji' => ' 👏 ', 'nombre' => ' Aplausos ']) === ['👏', 'Aplausos'], 'emoji y nombre sin espacios de los lados');
check(count(ReactionService::DEFAULTS) === 5, 'son 5 sugeridas');
foreach (ReactionService::DEFAULTS as $default) {
    check(thrown(ValidationException::class, static fn () => ReactionService::validate($default)) === null, "la sugerida {$default['nombre']} pasa la validación");
}
$reaction = Reaction::fromRow([
    'id' => '4', 'emoji' => '😂', 'reaction_name' => '</script><b>x</b>', 'created_by' => '1', 'created_at' => '2026-10-25 18:00:00.000000',
    'updated_by' => null, 'updated_at' => '2026-10-25 18:00:00.000000', 'active' => 1,
]);
$json = ReactionPresenter::catalogJson([$reaction]);
check(!str_contains($json, '<') && !str_contains($json, '>') && str_contains($json, '\u003C\/script\u003E'), 'el catálogo incrustado en la página no puede cerrar la etiqueta <script>');
check(json_decode($json, true) === [['id' => '4', 'emoji' => '😂', 'name' => '</script><b>x</b>']], 'y conserva el texto tal cual');

// -----------------------------------------------------------
Report::section('Resumen de reacciones');
$rows = [
    ['target_id' => '10', 'reaction_id' => '1', 'emoji' => '👍', 'reaction_name' => 'Me gusta', 'total' => 3, 'mine' => 0],
    ['target_id' => '10', 'reaction_id' => '2', 'emoji' => '❤️', 'reaction_name' => 'Me encanta', 'total' => 1, 'mine' => 1],
    ['target_id' => '11', 'reaction_id' => '1', 'emoji' => '👍', 'reaction_name' => 'Me gusta', 'total' => 2, 'mine' => 0],
];
$byTarget = ReactionSummary::byTarget($rows);
check(count($byTarget) === 2 && ReactionSummary::of($byTarget, '10')->total() === 4 && ReactionSummary::of($byTarget, '10')->mine === '2', 'agrupa por elemento, suma y marca la propia');
check(ReactionSummary::of($byTarget, '11')->mine === null && ReactionSummary::of($byTarget, '99')->counts === [], 'sin la propia, y vacío si no tiene');
check(ReactionPresenter::summary(ReactionSummary::of($byTarget, '10')) === ['items' => [
    ['id' => '1', 'emoji' => '👍', 'name' => 'Me gusta', 'total' => 3, 'mine' => false],
    ['id' => '2', 'emoji' => '❤️', 'name' => 'Me encanta', 'total' => 1, 'mine' => true],
], 'mine' => '2'], 'JSON del resumen');

// -----------------------------------------------------------
Report::section('Hilos y permisos');
$threads = ChapterComment::threads(
    [commentRow(['reply_count' => 2]), commentRow(['id' => '20', 'comment' => null, 'active' => 0, 'reply_count' => 1, 'commented_by' => '8'])],
    [
        commentRow(['id' => '11', 'comment_parent_id' => '10', 'commented_by' => '4', 'author_name' => 'Irene', 'author_is_instructor' => 1, 'comment' => 'Sí.']),
        commentRow(['id' => '12', 'comment_parent_id' => '10', 'commented_by' => '8', 'comment' => 'Gracias', 'updated_at' => '2026-10-25 18:30:00.000000']),
        commentRow(['id' => '21', 'comment_parent_id' => '20', 'commented_by' => '7', 'comment' => 'Yo tampoco']),
    ],
    $rows,
);
check(array_map(static fn ($t): array => [$t->id, array_map(static fn ($r): string => $r->id, $t->replies)], $threads) === [['10', ['11', '12']], ['20', ['21']]], 'cada principal con sus respuestas, en orden');
check($threads[0]->reactions->total() === 4 && $threads[0]->replies[0]->reactions->total() === 2, 'y las reacciones de cada comentario');
check($threads[1]->isRemoved() && $threads[1]->content === null && !$threads[1]->wasEdited(), 'el eliminado: sin texto ni "editado"');
check(!$threads[0]->wasEdited() && $threads[0]->replies[1]->wasEdited(), '"editado" solo si updated_at es posterior');
check($threads[0]->replies[0]->authorIsInstructor && $threads[0]->answered === null && $threads[0]->chapterLabel() === '', 'la etiqueta del instructor; sin datos del capítulo fuera de su vista');

$root = $threads[0];
check(CommentPresenter::can($root, '7', false) === ['reply' => true, 'edit' => true, 'delete' => true, 'hide' => false], 'el autor responde, edita y elimina');
check(CommentPresenter::can($root, '8', false) === ['reply' => true, 'edit' => false, 'delete' => false, 'hide' => false], 'otro alumno solo responde');
check(CommentPresenter::can($root, '4', true) === ['reply' => true, 'edit' => false, 'delete' => false, 'hide' => true], 'el instructor del curso responde y oculta');
check(CommentPresenter::can($threads[0]->replies[0], '4', true)['hide'] === false, 'el instructor no oculta los suyos (los elimina)');
check(CommentPresenter::can($threads[1], '8', true) === ['reply' => false, 'edit' => false, 'delete' => false, 'hide' => false], 'con un eliminado no se puede hacer nada');

$commentJson = CommentPresenter::comment($root, '7', false);
check(array_keys($commentJson) === ['id', 'chapterId', 'parentId', 'author', 'avatar', 'isInstructor', 'text', 'removed', 'edited', 'commentedAt', 'commentedAtLabel', 'replyCount', 'replies', 'reactions', 'can'], 'JSON del comentario sin datos internos (ni el id del autor)');
check($commentJson['commentedAtLabel'] === '25 oct 2026, 12:00' && count($commentJson['replies']) === 2 && $commentJson['replies'][0]['isInstructor'] === true, 'con la fecha en hora de Monterrey y sus respuestas');
check(CommentPresenter::comment($threads[1], '7', false)['text'] === null && CommentPresenter::comment($threads[1], '7', false)['removed'] === true, 'el eliminado no manda su texto');

$page = CommentPage::fromResultSets('3', [[commentRow()], [], [], [['total' => 23, 'viewer_is_owner' => 0]]], 1, 10);
check($page->pages === 3 && $page->hasMore() && !$page->viewerIsOwner && count($page->comments) === 1, 'página: 23 comentarios = 3 páginas');

// -----------------------------------------------------------
Report::section('Vista del instructor y panel');
$withChapter = static fn (array $overrides): array => commentRow($overrides) + ['chapter_title' => 'Variables', 'level_sort' => 1, 'chapter_sort' => 2, 'answered' => 0];
$instructorPage = InstructorCommentPage::fromResultSets([
    [['id' => '9', 'title' => 'PHP', 'active' => 1]],
    [
        ['id' => '3', 'title' => 'Variables', 'level_sort' => 1, 'chapter_sort' => 2, 'comment_count' => 2, 'unanswered_count' => 1],
        ['id' => '5', 'title' => 'Funciones', 'level_sort' => 2, 'chapter_sort' => 1, 'comment_count' => 1, 'unanswered_count' => 1],
    ],
    [
        $withChapter(['id' => '30', 'course_chapter_id' => '5', 'chapter_title' => 'Funciones', 'level_sort' => 2, 'chapter_sort' => 1]),
        $withChapter(['id' => '10']),
        $withChapter(['id' => '15', 'answered' => 1]),
    ],
    [], [], [['total' => 3]],
], ['capitulo' => '', 'estado' => ''], 1, 20);
check(array_map(static fn (array $g): string => $g['label'], $instructorPage->byChapter()) === ['Nivel 1 · 1.2 Variables', 'Nivel 2 · 2.1 Funciones'], 'los hilos se agrupan por capítulo, en el orden del temario');
check(array_map(static fn ($t): string => $t->id, $instructorPage->byChapter()[0]['threads']) === ['10', '15'], 'y dentro de cada capítulo conservan su orden');
check($instructorPage->unansweredTotal() === 2 && $instructorPage->threads[1]->answered === false && $instructorPage->threads[2]->answered === true, 'sin responder: 2 en el curso; "answered" por hilo');
check(CommentPresenter::comment($instructorPage->threads[0], '4', true)['chapterLabel'] === 'Nivel 2 · 2.1 Funciones', 'el JSON del instructor trae el capítulo');
$recent = RecentComment::fromRow([
    'id' => '10', 'course_id' => '9', 'course_title' => 'PHP', 'chapter_id' => '3', 'chapter_title' => 'Variables',
    'author_name' => 'Ana', 'author_avatar' => 'img/a.svg', 'comment' => str_repeat('palabra ', 30), 'commented_at' => '2026-10-25 18:00:00.000000', 'answered' => 0,
]);
check($recent->url() === '?view=course-comments&id=9&capitulo=3#comentario-10' && !$recent->answered && str_ends_with($recent->excerpt(), '…'), 'reciente: enlace al hilo, "sin responder" y extracto');

// -----------------------------------------------------------
Report::section('Códigos y scripts');
check(BusinessRuleException::of('COMMENT_NOT_FOUND')->status === 404 && BusinessRuleException::of('REACTION_NOT_FOUND')->status === 404, 'COMMENT_NOT_FOUND y REACTION_NOT_FOUND: 404');
check(BusinessRuleException::of('INVALID_COMMENT')->field === 'texto' && BusinessRuleException::of('REACTION_NAME_TAKEN')->field === 'nombre', 'INVALID_COMMENT en texto y REACTION_NAME_TAKEN en nombre');
check(BusinessRuleException::of('NO_COMMENTS_ON_EXAM')->status === 409, 'NO_COMMENTS_ON_EXAM: 409');
$views = dirname(__DIR__) . '/app/views';
foreach (['comments/js/comments.js', 'reactions/js/reactions.js', 'admin/js/reactions.js', 'messages/js/messages.js', 'catalog/js/reviews.js'] as $script) {
    $code = (string) file_get_contents("$views/$script");
    check(!preg_match('/innerHTML|outerHTML|insertAdjacentHTML|document\.write/', $code), "$script no arma HTML con texto");
}
check(!str_contains((string) file_get_contents("$views/admin/html/dashboard.html"), 'disabled>Administrar'), 'el botón "Administrar" de reacciones ya está habilitado');

Report::finish();
