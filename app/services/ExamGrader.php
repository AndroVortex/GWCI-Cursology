<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Dto\ExamResult;
use App\Models\Enums\SelectionMode;
use InvalidArgumentException;

/**
 * Califica un examen con la regla acordada:
 * - cada pregunta vale la suma de los pesos de sus opciones correctas;
 * - se suman los pesos de las correctas que se marcaron, pero si se marcó alguna
 *   incorrecta, la pregunta vale 0 (marcar todo no sirve);
 * - calificación = ROUND(100 * puntos / máximo, 2), como exige ck_attempt_result, y
 *   aprueba con calificación >= mínimo.
 *
 * Trabaja con centavos enteros (sin float). La división se redondea primero a 6 decimales
 * y luego a 2, igual que MySQL con div_precision_increment = 4, para que el resultado
 * coincida con el que calculará el SP de intentos.
 */
final class ExamGrader
{
    /**
     * @param list<array{id: string, mode: SelectionMode, answers: list<array{id: string, correct: bool, weight: string}>}> $questions
     * @param array<string, list<string>> $selected id de pregunta => ids de las opciones marcadas
     * @param string $minimumGrade DECIMAL(5,2) para aprobar
     */
    public function grade(array $questions, array $selected, string $minimumGrade): ExamResult
    {
        $maxTotal = 0;
        $earnedTotal = 0;
        $details = [];

        foreach ($questions as $question) {
            $marked = array_flip($selected[$question['id']] ?? []);
            $max = 0;
            $earned = 0;
            $markedWrong = false;
            foreach ($question['answers'] as $answer) {
                $weight = self::cents($answer['weight']);
                if ($answer['correct']) {
                    $max += $weight;
                }
                if (!isset($marked[$answer['id']])) {
                    continue;
                }
                if ($answer['correct']) {
                    $earned += $weight;
                } else {
                    $markedWrong = true;
                }
            }
            if ($markedWrong) {
                $earned = 0;
            }

            $maxTotal += $max;
            $earnedTotal += $earned;
            $details[] = [
                'id' => $question['id'],
                'maxPoints' => self::decimal($max),
                'earnedPoints' => self::decimal($earned),
                'markedWrong' => $markedWrong,
            ];
        }

        if ($maxTotal <= 0) {
            throw new InvalidArgumentException('Un examen sin preguntas no se puede calificar.');
        }

        $micro = self::roundDiv(100_000_000 * $earnedTotal, $maxTotal);
        $grade = self::roundDiv($micro, 10_000);

        return new ExamResult(
            maxPoints: self::decimal($maxTotal),
            earnedPoints: self::decimal($earnedTotal),
            grade: self::decimal($grade),
            minimumGrade: self::decimal(self::cents($minimumGrade)),
            passed: $grade >= self::cents($minimumGrade),
            questions: $details,
        );
    }

    /** "12.5" o "12.50" -> 1250. */
    private static function cents(string $amount): int
    {
        [$integer, $fraction] = explode('.', $amount) + [1 => '0'];

        return (int) $integer * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private static function decimal(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** División entera redondeando la mitad hacia arriba (números no negativos). */
    private static function roundDiv(int $numerator, int $denominator): int
    {
        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
