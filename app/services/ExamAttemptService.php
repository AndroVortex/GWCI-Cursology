<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Dto\AttemptSummary;
use App\Models\Dto\ExamAttempt;
use App\Models\Entities\AppUser;
use App\Repositories\ExamAttemptRepository;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;

/**
 * Exámenes del alumno: intentos ilimitados, uno abierto a la vez. La base copia las
 * preguntas al abrir el intento y lo califica al entregarlo (regla A, igual que ExamGrader).
 */
final class ExamAttemptService
{
    private const MAX_SELECTED = 1000;

    public function __construct(private readonly ExamAttemptRepository $attempts)
    {
    }

    /** @throws BusinessRuleException CHAPTER_NOT_FOUND, CHAPTER_NOT_EXAM, NO_LEVEL_ACCESS */
    public function start(AppUser $student, string $chapterId): ExamAttempt
    {
        return $this->attempts->start($student->id(), self::requireId($chapterId, 'CHAPTER_NOT_FOUND'));
    }

    /**
     * @param array<string, mixed> $input {respuestas: list<id>}
     * @throws ValidationException|BusinessRuleException ATTEMPT_NOT_FOUND, ATTEMPT_ALREADY_SUBMITTED, INVALID_SELECTION
     */
    public function submit(AppUser $student, string $attemptId, array $input): ExamAttempt
    {
        $attemptId = self::requireId($attemptId, 'ATTEMPT_NOT_FOUND');
        $selected = $input['respuestas'] ?? null;
        if (!is_array($selected) || !array_is_list($selected) || count($selected) > self::MAX_SELECTED) {
            throw new ValidationException(['respuestas' => 'Respuestas inválidas.']);
        }

        $ids = [];
        foreach ($selected as $value) {
            $id = is_int($value) ? (string) $value : $value;
            if (!is_string($id) || !self::isId($id)) {
                throw new ValidationException(['respuestas' => 'Respuestas inválidas.']);
            }
            $ids[] = $id;
        }

        return $this->attempts->submit($student->id(), $attemptId, $ids);
    }

    /** @return list<AttemptSummary> */
    public function attempts(AppUser $student, string $chapterId): array
    {
        return $this->attempts->list($student->id(), self::requireId($chapterId, 'CHAPTER_NOT_FOUND'));
    }

    private static function isId(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,19}$/D', $value) === 1;
    }

    private static function requireId(string $value, string $code): string
    {
        return self::isId($value) ? $value : throw BusinessRuleException::of($code);
    }
}
