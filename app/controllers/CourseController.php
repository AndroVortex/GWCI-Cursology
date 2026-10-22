<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Presenters\CoursePresenter;
use App\Http\Request;
use App\Models\Dto\InstructorCourseSummary;
use App\Models\Entities\AppUser;
use App\Services\CourseService;

/**
 * Endpoints /api/instructor/*: el instructor de la sesión administra sus cursos.
 * Las rutas pasan por RequireAuth + RequireRole(instructor); los errores de validación
 * y de negocio (p. ej. COURSE_NOT_FOUND) los responde el router.
 */
final class CourseController
{
    public function __construct(private readonly CourseService $courses)
    {
    }

    /** GET /api/instructor/courses */
    public function listMine(Request $request): JsonResponse
    {
        return JsonResponse::data(['courses' => array_map(
            static fn (InstructorCourseSummary $summary): array => CoursePresenter::summary($summary),
            $this->courses->listMine($this->user($request)),
        )]);
    }

    /** POST /api/instructor/courses */
    public function create(Request $request): JsonResponse
    {
        $course = $this->courses->create($this->user($request), $request->json());

        return JsonResponse::data(['course' => CoursePresenter::course($course)], 201);
    }

    /** GET /api/instructor/courses/{id} */
    public function show(Request $request): JsonResponse
    {
        $outline = $this->courses->outline($this->user($request), $this->id($request));

        return JsonResponse::data(['course' => CoursePresenter::outline($outline, $this->courses->viewUrl(...))]);
    }

    /** PATCH /api/instructor/courses/{id} */
    public function update(Request $request): JsonResponse
    {
        $course = $this->courses->update($this->user($request), $this->id($request), $request->json());

        return JsonResponse::data(['course' => CoursePresenter::course($course)]);
    }

    /** POST /api/instructor/courses/{id}/levels */
    public function addLevel(Request $request): JsonResponse
    {
        $level = $this->courses->addLevel($this->user($request), $this->id($request), $request->json());

        return JsonResponse::data(['level' => CoursePresenter::level($level)], 201);
    }

    /** PATCH /api/instructor/levels/{id} */
    public function updateLevel(Request $request): JsonResponse
    {
        $level = $this->courses->updateLevel($this->user($request), $this->id($request), $request->json());

        return JsonResponse::data(['level' => CoursePresenter::level($level)]);
    }

    /** DELETE /api/instructor/levels/{id} */
    public function deleteLevel(Request $request): JsonResponse
    {
        $this->courses->deleteLevel($this->user($request), $this->id($request));

        return JsonResponse::noContent();
    }

    /** POST /api/instructor/levels/{id}/chapters */
    public function addChapter(Request $request): JsonResponse
    {
        $chapter = $this->courses->addChapter($this->user($request), $this->id($request), $request->json());

        return JsonResponse::data(['chapter' => CoursePresenter::chapter($chapter)], 201);
    }

    /** PATCH /api/instructor/chapters/{id} */
    public function updateChapter(Request $request): JsonResponse
    {
        $chapter = $this->courses->updateChapter($this->user($request), $this->id($request), $request->json());

        return JsonResponse::data(['chapter' => CoursePresenter::chapter($chapter)]);
    }

    /** DELETE /api/instructor/chapters/{id} */
    public function deleteChapter(Request $request): JsonResponse
    {
        $this->courses->deleteChapter($this->user($request), $this->id($request));

        return JsonResponse::noContent();
    }

    /** POST /api/instructor/chapters/{id}/contents */
    public function addContent(Request $request): JsonResponse
    {
        $content = $this->courses->addContent($this->user($request), $this->id($request), $request->json());

        return JsonResponse::data(['content' => CoursePresenter::content($content, $this->courses->viewUrl($content))], 201);
    }

    /** DELETE /api/instructor/contents/{id} */
    public function deleteContent(Request $request): JsonResponse
    {
        $this->courses->deleteContent($this->user($request), $this->id($request));

        return JsonResponse::noContent();
    }

    private function user(Request $request): AppUser
    {
        /** @var AppUser */
        return $request->attribute('user');
    }

    private function id(Request $request): string
    {
        return (string) $request->attribute('id');
    }
}
