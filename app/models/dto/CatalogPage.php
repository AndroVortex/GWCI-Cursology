<?php

declare(strict_types=1);

namespace App\Models\Dto;

/** Una página del catálogo con los filtros ya normalizados (CatalogService::search). */
final class CatalogPage
{
    /**
     * @param list<CatalogCourse> $courses
     * @param array{q: string, categoria: ?string, orden: string, pagina: int} $filters
     */
    public function __construct(
        public readonly array $courses,
        public readonly int $total,
        public readonly int $page,
        public readonly int $pages,
        public readonly array $filters,
    ) {
    }
}
