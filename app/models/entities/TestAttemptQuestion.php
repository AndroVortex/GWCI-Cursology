<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Enums\SelectionMode;
use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;

/**
 * Fila de `test_attempt_question`: copia de una pregunta dentro de un intento.
 * PK compuesta (attempt_id, question_id). Los *_snapshot congelan el banco de
 * preguntas al iniciar el intento.
 */
final class TestAttemptQuestion extends Entity
{
    private function __construct(
        protected readonly string $attemptId,
        protected readonly string $questionId,
        protected readonly string $courseChapterId,
        protected readonly string $questionSnapshot,
        protected readonly SelectionMode $selectionModeSnapshot,
        protected readonly string $maxPoints,
        protected readonly ?string $earnedPoints,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            attemptId: $r->id('attempt_id'),
            questionId: $r->id('question_id'),
            courseChapterId: $r->id('course_chapter_id'),
            questionSnapshot: $r->text('question_snapshot'),
            selectionModeSnapshot: $r->enum('selection_mode_snapshot', SelectionMode::class),
            maxPoints: $r->decimal('max_points', 14, 2),
            earnedPoints: $r->nullableDecimal('earned_points', 14, 2),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'test_attempt_question',
            columns: [
                new Column('attempt_id', 'attemptId', 'BIGINT UNSIGNED'),
                new Column('question_id', 'questionId', 'BIGINT UNSIGNED'),
                new Column('course_chapter_id', 'courseChapterId', 'BIGINT UNSIGNED'),
                new Column('question_snapshot', 'questionSnapshot', 'TEXT'),
                new Column('selection_mode_snapshot', 'selectionModeSnapshot', 'VARCHAR(10)', enum: SelectionMode::class),
                new Column('max_points', 'maxPoints', 'DECIMAL(14,2)'),
                new Column('earned_points', 'earnedPoints', 'DECIMAL(14,2)', nullable: true),
            ],
            primaryKey: ['attempt_id', 'question_id'],
            foreignKeys: [
                new ForeignKey('fk_attempt_question_attempt', ['attempt_id', 'course_chapter_id'], 'test_attempt', ['id', 'course_chapter_id']),
                new ForeignKey('fk_attempt_question_question', ['question_id', 'course_chapter_id'], 'course_chapter_test_question', ['id', 'course_chapter_id']),
            ],
        );
    }

    public function attemptId(): string { return $this->attemptId; }
    public function questionId(): string { return $this->questionId; }
    public function courseChapterId(): string { return $this->courseChapterId; }
    public function questionSnapshot(): string { return $this->questionSnapshot; }
    public function selectionModeSnapshot(): SelectionMode { return $this->selectionModeSnapshot; }
    public function maxPoints(): string { return $this->maxPoints; }
    public function earnedPoints(): ?string { return $this->earnedPoints; }
}
