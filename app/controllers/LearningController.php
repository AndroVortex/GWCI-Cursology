<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Models\Entities\AppUser;
use App\Services\LearningService;

/** Avance del alumno en el reproductor (RequireAuth + RequireRole(student) + CSRF). */
final class LearningController
{
    public function __construct(private readonly LearningService $learning)
    {
    }

    /** POST /api/student/chapters/{id}/progress ({posicion, terminado}) */
    public function progress(Request $request): JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->attribute('user');
        $progress = $this->learning->progress($user, (string) $request->attribute('id'), $request->json());

        return JsonResponse::data(['progress' => [
            'chapterId' => $progress->chapterId,
            'positionSeconds' => $progress->positionSeconds,
            'completed' => $progress->completedAt !== null,
            'levelCompleted' => $progress->levelCompletedAt !== null,
            'courseCompleted' => $progress->courseCompletedAt !== null,
        ]]);
    }
}
