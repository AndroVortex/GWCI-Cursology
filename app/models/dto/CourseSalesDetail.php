<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Detalle de ventas de un curso con sus alumnos (sp_instructor_course_students). */
final class CourseSalesDetail
{
    /** @param list<CourseStudentRow> $students */
    private function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $coverImage,
        public readonly bool $active,
        public readonly ?DateTimeImmutable $publishedAt,
        public readonly string $currency,
        public readonly int $chapterCount,
        public readonly int $studentCount,
        public readonly string $avgProgress,
        public readonly string $revenue,
        public readonly array $students,
    ) {
    }

    /** @param list<list<array<string, mixed>>> $resultSets */
    public static function fromResultSets(array $resultSets): self
    {
        [$courseRows, $studentRows] = $resultSets + [[], []];
        $r = new RowReader(self::class, $courseRows[0] ?? []);

        return new self(
            $r->id('id'),
            $r->string('title', 200),
            $r->string('cover_image', 2048),
            $r->bool('active'),
            $r->nullableDateTime('published_at'),
            $r->string('currency', 3),
            $r->intUnsigned('chapter_count'),
            $r->intUnsigned('student_count'),
            $r->decimal('avg_progress', 5, 1),
            $r->decimal('revenue', 14, 2),
            array_map(CourseStudentRow::fromRow(...), $studentRows),
        );
    }
}
