<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Entities\CourseChapter;
use App\Models\Entities\CourseChapterTestQuestion;
use App\Models\Entities\TestQuestionAnswer;

/**
 * Un examen con sus preguntas y opciones, de sp_exam_get_for_owner (tres result sets).
 * Incluye las claves (is_correct, weight): solo para el instructor dueño.
 */
final class ExamOutline
{
    /**
     * @param list<CourseChapterTestQuestion> $questions
     * @param array<string, list<TestQuestionAnswer>> $answersByQuestion
     */
    private function __construct(
        public readonly CourseChapter $chapter,
        public readonly array $questions,
        private readonly array $answersByQuestion,
    ) {
    }

    /** @param list<list<array<string, mixed>>> $resultSets */
    public static function fromResultSets(array $resultSets): self
    {
        [$chapterRows, $questionRows, $answerRows] = $resultSets + [[], [], []];

        $answers = [];
        foreach ($answerRows as $row) {
            $answer = TestQuestionAnswer::fromRow($row);
            $answers[$answer->questionId()][] = $answer;
        }

        return new self(
            CourseChapter::fromRow($chapterRows[0] ?? []),
            array_map(static fn (array $row): CourseChapterTestQuestion => CourseChapterTestQuestion::fromRow($row), $questionRows),
            $answers,
        );
    }

    /** @return list<TestQuestionAnswer> */
    public function answers(string $questionId): array
    {
        return $this->answersByQuestion[$questionId] ?? [];
    }

    /**
     * Las preguntas en el formato de ExamGrader.
     *
     * @return list<array{id: string, mode: \App\Models\Enums\SelectionMode, answers: list<array{id: string, correct: bool, weight: string}>}>
     */
    public function gradingQuestions(): array
    {
        return array_map(fn (CourseChapterTestQuestion $question): array => [
            'id' => $question->id(),
            'mode' => $question->selectionMode(),
            'answers' => array_map(static fn (TestQuestionAnswer $answer): array => [
                'id' => $answer->id(),
                'correct' => $answer->isCorrect(),
                'weight' => $answer->weight(),
            ], $this->answers($question->id())),
        ], $this->questions);
    }
}
