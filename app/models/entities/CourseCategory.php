<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;

/**
 * Fila de `course_category`: relación N:M curso-categoría.
 * PK compuesta (course_id, category_id); no tiene id propio.
 */
final class CourseCategory extends Entity
{
    private function __construct(
        protected readonly string $courseId,
        protected readonly string $categoryId,
        protected readonly bool $active,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            courseId: $r->id('course_id'),
            categoryId: $r->id('category_id'),
            active: $r->bool('active'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'course_category',
            columns: [
                new Column('course_id', 'courseId', 'BIGINT UNSIGNED'),
                new Column('category_id', 'categoryId', 'BIGINT UNSIGNED'),
                new Column('active', 'active', 'BOOLEAN', default: 'TRUE'),
            ],
            primaryKey: ['course_id', 'category_id'],
            foreignKeys: [
                new ForeignKey('fk_course_category_course', ['course_id'], 'course', ['id']),
                new ForeignKey('fk_course_category_category', ['category_id'], 'category', ['id']),
            ],
        );
    }

    public function courseId(): string { return $this->courseId; }
    public function categoryId(): string { return $this->categoryId; }
    public function active(): bool { return $this->active; }
}
