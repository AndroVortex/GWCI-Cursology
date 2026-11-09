<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Un curso en la tabla "Ventas" del instructor (sp_instructor_sales, primer resultado). */
final class CourseSalesRow
{
    private function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $coverImage,
        public readonly bool $active,
        public readonly DateTimeImmutable $createdAt,
        public readonly string $currency,
        public readonly int $studentCount,
        public readonly string $avgProgress,
        public readonly string $revenue,
        public readonly ?string $ratingAvg,
        public readonly int $ratingCount,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);

        return new self(
            $r->id('id'),
            $r->string('title', 200),
            $r->string('cover_image', 2048),
            $r->bool('active'),
            $r->dateTime('created_at'),
            $r->string('currency', 3),
            $r->intUnsigned('student_count'),
            $r->decimal('avg_progress', 5, 1),
            $r->decimal('revenue', 14, 2),
            $r->nullableDecimal('rating_avg', 3, 1),
            $r->intUnsigned('rating_count'),
        );
    }
}
