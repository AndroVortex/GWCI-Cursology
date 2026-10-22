<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\StoredProcedures;
use App\Models\Dto\CourseOutline;
use App\Models\Dto\InstructorCourseSummary;
use App\Models\Entities\Course;
use App\Models\Entities\CourseChapter;
use App\Models\Entities\CourseChapterContent;
use App\Models\Entities\CourseLevel;
use App\Services\ContentInput;
use App\Services\CourseInput;
use App\Services\LevelInput;
use App\Services\VerifiedMedia;
use UnexpectedValueException;

/**
 * Cursos del instructor, sus niveles, capítulos y contenido, exclusivamente mediante SP.
 * Cada SP comprueba que el curso sea del instructor; los de borrado y reemplazo
 * devuelven las referencias de los archivos que PHP debe borrar de Cloudinary.
 */
final class CourseRepository
{
    use CallsProcedures;

    public function __construct(private readonly StoredProcedures $procedures)
    {
    }

    public function create(string $instructorId, CourseInput $data, string $cover, string $video): Course
    {
        return Course::fromRow($this->requiredRow('sp_course_create', [
            $instructorId,
            $data->title,
            $data->description,
            $cover,
            $video,
            $data->certifierName,
            $data->fullCoursePrice,
            json_encode($data->categoryIds, JSON_THROW_ON_ERROR),
        ]));
    }

    /**
     * @param ?string $cover null = conservar la portada actual
     * @param ?string $video null = conservar el video actual
     * @return array{0: Course, 1: list<string>} [curso, referencias reemplazadas]
     */
    public function update(string $instructorId, string $courseId, CourseInput $data, ?string $cover, ?string $video): array
    {
        $sets = $this->resultSets('sp_course_update', [
            $instructorId,
            $courseId,
            $data->title,
            $data->description,
            $cover,
            $video,
            $data->certifierName,
            $data->fullCoursePrice,
            json_encode($data->categoryIds, JSON_THROW_ON_ERROR),
        ]);

        return [Course::fromRow(self::first($sets, 0, 'sp_course_update')), self::mediaRefs($sets[1][0] ?? [])];
    }

    /** @return list<InstructorCourseSummary> */
    public function listByInstructor(string $instructorId): array
    {
        return array_map(
            static fn (array $row): InstructorCourseSummary => InstructorCourseSummary::fromRow($row),
            $this->procedures->rows('sp_course_list_by_instructor', [$instructorId]),
        );
    }

    public function outline(string $instructorId, string $courseId): CourseOutline
    {
        return CourseOutline::fromResultSets($this->resultSets('sp_course_get_for_owner', [$instructorId, $courseId]));
    }

    public function createLevel(string $instructorId, string $courseId, LevelInput $data, string $video): CourseLevel
    {
        return CourseLevel::fromRow($this->requiredRow('sp_level_create', [
            $instructorId, $courseId, $data->title, $data->description, $video, $data->cost,
        ]));
    }

    /**
     * @param ?string $video null = conservar el video actual
     * @return array{0: CourseLevel, 1: list<string>} [nivel, referencias reemplazadas]
     */
    public function updateLevel(string $instructorId, string $levelId, LevelInput $data, ?string $video): array
    {
        $sets = $this->resultSets('sp_level_update', [
            $instructorId, $levelId, $data->title, $data->description, $video, $data->cost,
        ]);

        return [CourseLevel::fromRow(self::first($sets, 0, 'sp_level_update')), self::mediaRefs($sets[1][0] ?? [])];
    }

    /** @return list<string> referencias de los archivos del nivel eliminado */
    public function deleteLevel(string $instructorId, string $levelId): array
    {
        return $this->deletedMediaRefs('sp_level_delete', [$instructorId, $levelId]);
    }

    public function createChapter(string $instructorId, string $levelId, string $title): CourseChapter
    {
        return CourseChapter::fromRow($this->requiredRow('sp_chapter_create', [$instructorId, $levelId, $title]));
    }

    public function updateChapter(string $instructorId, string $chapterId, string $title): CourseChapter
    {
        return CourseChapter::fromRow($this->requiredRow('sp_chapter_update', [$instructorId, $chapterId, $title]));
    }

    /** @return list<string> referencias del contenido del capítulo eliminado */
    public function deleteChapter(string $instructorId, string $chapterId): array
    {
        return $this->deletedMediaRefs('sp_chapter_delete', [$instructorId, $chapterId]);
    }

    public function addContent(string $instructorId, string $chapterId, ContentInput $data, string $title, ?VerifiedMedia $media): CourseChapterContent
    {
        return CourseChapterContent::fromRow($this->requiredRow('sp_content_add', [
            $instructorId,
            $chapterId,
            $title,
            $data->type,
            $media?->reference() ?? $data->url,
            $data->text,
            $media?->mimeType,
            $media?->originalFilename,
            $media !== null ? (string) $media->bytes : null,
            $media?->durationSeconds,
            $data->isMain,
        ]));
    }

    /** @return list<string> referencia del archivo eliminado */
    public function deleteContent(string $instructorId, string $contentId): array
    {
        return $this->deletedMediaRefs('sp_content_delete', [$instructorId, $contentId]);
    }

    /**
     * @param list<list<array<string, mixed>>> $sets
     * @return array<string, mixed>
     */
    private static function first(array $sets, int $index, string $procedure): array
    {
        return $sets[$index][0] ?? throw new UnexpectedValueException(sprintf('%s no devolvió resultados.', $procedure));
    }
}
