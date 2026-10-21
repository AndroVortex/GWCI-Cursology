<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;
use App\Models\Mapping\UniqueKey;

/**
 * Fila de `test_question_answer`: opción de una pregunta.
 * is_correct y weight son claves del examen: uso interno, ocultas en volcados
 * y nunca enviadas al alumno antes de entregar el intento.
 */
final class TestQuestionAnswer extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $questionId,
        protected readonly int $sortOrder,
        protected readonly string $answer,
        protected readonly bool $isCorrect,
        protected readonly string $weight,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            questionId: $r->id('question_id'),
            sortOrder: $r->intUnsigned('sort_order'),
            answer: $r->text('answer'),
            isCorrect: $r->bool('is_correct'),
            weight: $r->decimal('weight', 10, 2),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'test_question_answer',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('question_id', 'questionId', 'BIGINT UNSIGNED'),
                new Column('sort_order', 'sortOrder', 'INT UNSIGNED'),
                new Column('answer', 'answer', 'TEXT'),
                new Column('is_correct', 'isCorrect', 'BOOLEAN', default: 'FALSE'),
                new Column('weight', 'weight', 'DECIMAL(10,2)', default: '0.00'),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_answer_order', ['question_id', 'sort_order']),
                new UniqueKey('uq_answer_question', ['id', 'question_id']),
            ],
            foreignKeys: [
                new ForeignKey('fk_answer_question', ['question_id'], 'course_chapter_test_question', ['id']),
            ],
            redactedColumns: ['is_correct', 'weight'],
        );
    }

    public function id(): string { return $this->id; }
    public function questionId(): string { return $this->questionId; }
    public function sortOrder(): int { return $this->sortOrder; }
    public function answer(): string { return $this->answer; }
    /** Clave del examen: no exponer al alumno. */
    public function isCorrect(): bool { return $this->isCorrect; }
    /** Ponderación interna DECIMAL(10,2): no exponer al alumno. */
    public function weight(): string { return $this->weight; }
}
