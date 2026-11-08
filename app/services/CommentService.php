<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Dto\ChapterComment;
use App\Models\Dto\CommentPage;
use App\Models\Dto\InstructorCommentPage;
use App\Models\Dto\ReactionSummary;
use App\Models\Dto\RecentComment;
use App\Models\Entities\AppUser;
use App\Repositories\CommentRepository;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;
use App\Services\Validation\UserRules;

/**
 * Comentarios de los capítulos de video: los escriben el alumno con acceso al nivel y el
 * instructor del curso, con respuestas de un solo nivel. El autor edita o elimina los suyos; el
 * instructor del curso (o un admin) oculta los de otros con un motivo.
 */
final class CommentService
{
    public const MAX_COMMENT_CHARS = 2000;
    public const PAGE_SIZE = 10;
    public const INSTRUCTOR_PAGE_SIZE = 20;
    public const RECENT_COMMENTS = 5;
    private const MAX_PAGE = 1000;

    public function __construct(private readonly CommentRepository $comments)
    {
    }

    /**
     * @param array<string, mixed> $query {pagina?}
     * @throws BusinessRuleException CHAPTER_NOT_FOUND, NO_COMMENTS_ON_EXAM, NO_LEVEL_ACCESS
     */
    public function list(AppUser $user, string $chapterId, array $query = []): CommentPage
    {
        self::requireId($chapterId, 'CHAPTER_NOT_FOUND');

        return $this->comments->list($user->id(), $chapterId, self::page($query), self::PAGE_SIZE);
    }

    /**
     * @param array<string, mixed> $input {texto, responde_a?}
     * @throws ValidationException|BusinessRuleException CHAPTER_NOT_FOUND, NO_COMMENTS_ON_EXAM, NO_LEVEL_ACCESS, COMMENT_NOT_FOUND
     */
    public function create(AppUser $user, string $chapterId, array $input): ChapterComment
    {
        self::requireId($chapterId, 'CHAPTER_NOT_FOUND');
        $text = self::validateText($input);
        $parent = $input['responde_a'] ?? null;
        $parent = is_int($parent) ? (string) $parent : $parent;
        if ($parent === '') {
            $parent = null;
        }
        if ($parent !== null && (!is_string($parent) || !self::isId(trim($parent)))) {
            throw new ValidationException(['responde_a' => 'Comentario inválido.']);
        }

        return $this->comments->create($user->id(), $chapterId, $parent === null ? null : trim($parent), $text);
    }

    /**
     * @param array<string, mixed> $input {texto}
     * @throws ValidationException|BusinessRuleException COMMENT_NOT_FOUND
     */
    public function update(AppUser $user, string $commentId, array $input): ChapterComment
    {
        self::requireId($commentId, 'COMMENT_NOT_FOUND');

        return $this->comments->update($user->id(), $commentId, self::validateText($input));
    }

    /** @throws BusinessRuleException COMMENT_NOT_FOUND */
    public function delete(AppUser $user, string $commentId): ChapterComment
    {
        self::requireId($commentId, 'COMMENT_NOT_FOUND');

        return $this->comments->delete($user->id(), $commentId);
    }

    /**
     * @param array<string, mixed> $input {motivo}
     * @throws ValidationException|BusinessRuleException COMMENT_NOT_FOUND
     */
    public function hide(AppUser $user, string $commentId, array $input): ChapterComment
    {
        self::requireId($commentId, 'COMMENT_NOT_FOUND');

        return $this->comments->hide($user->id(), $commentId, ReviewService::validateReason($input));
    }

    /**
     * @param array<string, mixed> $input {reaccion: id | null}
     * @throws ValidationException|BusinessRuleException COMMENT_NOT_FOUND, REACTION_NOT_FOUND
     */
    public function react(AppUser $user, string $commentId, array $input): ReactionSummary
    {
        self::requireId($commentId, 'COMMENT_NOT_FOUND');

        return $this->comments->react($user->id(), $commentId, ReactionService::reactionInput($input));
    }

    /**
     * Los comentarios de un curso del instructor, con los filtros {capitulo, estado, pagina}.
     *
     * @param array<string, mixed> $query
     * @throws BusinessRuleException COURSE_NOT_FOUND
     */
    public function forInstructor(AppUser $instructor, string $courseId, array $query = []): InstructorCommentPage
    {
        self::requireId($courseId, 'COURSE_NOT_FOUND');

        return $this->comments->forInstructor($instructor->id(), $courseId, self::instructorFilters($query), self::page($query), self::INSTRUCTOR_PAGE_SIZE);
    }

    /**
     * @return list<RecentComment>
     * @throws BusinessRuleException NOT_INSTRUCTOR
     */
    public function recentForInstructor(AppUser $instructor): array
    {
        return $this->comments->recentForInstructor($instructor->id(), self::RECENT_COMMENTS);
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public static function validateText(array $input): string
    {
        $text = $input['texto'] ?? null;
        $text = is_string($text) && mb_check_encoding($text, 'UTF-8') ? trim($text) : '';

        return match (true) {
            $text === '' => throw new ValidationException(['texto' => 'Escribe tu comentario.']),
            mb_strlen($text) > self::MAX_COMMENT_CHARS => throw new ValidationException(['texto' => sprintf('No puede exceder %d caracteres.', self::MAX_COMMENT_CHARS)]),
            default => $text,
        };
    }

    /**
     * Filtros válidos de la vista del instructor; los inválidos se ignoran.
     *
     * @param array<string, mixed> $query
     * @return array{capitulo: string, estado: string}
     */
    public static function instructorFilters(array $query): array
    {
        $chapter = UserRules::text($query, 'capitulo');
        $state = UserRules::text($query, 'estado');

        return [
            'capitulo' => self::isId($chapter) ? $chapter : '',
            'estado' => $state === 'sin_responder' ? 'sin_responder' : '',
        ];
    }

    /** @param array<string, mixed> $query */
    private static function page(array $query): int
    {
        $page = UserRules::text($query, 'pagina');

        return preg_match('/^[1-9][0-9]{0,3}$/D', $page) === 1 ? min((int) $page, self::MAX_PAGE) : 1;
    }

    private static function isId(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,19}$/D', $value) === 1;
    }

    private static function requireId(string $value, string $code): void
    {
        if (!self::isId($value)) {
            throw BusinessRuleException::of($code);
        }
    }
}
