<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\StoredProcedures;
use App\Models\Dto\AttemptSummary;
use App\Models\Dto\ExamAttempt;

/** Intentos de examen del alumno, exclusivamente mediante SP (la calificación es de la base). */
final class ExamAttemptRepository
{
    use CallsProcedures;

    public function __construct(private readonly StoredProcedures $procedures)
    {
    }

    public function start(string $userId, string $chapterId): ExamAttempt
    {
        return ExamAttempt::fromResultSets($this->resultSets('sp_attempt_start', [$userId, $chapterId]));
    }

    /** @param list<string> $answerIds opciones marcadas */
    public function submit(string $userId, string $attemptId, array $answerIds): ExamAttempt
    {
        $selected = json_encode($answerIds, JSON_THROW_ON_ERROR);

        return ExamAttempt::fromResultSets($this->resultSets('sp_attempt_submit', [$userId, $attemptId, $selected]));
    }

    /** @return list<AttemptSummary> */
    public function list(string $userId, string $chapterId): array
    {
        return array_map(AttemptSummary::fromRow(...), $this->resultSets('sp_attempt_list', [$userId, $chapterId])[0] ?? []);
    }
}
