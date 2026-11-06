<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\StoredProcedures;
use App\Models\Dto\CourseReview;
use App\Models\Dto\MyReview;
use App\Models\Dto\ReviewSummary;
use App\Models\Entities\CourseOpinion;

/** Calificaciones y opiniones de los cursos, exclusivamente mediante SP. */
final class ReviewRepository
{
    use CallsProcedures;

    public function __construct(private readonly StoredProcedures $procedures)
    {
    }

    public function save(string $userId, string $courseId, int $rate, ?string $opinion): MyReview
    {
        return MyReview::fromResultSets($this->resultSets('sp_review_save', [$userId, $courseId, $rate, $opinion]));
    }

    public function mine(string $userId, string $courseId): MyReview
    {
        return MyReview::fromResultSets($this->resultSets('sp_review_mine', [$userId, $courseId]));
    }

    /** @return array{0: ReviewSummary, 1: list<CourseReview>, 2: int} [resumen, opiniones, total] */
    public function list(string $courseId, int $limit, int $offset): array
    {
        [$summaryRows, $reviewRows, $totalRows] = $this->resultSets('sp_review_list', [$courseId, $limit, $offset]) + [[], [], []];

        return [
            ReviewSummary::fromRow($summaryRows[0] ?? []),
            array_map(CourseReview::fromRow(...), $reviewRows),
            (int) ($totalRows[0]['total'] ?? 0),
        ];
    }

    public function hide(string $actorId, string $opinionId, string $reason): CourseOpinion
    {
        return CourseOpinion::fromRow($this->requiredRow('sp_review_hide', [$actorId, $opinionId, $reason]));
    }
}
