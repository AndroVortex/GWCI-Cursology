<?php

declare(strict_types=1);

namespace App\Models\Dto;

/** Calificación de un examen (ExamGrader). Importes y calificaciones como DECIMAL(…,2) exactos. */
final class ExamResult
{
    /**
     * @param list<array{id: string, maxPoints: string, earnedPoints: string, markedWrong: bool}> $questions
     */
    public function __construct(
        public readonly string $maxPoints,
        public readonly string $earnedPoints,
        public readonly string $grade,
        public readonly string $minimumGrade,
        public readonly bool $passed,
        public readonly array $questions,
    ) {
    }
}
