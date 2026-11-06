<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;

/** Cifras del panel del instructor (sp_instructor_overview). */
final class InstructorOverview
{
    private function __construct(
        public readonly string $revenueTotal,
        public readonly int $studentTotal,
        public readonly ?string $ratingAvg,
        public readonly int $ratingCount,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);

        return new self(
            $r->decimal('revenue_total', 14, 2),
            $r->intUnsigned('student_total'),
            $r->nullableDecimal('rating_avg', 3, 1),
            $r->intUnsigned('rating_count'),
        );
    }
}
