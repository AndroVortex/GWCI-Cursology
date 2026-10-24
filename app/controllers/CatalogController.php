<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Presenters\CatalogPresenter;
use App\Http\Request;
use App\Services\CatalogService;

/** Endpoints públicos /api/catalog/*: no requieren sesión. */
final class CatalogController
{
    public function __construct(private readonly CatalogService $catalog)
    {
    }

    /** GET /api/catalog/courses?q=&categoria=&orden=&pagina= */
    public function search(Request $request): JsonResponse
    {
        return JsonResponse::data(CatalogPresenter::page($this->catalog->search($request->queryParams())));
    }

    /** GET /api/catalog/courses/{id} */
    public function show(Request $request): JsonResponse
    {
        return JsonResponse::data(['course' => CatalogPresenter::course($this->catalog->course((string) $request->attribute('id')))]);
    }
}
