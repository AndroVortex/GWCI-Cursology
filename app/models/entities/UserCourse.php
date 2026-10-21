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
 * Fila de `user_courses`: inscripción del alumno al curso (fuente del Kardex).
 * No equivale al acceso a niveles, que está en user_course_level. PK compuesta
 * (user_id, course_id). El porcentaje de avance se calcula, no se guarda.
 */
final class UserCourse extends Entity
{
    private function __construct(
        protected readonly string $userId,
        protected readonly string $courseId,
        protected readonly DateTimeImmutable $enrolledAt,
        protected readonly ?DateTimeImmutable $lastAccessedAt,
        protected readonly ?DateTimeImmutable $completedAt,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            userId: $r->id('user_id'),
            courseId: $r->id('course_id'),
            enrolledAt: $r->dateTime('enrolled_at'),
            lastAccessedAt: $r->nullableDateTime('last_accessed_at'),
            completedAt: $r->nullableDateTime('completed_at'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'user_courses',
            columns: [
                new Column('user_id', 'userId', 'BIGINT UNSIGNED'),
                new Column('course_id', 'courseId', 'BIGINT UNSIGNED'),
                new Column('enrolled_at', 'enrolledAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('last_accessed_at', 'lastAccessedAt', 'DATETIME(6)', nullable: true),
                new Column('completed_at', 'completedAt', 'DATETIME(6)', nullable: true),
            ],
            primaryKey: ['user_id', 'course_id'],
            foreignKeys: [
                new ForeignKey('fk_enrollment_user', ['user_id'], 'app_user', ['id']),
                new ForeignKey('fk_enrollment_course', ['course_id'], 'course', ['id']),
            ],
        );
    }

    public function userId(): string { return $this->userId; }
    public function courseId(): string { return $this->courseId; }
    public function enrolledAt(): DateTimeImmutable { return $this->enrolledAt; }
    public function lastAccessedAt(): ?DateTimeImmutable { return $this->lastAccessedAt; }
    public function completedAt(): ?DateTimeImmutable { return $this->completedAt; }
}
