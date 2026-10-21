<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;
use App\Models\Mapping\UniqueKey;
use DateTimeImmutable;

/**
 * Fila de `course_rate`: una calificación de 1 a 5 por alumno y curso (editable).
 * El promedio se calcula al consultar; no se almacena.
 */
final class CourseRate extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $ratedBy,
        protected readonly string $courseId,
        protected readonly int $rate,
        protected readonly DateTimeImmutable $ratedAt,
        protected readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            ratedBy: $r->id('rated_by'),
            courseId: $r->id('course_id'),
            rate: $r->tinyIntUnsigned('rate'),
            ratedAt: $r->dateTime('rated_at'),
            updatedAt: $r->dateTime('updated_at'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'course_rate',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('rated_by', 'ratedBy', 'BIGINT UNSIGNED'),
                new Column('course_id', 'courseId', 'BIGINT UNSIGNED'),
                new Column('rate', 'rate', 'TINYINT UNSIGNED'),
                new Column('rated_at', 'ratedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('updated_at', 'updatedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)', onUpdate: 'CURRENT_TIMESTAMP(6)'),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_course_rate_user', ['rated_by', 'course_id']),
            ],
            foreignKeys: [
                new ForeignKey('fk_rate_enrollment', ['rated_by', 'course_id'], 'user_courses', ['user_id', 'course_id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function ratedBy(): string { return $this->ratedBy; }
    public function courseId(): string { return $this->courseId; }
    public function rate(): int { return $this->rate; }
    public function ratedAt(): DateTimeImmutable { return $this->ratedAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
}
