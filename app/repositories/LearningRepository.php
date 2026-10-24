<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\StoredProcedures;
use App\Models\Dto\ChapterProgress;
use App\Models\Dto\LearningCourse;

/** Reproductor del alumno y su avance, exclusivamente mediante SP. */
final class LearningRepository
{
    use CallsProcedures;

    public function __construct(private readonly StoredProcedures $procedures)
    {
    }

    public function course(string $userId, string $courseId): LearningCourse
    {
        return LearningCourse::fromResultSets($this->resultSets('sp_learning_course', [$userId, $courseId]));
    }

    public function progress(string $userId, string $chapterId, int $positionSeconds, bool $markDone): ChapterProgress
    {
        return ChapterProgress::fromRow($this->requiredRow('sp_learning_progress', [$userId, $chapterId, $positionSeconds, $markDone]));
    }
}
