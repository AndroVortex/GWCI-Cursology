<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Enums\SelectionMode;

/** Datos validados de una pregunta de examen con sus opciones (CourseValidator::validateQuestion). */
final class QuestionInput
{
    /**
     * @param list<array{answer: string, correct: bool, weight: string}> $answers en el orden en
     *        que se muestran; las incorrectas llevan weight "0.00"
     */
    public function __construct(
        public readonly string $question,
        public readonly SelectionMode $mode,
        public readonly array $answers,
    ) {
    }

    /** Las opciones como las lee el SP con JSON_TABLE. */
    public function answersJson(): string
    {
        return json_encode(array_map(
            static fn (array $answer): array => [
                'answer' => $answer['answer'],
                'is_correct' => $answer['correct'] ? 1 : 0,
                'weight' => $answer['weight'],
            ],
            $this->answers,
        ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
