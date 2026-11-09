<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Entities\CourseOpinion;
use App\Models\Entities\CourseRate;
use App\Models\Mapping\RowReader;

/** Lo que el alumno ya calificó y opinó de un curso, y si puede hacerlo (sp_review_mine). */
final class MyReview
{
    private function __construct(
        public readonly bool $enrolled,
        public readonly bool $completed,
        public readonly ?CourseRate $rate,
        public readonly ?CourseOpinion $opinion,
    ) {
    }

    /** @param list<list<array<string, mixed>>> $resultSets */
    public static function fromResultSets(array $resultSets): self
    {
        [$accessRows, $rateRows, $opinionRows] = $resultSets + [[], [], []];
        $r = new RowReader(self::class, $accessRows[0] ?? ['enrolled' => 0, 'completed' => 0]);

        return new self(
            $r->bool('enrolled'),
            $r->bool('completed'),
            isset($rateRows[0]) ? CourseRate::fromRow($rateRows[0]) : null,
            isset($opinionRows[0]) ? CourseOpinion::fromRow($opinionRows[0]) : null,
        );
    }

    /** Solo con el curso terminado. */
    public function canReview(): bool
    {
        return $this->completed;
    }

    public function isHidden(): bool
    {
        return $this->opinion !== null && !$this->opinion->active();
    }
}
