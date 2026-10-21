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
 * Fila de `user_course_level`: derecho de acceso del alumno a un nivel.
 *
 * PK compuesta (user_id, course_level_id). Las FK compuestas obligan a que la
 * inscripción, el nivel y la compra pertenezcan al mismo alumno y curso.
 */
final class UserCourseLevel extends Entity
{
    private function __construct(
        protected readonly string $userId,
        protected readonly string $courseLevelId,
        protected readonly string $courseId,
        protected readonly string $purchaseId,
        protected readonly DateTimeImmutable $grantedAt,
        protected readonly ?DateTimeImmutable $lastAccessedAt,
        protected readonly ?DateTimeImmutable $completedAt,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            userId: $r->id('user_id'),
            courseLevelId: $r->id('course_level_id'),
            courseId: $r->id('course_id'),
            purchaseId: $r->id('purchase_id'),
            grantedAt: $r->dateTime('granted_at'),
            lastAccessedAt: $r->nullableDateTime('last_accessed_at'),
            completedAt: $r->nullableDateTime('completed_at'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'user_course_level',
            columns: [
                new Column('user_id', 'userId', 'BIGINT UNSIGNED'),
                new Column('course_level_id', 'courseLevelId', 'BIGINT UNSIGNED'),
                new Column('course_id', 'courseId', 'BIGINT UNSIGNED'),
                new Column('purchase_id', 'purchaseId', 'BIGINT UNSIGNED'),
                new Column('granted_at', 'grantedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('last_accessed_at', 'lastAccessedAt', 'DATETIME(6)', nullable: true),
                new Column('completed_at', 'completedAt', 'DATETIME(6)', nullable: true),
            ],
            primaryKey: ['user_id', 'course_level_id'],
            foreignKeys: [
                new ForeignKey('fk_access_enrollment', ['user_id', 'course_id'], 'user_courses', ['user_id', 'course_id']),
                new ForeignKey('fk_access_level', ['course_level_id', 'course_id'], 'course_level', ['id', 'course_id']),
                new ForeignKey('fk_access_purchase', ['purchase_id', 'user_id', 'course_id'], 'purchase', ['id', 'user_id', 'course_id']),
            ],
        );
    }

    public function userId(): string { return $this->userId; }
    public function courseLevelId(): string { return $this->courseLevelId; }
    public function courseId(): string { return $this->courseId; }
    public function purchaseId(): string { return $this->purchaseId; }
    public function grantedAt(): DateTimeImmutable { return $this->grantedAt; }
    public function lastAccessedAt(): ?DateTimeImmutable { return $this->lastAccessedAt; }
    public function completedAt(): ?DateTimeImmutable { return $this->completedAt; }
}
