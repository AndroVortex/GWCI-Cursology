<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Fila de sp_course_list_by_instructor: un curso con sus conteos para el panel. */
final class InstructorCourseSummary
{
    private function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $coverImage,
        public readonly ?string $fullCoursePrice,
        public readonly string $currency,
        public readonly bool $active,
        public readonly ?DateTimeImmutable $publishedAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly int $levelCount,
        public readonly int $chapterCount,
        public readonly int $studentCount,
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
            $r->nullableDecimal('full_course_price', 12, 2),
            $r->string('currency', 3),
            $r->bool('active'),
            $r->nullableDateTime('published_at'),
            $r->dateTime('created_at'),
            $r->dateTime('updated_at'),
            $r->intUnsigned('level_count'),
            $r->intUnsigned('chapter_count'),
            $r->intUnsigned('student_count'),
        );
    }

    public function isPublished(): bool
    {
        return $this->publishedAt !== null;
    }
}
