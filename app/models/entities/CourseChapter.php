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
 * Fila de `course_chapter`: capítulo de video o examen.
 * minimum_passing_grade solo existe en exámenes (escala 0-100, dos decimales).
 */
final class CourseChapter extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $courseLevelId,
        protected readonly string $title,
        protected readonly int $sortOrder,
        protected readonly bool $isTest,
        protected readonly ?string $minimumPassingGrade,
        protected readonly DateTimeImmutable $createdAt,
        protected readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            courseLevelId: $r->id('course_level_id'),
            title: $r->string('title', 200),
            sortOrder: $r->intUnsigned('sort_order'),
            isTest: $r->bool('is_test'),
            minimumPassingGrade: $r->nullableDecimal('minimum_passing_grade', 5, 2),
            createdAt: $r->dateTime('created_at'),
            updatedAt: $r->dateTime('updated_at'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'course_chapter',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('course_level_id', 'courseLevelId', 'BIGINT UNSIGNED'),
                new Column('title', 'title', 'VARCHAR(200)'),
                new Column('sort_order', 'sortOrder', 'INT UNSIGNED'),
                new Column('is_test', 'isTest', 'BOOLEAN', default: 'FALSE'),
                new Column('minimum_passing_grade', 'minimumPassingGrade', 'DECIMAL(5,2)', nullable: true),
                new Column('created_at', 'createdAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('updated_at', 'updatedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)', onUpdate: 'CURRENT_TIMESTAMP(6)'),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_chapter_order', ['course_level_id', 'sort_order']),
                new UniqueKey('uq_chapter_level', ['id', 'course_level_id']),
            ],
            foreignKeys: [
                new ForeignKey('fk_chapter_level', ['course_level_id'], 'course_level', ['id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function courseLevelId(): string { return $this->courseLevelId; }
    public function title(): string { return $this->title; }
    public function sortOrder(): int { return $this->sortOrder; }
    public function isTest(): bool { return $this->isTest; }
    /** DECIMAL(5,2) exacto como cadena; NULL en capítulos que no son examen. */
    public function minimumPassingGrade(): ?string { return $this->minimumPassingGrade; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
}
