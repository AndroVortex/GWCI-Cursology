<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Models\Dto\AttemptSummary;
use App\Models\Dto\ExamAttempt;
use App\Models\Entities\AppUser;
use App\Services\ExamAttemptService;

/** Exámenes del alumno (RequireAuth + RequireRole(student); CSRF en los POST). */
final class ExamAttemptController
{
    public function __construct(private readonly ExamAttemptService $attempts)
    {
    }

    /** POST /api/student/chapters/{id}/attempts: abre un intento o retoma el abierto. */
    public function start(Request $request): JsonResponse
    {
        $attempt = $this->attempts->start($this->user($request), (string) $request->attribute('id'));

        return JsonResponse::data(['attempt' => self::present($attempt)], 201);
    }

    /** POST /api/student/attempts/{id}/submit ({respuestas: [ids]}) */
    public function submit(Request $request): JsonResponse
    {
        $attempt = $this->attempts->submit($this->user($request), (string) $request->attribute('id'), $request->json());

        return JsonResponse::data(['attempt' => self::present($attempt)]);
    }

    /** GET /api/student/chapters/{id}/attempts */
    public function list(Request $request): JsonResponse
    {
        $attempts = $this->attempts->attempts($this->user($request), (string) $request->attribute('id'));

        return JsonResponse::data(['attempts' => array_map(static fn (AttemptSummary $attempt): array => [
            'id' => $attempt->id,
            'number' => $attempt->number,
            'startedAt' => $attempt->startedAt->format(DATE_ATOM),
            'submittedAt' => $attempt->submittedAt?->format(DATE_ATOM),
            'minimumGrade' => $attempt->minimumGrade,
            'grade' => $attempt->grade,
            'passed' => $attempt->passed,
        ], $attempts)]);
    }

    /**
     * Abierto: preguntas y opciones sin las claves. Entregado: también los puntos por pregunta,
     * las opciones marcadas y las correctas.
     *
     * @return array<string, mixed>
     */
    public static function present(ExamAttempt $attempt): array
    {
        $submitted = $attempt->isSubmitted();

        return [
            'id' => $attempt->id,
            'chapterId' => $attempt->chapterId,
            'number' => $attempt->number,
            'submitted' => $submitted,
            'minimumGrade' => $attempt->minimumGrade,
            'maxPoints' => $attempt->maxPoints,
            'earnedPoints' => $attempt->earnedPoints,
            'grade' => $attempt->grade,
            'passed' => $attempt->passed,
            'chapterCompleted' => $attempt->chapterCompletedAt !== null,
            'levelCompleted' => $attempt->levelCompletedAt !== null,
            'courseCompleted' => $attempt->courseCompletedAt !== null,
            'questions' => array_map(static fn (array $question): array => array_filter([
                'id' => $question['id'],
                'text' => $question['text'],
                'mode' => $question['mode']->value,
                'maxPoints' => $question['maxPoints'],
                'earnedPoints' => $question['earnedPoints'],
                'answers' => array_map(static fn (array $answer): array => $submitted
                    ? ['id' => $answer['id'], 'text' => $answer['text'], 'selected' => $answer['selected'], 'correct' => $answer['correct'], 'weight' => $answer['weight']]
                    : ['id' => $answer['id'], 'text' => $answer['text']], $question['answers']),
            ], static fn (mixed $value): bool => $value !== null), $attempt->questions),
        ];
    }

    private function user(Request $request): AppUser
    {
        /** @var AppUser */
        return $request->attribute('user');
    }
}
