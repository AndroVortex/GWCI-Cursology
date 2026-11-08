<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Dto\MyReview;
use App\Models\Dto\ReviewPage;
use App\Models\Entities\AppUser;
use App\Models\Entities\CourseOpinion;
use App\Models\Enums\UserRole;
use App\Repositories\ReviewRepository;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;
use App\Services\Validation\UserRules;

/**
 * Calificación (1 a 5) y opinión de los cursos: solo quien lo terminó, y puede editarlas.
 * El instructor del curso o un admin ocultan una opinión con su motivo; con la opinión oculta,
 * sus estrellas dejan de contar en el promedio.
 */
final class ReviewService
{
    public const MAX_OPINION_CHARS = 2000;
    public const MIN_REASON_CHARS = 3;
    public const MAX_REASON_CHARS = 500;
    private const PAGE_SIZE = 10;
    private const MAX_PAGE = 1000;

    public function __construct(private readonly ReviewRepository $reviews)
    {
    }

    /**
     * @param array<string, mixed> $input {calificacion: 1..5, opinion?: string}
     * @throws ValidationException|BusinessRuleException NOT_ENROLLED, COURSE_NOT_COMPLETED, OPINION_HIDDEN
     */
    public function save(AppUser $student, string $courseId, array $input): MyReview
    {
        self::requireId($courseId, 'NOT_ENROLLED');
        [$rate, $opinion] = self::validateReview($input);

        return $this->reviews->save($student->id(), $courseId, $rate, $opinion);
    }

    /** Lo del alumno en ese curso; null si no hay sesión de alumno. */
    public function mine(?AppUser $user, string $courseId): ?MyReview
    {
        if ($user === null || $user->role() !== UserRole::Student || !self::isId($courseId)) {
            return null;
        }

        return $this->reviews->mine($user->id(), $courseId);
    }

    /**
     * @param array<string, mixed> $query {pagina?}
     * @param ?AppUser $viewer quien consulta, para marcar su reacción en cada opinión
     * @throws BusinessRuleException COURSE_NOT_FOUND
     */
    public function list(string $courseId, array $query = [], ?AppUser $viewer = null): ReviewPage
    {
        self::requireId($courseId, 'COURSE_NOT_FOUND');
        $page = UserRules::text($query, 'pagina');
        $page = preg_match('/^[1-9][0-9]{0,3}$/D', $page) === 1 ? min((int) $page, self::MAX_PAGE) : 1;

        [$summary, $reviews, $total] = $this->reviews->list($courseId, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE, $viewer?->id());

        return new ReviewPage($summary, $reviews, $total, $page, max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE)));
    }

    /**
     * @param array<string, mixed> $input {motivo}
     * @throws ValidationException|BusinessRuleException OPINION_NOT_FOUND
     */
    public function hide(AppUser $actor, string $opinionId, array $input): CourseOpinion
    {
        self::requireId($opinionId, 'OPINION_NOT_FOUND');
        $reason = self::validateReason($input);

        return $this->reviews->hide($actor->id(), $opinionId, $reason);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: int, 1: ?string} [estrellas, opinión o null]
     * @throws ValidationException
     */
    public static function validateReview(array $input): array
    {
        $rate = $input['calificacion'] ?? null;
        $rate = is_string($rate) && preg_match('/^[1-5]$/D', trim($rate)) === 1 ? (int) trim($rate) : $rate;
        $opinion = $input['opinion'] ?? null;
        $opinion = is_string($opinion) ? trim($opinion) : $opinion;

        $errors = array_filter([
            'calificacion' => is_int($rate) && $rate >= 1 && $rate <= 5 ? null : 'Elige de 1 a 5 estrellas.',
            'opinion' => match (true) {
                $opinion !== null && !is_string($opinion) => 'Opinión inválida.',
                is_string($opinion) && mb_strlen($opinion) > self::MAX_OPINION_CHARS => sprintf('No puede exceder %d caracteres.', self::MAX_OPINION_CHARS),
                default => null,
            },
        ]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$rate, $opinion === '' ? null : $opinion];
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public static function validateReason(array $input): string
    {
        $reason = UserRules::text($input, 'motivo');
        $length = mb_strlen($reason);
        if ($length < self::MIN_REASON_CHARS || $length > self::MAX_REASON_CHARS) {
            throw new ValidationException(['motivo' => sprintf('Escribe el motivo (de %d a %d caracteres).', self::MIN_REASON_CHARS, self::MAX_REASON_CHARS)]);
        }

        return $reason;
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
