<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Un curso del kardex del alumno (sp_student_courses). */
final class KardexEntry
{
    /** @param list<string> $categories */
    private function __construct(
        public readonly string $courseId,
        public readonly string $title,
        public readonly string $coverImage,
        public readonly bool $active,
        public readonly array $categories,
        public readonly DateTimeImmutable $enrolledAt,
        public readonly ?DateTimeImmutable $lastAccessedAt,
        public readonly ?DateTimeImmutable $completedAt,
        public readonly int $chapterCount,
        public readonly int $chaptersDone,
        public readonly int $levelCount,
        public readonly int $levelsOwned,
        public readonly ?string $lastChapterId,
        public readonly ?string $lastChapterTitle,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);
        $categories = $r->nullableText('category_names');

        return new self(
            $r->id('course_id'),
            $r->string('title', 200),
            $r->string('cover_image', 2048),
            $r->bool('active'),
            $categories === null ? [] : explode('|', $categories),
            $r->dateTime('enrolled_at'),
            $r->nullableDateTime('last_accessed_at'),
            $r->nullableDateTime('completed_at'),
            $r->intUnsigned('chapter_count'),
            $r->intUnsigned('chapters_done'),
            $r->intUnsigned('level_count'),
            $r->intUnsigned('levels_owned'),
            $r->nullableId('last_chapter_id'),
            $r->nullableString('last_chapter_title', 200),
        );
    }

    public function isCompleted(): bool
    {
        return $this->completedAt !== null;
    }

    /** Capítulos terminados sobre los del curso, de 0 a 100. */
    public function progressPercent(): int
    {
        return $this->chapterCount === 0 ? 0 : intdiv(100 * $this->chaptersDone, $this->chapterCount);
    }
}
