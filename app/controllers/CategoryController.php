<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Models\Entities\AppUser;
use App\Models\Entities\Category;
use App\Services\CategoryService;

/** Endpoints de categorías: lista pública de activas y administración (solo admin). */
final class CategoryController
{
    public function __construct(private readonly CategoryService $categories)
    {
    }

    /** GET /api/categories */
    public function listActive(Request $request): JsonResponse
    {
        return JsonResponse::data(['categories' => array_map(self::present(...), $this->categories->active())]);
    }

    /** GET /api/admin/categories */
    public function listAll(Request $request): JsonResponse
    {
        return JsonResponse::data(['categories' => array_map(self::present(...), $this->categories->all())]);
    }

    /** POST /api/admin/categories (application/json: {nombre, descripcion}) */
    public function create(Request $request): JsonResponse
    {
        $category = $this->categories->create($this->user($request), $request->json());

        return JsonResponse::data(['category' => self::present($category)], 201);
    }

    /** PATCH /api/admin/categories/{id} (application/json: {nombre, descripcion} y/o {activa}) */
    public function update(Request $request): JsonResponse
    {
        $category = $this->categories->update($this->user($request), (string) $request->attribute('id'), $request->json());

        return JsonResponse::data(['category' => self::present($category)]);
    }

    /** @return array<string, string|bool|null> */
    private static function present(Category $category): array
    {
        return [
            'id' => $category->id(),
            'name' => $category->name(),
            'description' => $category->description(),
            'active' => $category->active(),
            'updatedAt' => $category->updatedAt()->format(DATE_ATOM),
        ];
    }

    private function user(Request $request): AppUser
    {
        /** @var AppUser */
        return $request->attribute('user');
    }
}
