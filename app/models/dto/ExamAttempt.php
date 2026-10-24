<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Enums\SelectionMode;
use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/**
 * Un intento de examen (sp_attempt_get) desde sus copias. Mientras está abierto, las opciones
 * no traen las claves: correct y weight son null y earnedPoints también.
 *
 * @phpstan-type Answer array{id: string, text: string, selected: bool, correct: ?bool, weight: ?string}
 * @phpstan-type Question array{id: string, text: string, mode: SelectionMode, maxPoints: string,
 *     earnedPoints: ?string, answers: list<Answer>}
 */
final class ExamAttempt
{
    /** @param list<Question> $questions */
    private function __construct(
        public readonly string $id,
        public readonly string $chapterId,
        public readonly string $levelId,
        public readonly int $number,
        public readonly DateTimeImmutable $startedAt,
        public readonly ?DateTimeImmutable $submittedAt,
        public readonly string $minimumGrade,
        public readonly string $maxPoints,
        public readonly ?string $earnedPoints,
        public readonly ?string $grade,
        public readonly ?bool $passed,
        public readonly ?DateTimeImmutable $chapterCompletedAt,
        public readonly ?DateTimeImmutable $levelCompletedAt,
        public readonly ?DateTimeImmutable $courseCompletedAt,
        public readonly array $questions,
    ) {
    }

    /** @param list<list<array<string, mixed>>> $resultSets */
    public static function fromResultSets(array $resultSets): self
    {
        [$attemptRows, $questionRows, $answerRows] = $resultSets + [[], [], []];
        $r = new RowReader(self::class, $attemptRows[0] ?? []);
        $submitted = $r->nullableDateTime('submitted_at') !== null;

        $answersByQuestion = [];
        foreach ($answerRows as $row) {
            $a = new RowReader(self::class, $row);
            $answersByQuestion[$a->id('question_id')][] = [
                'id' => $a->id('answer_id'),
                'text' => $a->text('answer_snapshot'),
                'selected' => $submitted && $a->bool('selected'),
                'correct' => $submitted ? $a->bool('is_correct_snapshot') : null,
                'weight' => $submitted ? $a->decimal('weight_snapshot', 10, 2) : null,
            ];
        }

        $questions = [];
        foreach ($questionRows as $row) {
            $q = new RowReader(self::class, $row);
            $questions[] = [
                'id' => $q->id('question_id'),
                'text' => $q->text('question_snapshot'),
                'mode' => $q->enum('selection_mode_snapshot', SelectionMode::class),
                'maxPoints' => $q->decimal('max_points', 14, 2),
                'earnedPoints' => $submitted ? $q->decimal('earned_points', 14, 2) : null,
                'answers' => $answersByQuestion[$q->id('question_id')] ?? [],
            ];
        }

        return new self(
            $r->id('id'),
            $r->id('course_chapter_id'),
            $r->id('course_level_id'),
            $r->intUnsigned('attempt_number'),
            $r->dateTime('started_at'),
            $r->nullableDateTime('submitted_at'),
            $r->decimal('minimum_grade_snapshot', 5, 2),
            $r->decimal('max_points', 14, 2),
            $r->nullableDecimal('earned_points', 14, 2),
            $r->nullableDecimal('grade', 5, 2),
            $r->nullableBool('passed'),
            $r->nullableDateTime('chapter_completed_at'),
            $r->nullableDateTime('level_completed_at'),
            $r->nullableDateTime('course_completed_at'),
            $questions,
        );
    }

    public function isSubmitted(): bool
    {
        return $this->submittedAt !== null;
    }
}
