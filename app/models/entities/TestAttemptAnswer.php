<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;

/**
 * Fila de `test_attempt_answer`: copia de cada opción de una pregunta del intento
 * y si el alumno la seleccionó. PK compuesta (attempt_id, question_id, answer_id).
 * is_correct_snapshot y weight_snapshot son claves del examen: uso interno.
 */
final class TestAttemptAnswer extends Entity
{
    private function __construct(
        protected readonly string $attemptId,
        protected readonly string $questionId,
        protected readonly string $answerId,
        protected readonly string $answerSnapshot,
        protected readonly bool $isCorrectSnapshot,
        protected readonly string $weightSnapshot,
        protected readonly bool $selected,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            attemptId: $r->id('attempt_id'),
            questionId: $r->id('question_id'),
            answerId: $r->id('answer_id'),
            answerSnapshot: $r->text('answer_snapshot'),
            isCorrectSnapshot: $r->bool('is_correct_snapshot'),
            weightSnapshot: $r->decimal('weight_snapshot', 10, 2),
            selected: $r->bool('selected'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'test_attempt_answer',
            columns: [
                new Column('attempt_id', 'attemptId', 'BIGINT UNSIGNED'),
                new Column('question_id', 'questionId', 'BIGINT UNSIGNED'),
                new Column('answer_id', 'answerId', 'BIGINT UNSIGNED'),
                new Column('answer_snapshot', 'answerSnapshot', 'TEXT'),
                new Column('is_correct_snapshot', 'isCorrectSnapshot', 'BOOLEAN'),
                new Column('weight_snapshot', 'weightSnapshot', 'DECIMAL(10,2)'),
                new Column('selected', 'selected', 'BOOLEAN', default: 'FALSE'),
            ],
            primaryKey: ['attempt_id', 'question_id', 'answer_id'],
            foreignKeys: [
                new ForeignKey('fk_attempt_answer_question', ['attempt_id', 'question_id'], 'test_attempt_question', ['attempt_id', 'question_id']),
                new ForeignKey('fk_attempt_answer_answer', ['answer_id', 'question_id'], 'test_question_answer', ['id', 'question_id']),
            ],
            redactedColumns: ['is_correct_snapshot', 'weight_snapshot'],
        );
    }

    public function attemptId(): string { return $this->attemptId; }
    public function questionId(): string { return $this->questionId; }
    public function answerId(): string { return $this->answerId; }
    public function answerSnapshot(): string { return $this->answerSnapshot; }
    /** Clave del examen congelada: no exponer antes de entregar el intento. */
    public function isCorrectSnapshot(): bool { return $this->isCorrectSnapshot; }
    /** Ponderación interna congelada DECIMAL(10,2). */
    public function weightSnapshot(): string { return $this->weightSnapshot; }
    public function selected(): bool { return $this->selected; }
}
