<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Dto\ChapterProgress;
use App\Models\Dto\LearningCourse;
use App\Models\Entities\AppUser;
use App\Models\Entities\CourseChapterContent;
use App\Repositories\LearningRepository;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;

/**
 * El reproductor del alumno: el curso con su avance y el guardado de la posición del video.
 * El acceso a cada nivel lo decide la base (user_course_level).
 */
final class LearningService
{
    /** Un video cuenta como visto al llegar a este porcentaje (sp_learning_progress usa la misma regla). */
    public const WATCHED_PERCENT = 90;

    private const MAX_POSITION_SECONDS = 359999;

    public function __construct(
        private readonly LearningRepository $learning,
        private readonly MediaService $media,
    ) {
    }

    /** @throws BusinessRuleException NOT_ENROLLED */
    public function course(AppUser $student, string $courseId): LearningCourse
    {
        if (!self::isId($courseId)) {
            throw BusinessRuleException::of('NOT_ENROLLED');
        }

        return $this->learning->course($student->id(), $courseId);
    }

    /**
     * @param array<string, mixed> $input {posicion?: int, terminado?: bool}
     * @throws ValidationException|BusinessRuleException CHAPTER_NOT_FOUND, NO_LEVEL_ACCESS, EXAM_NOT_MARKABLE
     */
    public function progress(AppUser $student, string $chapterId, array $input): ChapterProgress
    {
        if (!self::isId($chapterId)) {
            throw BusinessRuleException::of('CHAPTER_NOT_FOUND');
        }
        $position = $input['posicion'] ?? 0;
        $markDone = $input['terminado'] ?? false;

        $errors = array_filter([
            'posicion' => is_int($position) && $position >= 0 && $position <= self::MAX_POSITION_SECONDS ? null : 'Posición del video inválida.',
            'terminado' => is_bool($markDone) ? null : 'Valor inválido.',
        ]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->learning->progress($student->id(), $chapterId, $position, $markDone);
    }

    /** Solo para contenido que sp_learning_course devolvió (niveles con acceso). */
    public function viewUrl(CourseChapterContent $content): ?string
    {
        $reference = $content->contentUri();

        return $reference === null ? null : $this->media->viewUrl($reference, $content->originalFilename());
    }

    public static function isWatched(int $positionSeconds, ?int $durationSeconds): bool
    {
        return $durationSeconds !== null && $durationSeconds > 0
            && $positionSeconds * 100 >= $durationSeconds * self::WATCHED_PERCENT;
    }

    private static function isId(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,19}$/D', $value) === 1;
    }
}
