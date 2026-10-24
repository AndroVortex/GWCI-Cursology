<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Avance del alumno en un capítulo después de guardarlo (sp_learning_progress). */
final class ChapterProgress
{
    private function __construct(
        public readonly string $chapterId,
        public readonly int $positionSeconds,
        public readonly ?DateTimeImmutable $completedAt,
        public readonly ?DateTimeImmutable $levelCompletedAt,
        public readonly ?DateTimeImmutable $courseCompletedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);

        return new self(
            $r->id('course_chapter_id'),
            $r->intUnsigned('video_position_seconds'),
            $r->nullableDateTime('completed_at'),
            $r->nullableDateTime('level_completed_at'),
            $r->nullableDateTime('course_completed_at'),
        );
    }
}
