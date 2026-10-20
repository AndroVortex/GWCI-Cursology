<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Enums\SelectionMode;
use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;
use App\Models\Mapping\UniqueKey;

/** Fila de `course_chapter_test_question`: pregunta de selección simple o múltiple. */
final class CourseChapterTestQuestion extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $courseChapterId,
        protected readonly int $sortOrder,
        protected readonly string $question,
        protected readonly SelectionMode $selectionMode,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            courseChapterId: $r->id('course_chapter_id'),
            sortOrder: $r->intUnsigned('sort_order'),
            question: $r->text('question'),
            selectionMode: $r->enum('selection_mode', SelectionMode::class),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'course_chapter_test_question',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('course_chapter_id', 'courseChapterId', 'BIGINT UNSIGNED'),
                new Column('sort_order', 'sortOrder', 'INT UNSIGNED'),
                new Column('question', 'question', 'TEXT'),
                new Column('selection_mode', 'selectionMode', 'VARCHAR(10)', default: "'single'", enum: SelectionMode::class),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_question_order', ['course_chapter_id', 'sort_order']),
                new UniqueKey('uq_question_chapter', ['id', 'course_chapter_id']),
            ],
            foreignKeys: [
                new ForeignKey('fk_question_chapter', ['course_chapter_id'], 'course_chapter', ['id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function courseChapterId(): string { return $this->courseChapterId; }
    public function sortOrder(): int { return $this->sortOrder; }
    public function question(): string { return $this->question; }
    public function selectionMode(): SelectionMode { return $this->selectionMode; }
}
