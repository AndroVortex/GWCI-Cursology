<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Presenters\CommentPresenter;
use App\Http\Presenters\ReactionPresenter;
use App\Http\Request;
use App\Models\Entities\AppUser;
use App\Services\CommentService;

/** Comentarios de los capítulos: lista, publicar, editar, eliminar, ocultar y reaccionar. */
final class CommentController
{
    public function __construct(private readonly CommentService $comments)
    {
    }

    /** GET /api/chapters/{id}/comments?pagina= */
    public function list(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $page = $this->comments->list($user, (string) $request->attribute('id'), $request->queryParams());

        return JsonResponse::data(CommentPresenter::page($page, $user->id()));
    }

    /** POST /api/chapters/{id}/comments ({texto, responde_a?}) */
    public function create(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $comment = $this->comments->create($user, (string) $request->attribute('id'), $request->json());

        return JsonResponse::data(['comment' => CommentPresenter::comment($comment, $user->id(), false)], 201);
    }

    /** PATCH /api/comments/{id} ({texto}); solo el autor */
    public function update(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $comment = $this->comments->update($user, (string) $request->attribute('id'), $request->json());

        return JsonResponse::data(['comment' => CommentPresenter::comment($comment, $user->id(), false)]);
    }

    /** DELETE /api/comments/{id}; solo el autor */
    public function delete(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $comment = $this->comments->delete($user, (string) $request->attribute('id'));

        return JsonResponse::data(['comment' => CommentPresenter::comment($comment, $user->id(), false)]);
    }

    /** POST /api/comments/{id}/hide ({motivo}); instructor del curso o admin */
    public function hide(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $comment = $this->comments->hide($user, (string) $request->attribute('id'), $request->json());

        return JsonResponse::data(['comment' => CommentPresenter::comment($comment, $user->id(), true)]);
    }

    /** PUT /api/comments/{id}/reaction ({reaccion: id | null}) */
    public function react(Request $request): JsonResponse
    {
        $summary = $this->comments->react($this->user($request), (string) $request->attribute('id'), $request->json());

        return JsonResponse::data(['reactions' => ReactionPresenter::summary($summary)]);
    }

    /** GET /api/instructor/courses/{id}/comments?capitulo=&estado=sin_responder&pagina= */
    public function instructorList(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $page = $this->comments->forInstructor($user, (string) $request->attribute('id'), $request->queryParams());

        return JsonResponse::data(CommentPresenter::instructorPage($page, $user->id()));
    }

    private function user(Request $request): AppUser
    {
        /** @var AppUser */
        return $request->attribute('user');
    }
}
