<?php

declare(strict_types=1);

namespace App\Models\Dto;

/** Una página de opiniones de un curso con su resumen de calificaciones. */
final class ReviewPage
{
    /** @param list<CourseReview> $reviews */
    public function __construct(
        public readonly ReviewSummary $summary,
        public readonly array $reviews,
        public readonly int $total,
        public readonly int $page,
        public readonly int $pages,
    ) {
    }

    public function hasMore(): bool
    {
        return $this->page < $this->pages;
    }
}
