<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;
use DateTimeImmutable;

/**
 * Fila de `user_chapter_progress`: avance del alumno en un capítulo.
 *
 * PK compuesta (user_id, course_chapter_id). video_position_seconds solo sirve
 * para reanudar; no prueba que el video se haya visto completo.
 */
final class UserChapterProgress extends Entity
{
    private function __construct(
        protected readonly string $userId,
        protected readonly string $courseChapterId,
        protected readonly string $courseLevelId,
        protected readonly DateTimeImmutable $startedAt,
        protected readonly DateTimeImmutable $lastAccessedAt,
        protected readonly int $videoPositionSeconds,
        protected readonly ?DateTimeImmutable $completedAt,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            userId: $r->id('user_id'),
            courseChapterId: $r->id('course_chapter_id'),
            courseLevelId: $r->id('course_level_id'),
            startedAt: $r->dateTime('started_at'),
            lastAccessedAt: $r->dateTime('last_accessed_at'),
            videoPositionSeconds: $r->intUnsigned('video_position_seconds'),
            completedAt: $r->nullableDateTime('completed_at'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'user_chapter_progress',
            columns: [
                new Column('user_id', 'userId', 'BIGINT UNSIGNED'),
                new Column('course_chapter_id', 'courseChapterId', 'BIGINT UNSIGNED'),
                new Column('course_level_id', 'courseLevelId', 'BIGINT UNSIGNED'),
                new Column('started_at', 'startedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('last_accessed_at', 'lastAccessedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('video_position_seconds', 'videoPositionSeconds', 'INT UNSIGNED', default: '0'),
                new Column('completed_at', 'completedAt', 'DATETIME(6)', nullable: true),
            ],
            primaryKey: ['user_id', 'course_chapter_id'],
            foreignKeys: [
                new ForeignKey('fk_progress_access', ['user_id', 'course_level_id'], 'user_course_level', ['user_id', 'course_level_id']),
                new ForeignKey('fk_progress_chapter', ['course_chapter_id', 'course_level_id'], 'course_chapter', ['id', 'course_level_id']),
            ],
        );
    }

    public function userId(): string { return $this->userId; }
    public function courseChapterId(): string { return $this->courseChapterId; }
    public function courseLevelId(): string { return $this->courseLevelId; }
    public function startedAt(): DateTimeImmutable { return $this->startedAt; }
    public function lastAccessedAt(): DateTimeImmutable { return $this->lastAccessedAt; }
    public function videoPositionSeconds(): int { return $this->videoPositionSeconds; }
    public function completedAt(): ?DateTimeImmutable { return $this->completedAt; }
}
