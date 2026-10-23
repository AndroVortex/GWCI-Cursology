<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\MediaUploadException;
use App\Models\Dto\CourseOutline;
use App\Models\Dto\InstructorCourseSummary;
use App\Models\Entities\AppUser;
use App\Models\Entities\Course;
use App\Models\Entities\CourseChapter;
use App\Models\Entities\CourseChapterContent;
use App\Models\Entities\CourseLevel;
use App\Repositories\CourseRepository;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;
use Throwable;

/**
 * Casos de uso del instructor sobre sus cursos en borrador: datos generales, niveles,
 * capítulos y contenido. La multimedia se verifica con MediaService antes de llamar
 * al SP; si el SP falla se borra lo recién subido, y lo reemplazado se borra después.
 */
final class CourseService
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly CourseValidator $validator,
        private readonly MediaService $media,
    ) {
    }

    /** @return list<InstructorCourseSummary> */
    public function listMine(AppUser $instructor): array
    {
        return $this->courses->listByInstructor($instructor->id());
    }

    /** @throws BusinessRuleException COURSE_NOT_FOUND */
    public function outline(AppUser $instructor, string $courseId): CourseOutline
    {
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $courseId) !== 1) {
            throw BusinessRuleException::of('COURSE_NOT_FOUND');
        }

        return $this->courses->outline($instructor->id(), $courseId);
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException|BusinessRuleException|MediaUploadException
     */
    public function create(AppUser $instructor, array $input): Course
    {
        $data = $this->validator->validateCourse($input, creating: true);

        $cover = $this->media->confirm($instructor, 'course_cover', $data->cover, 'portada');
        $video = $this->confirmOrDiscard($instructor, 'course_video', $data->video, 'video', [$cover]);

        return $this->withRollback(
            fn (): Course => $this->courses->create($instructor->id(), $data, $cover->reference(), $video->reference()),
            [$cover, $video],
        );
    }

    /**
     * @param array<string, mixed> $input portada y video son opcionales (sin ellos se conservan)
     * @throws ValidationException|BusinessRuleException|MediaUploadException
     */
    public function update(AppUser $instructor, string $courseId, array $input): Course
    {
        $data = $this->validator->validateCourse($input, creating: false);

        $cover = $data->cover === null ? null : $this->media->confirm($instructor, 'course_cover', $data->cover, 'portada');
        $video = $data->video === null ? null : $this->confirmOrDiscard($instructor, 'course_video', $data->video, 'video', [$cover]);

        [$course, $replaced] = $this->withRollback(
            fn (): array => $this->courses->update($instructor->id(), $courseId, $data, $cover?->reference(), $video?->reference()),
            [$cover, $video],
        );
        $this->discardAll($replaced);

        return $course;
    }

    /** @throws BusinessRuleException COURSE_INACTIVE, COURSE_ALREADY_PUBLISHED o el requisito que falte */
    public function publish(AppUser $instructor, string $courseId): Course
    {
        return $this->courses->publish($instructor->id(), $courseId);
    }

    /** @throws BusinessRuleException COURSE_NOT_PUBLISHED, COURSE_HAS_STUDENTS */
    public function unpublish(AppUser $instructor, string $courseId): Course
    {
        return $this->courses->unpublish($instructor->id(), $courseId);
    }

    /**
     * Baja lógica o reactivación.
     *
     * @param array<string, mixed> $input {activo: bool}
     * @throws ValidationException|BusinessRuleException
     */
    public function setActive(AppUser $instructor, string $courseId, array $input): Course
    {
        if (!is_bool($input['activo'] ?? null)) {
            throw new ValidationException(['activo' => 'Indica si el curso queda activo (true o false).']);
        }

        return $this->courses->setActive($instructor->id(), $courseId, $input['activo']);
    }

    /** @param array<string, mixed> $input */
    public function addLevel(AppUser $instructor, string $courseId, array $input): CourseLevel
    {
        $data = $this->validator->validateLevel($input, creating: true);
        $video = $this->media->confirm($instructor, 'level_video', $data->video, 'video');

        return $this->withRollback(
            fn (): CourseLevel => $this->courses->createLevel($instructor->id(), $courseId, $data, $video->reference()),
            [$video],
        );
    }

    /** @param array<string, mixed> $input */
    public function updateLevel(AppUser $instructor, string $levelId, array $input): CourseLevel
    {
        $data = $this->validator->validateLevel($input, creating: false);
        $video = $data->video === null ? null : $this->media->confirm($instructor, 'level_video', $data->video, 'video');

        [$level, $replaced] = $this->withRollback(
            fn (): array => $this->courses->updateLevel($instructor->id(), $levelId, $data, $video?->reference()),
            [$video],
        );
        $this->discardAll($replaced);

        return $level;
    }

    public function deleteLevel(AppUser $instructor, string $levelId): void
    {
        $this->discardAll($this->courses->deleteLevel($instructor->id(), $levelId));
    }

    /** @param array<string, mixed> $input {direccion: arriba | abajo} */
    public function moveLevel(AppUser $instructor, string $levelId, array $input): CourseLevel
    {
        return $this->courses->moveLevel($instructor->id(), $levelId, $this->validator->validateMoveDirection($input));
    }

    /** @param array<string, mixed> $input {direccion: arriba | abajo} */
    public function moveChapter(AppUser $instructor, string $chapterId, array $input): CourseChapter
    {
        return $this->courses->moveChapter($instructor->id(), $chapterId, $this->validator->validateMoveDirection($input));
    }

    /** @param array<string, mixed> $input */
    public function addChapter(AppUser $instructor, string $levelId, array $input): CourseChapter
    {
        return $this->courses->createChapter($instructor->id(), $levelId, $this->validator->validateChapterTitle($input));
    }

    /** @param array<string, mixed> $input */
    public function updateChapter(AppUser $instructor, string $chapterId, array $input): CourseChapter
    {
        return $this->courses->updateChapter($instructor->id(), $chapterId, $this->validator->validateChapterTitle($input));
    }

    public function deleteChapter(AppUser $instructor, string $chapterId): void
    {
        $this->discardAll($this->courses->deleteChapter($instructor->id(), $chapterId));
    }

    /** @param array<string, mixed> $input */
    public function addContent(AppUser $instructor, string $chapterId, array $input): CourseChapterContent
    {
        $data = $this->validator->validateContent($input);
        $media = $data->purpose === null ? null : $this->media->confirm($instructor, $data->purpose, $data->upload, 'archivo');

        // Para archivos, si no hay título se usa el nombre original sin extensión.
        $title = $data->title !== ''
            ? $data->title
            : mb_substr(pathinfo((string) $media?->originalFilename, PATHINFO_FILENAME) ?: 'Archivo', 0, 200);

        return $this->withRollback(
            fn (): CourseChapterContent => $this->courses->addContent($instructor->id(), $chapterId, $data, $title, $media),
            [$media],
        );
    }

    public function deleteContent(AppUser $instructor, string $contentId): void
    {
        $this->discardAll($this->courses->deleteContent($instructor->id(), $contentId));
    }

    /** URL para que el dueño vea el archivo (temporal y firmada si es protegido). */
    public function viewUrl(CourseChapterContent $content): ?string
    {
        $reference = $content->contentUri();

        return $reference === null ? null : $this->media->viewUrl($reference, $content->originalFilename());
    }

    /**
     * Confirma una subida; si falla, borra las que ya se habían confirmado.
     *
     * @param list<?VerifiedMedia> $alreadyConfirmed
     */
    private function confirmOrDiscard(AppUser $user, string $purpose, mixed $upload, string $field, array $alreadyConfirmed): VerifiedMedia
    {
        try {
            return $this->media->confirm($user, $purpose, $upload, $field);
        } catch (Throwable $exception) {
            array_map($this->media->discard(...), $alreadyConfirmed);
            throw $exception;
        }
    }

    /**
     * Ejecuta el SP; si falla, borra de Cloudinary lo que se subió para esta operación.
     *
     * @template T
     * @param callable(): T $operation
     * @param list<?VerifiedMedia> $uploaded
     * @return T
     */
    private function withRollback(callable $operation, array $uploaded): mixed
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            array_map($this->media->discard(...), $uploaded);
            throw $exception;
        }
    }

    /** @param list<string> $references */
    private function discardAll(array $references): void
    {
        array_map($this->media->discard(...), $references);
    }
}
