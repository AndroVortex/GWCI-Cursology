<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Presenters\ReactionPresenter;
use App\Http\Request;
use App\Models\Entities\AppUser;
use App\Models\Entities\Reaction;
use App\Services\ReactionService;

/** Catálogo de reacciones (admin) y reacciones a opiniones y mensajes. */
final class ReactionController
{
    public function __construct(private readonly ReactionService $reactions)
    {
    }

    /** GET /api/reactions (público): las activas, para el selector */
    public function catalog(Request $request): JsonResponse
    {
        return JsonResponse::data(['reactions' => ReactionPresenter::catalog($this->reactions->active())]);
    }

    /** GET /api/admin/reactions */
    public function adminList(Request $request): JsonResponse
    {
        return JsonResponse::data(['reactions' => array_map(ReactionPresenter::admin(...), $this->reactions->all())]);
    }

    /** POST /api/admin/reactions ({emoji, nombre}) */
    public function create(Request $request): JsonResponse
    {
        $reaction = $this->reactions->create($this->user($request), $request->json());

        return JsonResponse::data(['reaction' => ReactionPresenter::admin($reaction)], 201);
    }

    /** PATCH /api/admin/reactions/{id} ({emoji, nombre} y/o {activa}) */
    public function update(Request $request): JsonResponse
    {
        $reaction = $this->reactions->update($this->user($request), (string) $request->attribute('id'), $request->json());

        return JsonResponse::data(['reaction' => ReactionPresenter::admin($reaction)]);
    }

    /** POST /api/admin/reactions/defaults: agrega las sugeridas que falten */
    public function addDefaults(Request $request): JsonResponse
    {
        $added = $this->reactions->addDefaults($this->user($request));

        return JsonResponse::data(['added' => array_map(static fn (Reaction $reaction): array => ReactionPresenter::admin($reaction), $added)], $added === [] ? 200 : 201);
    }

    /** PUT /api/opinions/{id}/reaction ({reaccion: id | null}) */
    public function reactToOpinion(Request $request): JsonResponse
    {
        $summary = $this->reactions->reactToOpinion($this->user($request), (string) $request->attribute('id'), $request->json());

        return JsonResponse::data(['reactions' => ReactionPresenter::summary($summary)]);
    }

    /** PUT /api/messages/{id}/reaction ({reaccion: id | null}) */
    public function reactToMessage(Request $request): JsonResponse
    {
        $summary = $this->reactions->reactToMessage($this->user($request), (string) $request->attribute('id'), $request->json());

        return JsonResponse::data(['reactions' => ReactionPresenter::summary($summary)]);
    }

    private function user(Request $request): AppUser
    {
        /** @var AppUser */
        return $request->attribute('user');
    }
}
