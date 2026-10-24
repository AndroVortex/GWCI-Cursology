<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\StoredProcedures;
use App\Models\Dto\CatalogCourse;
use App\Models\Dto\PublicCourse;

/** Catálogo público: cursos publicados y activos, exclusivamente mediante SP. */
final class CatalogRepository
{
    use CallsProcedures;

    public function __construct(private readonly StoredProcedures $procedures)
    {
    }

    /**
     * @param ?string $pattern texto ya escapado para LIKE (\% y \_), o null
     * @param 'recientes'|'titulo'|'precio' $sort
     * @return array{0: list<CatalogCourse>, 1: int} [tarjetas de la página, total]
     */
    public function search(?string $pattern, ?string $categoryId, string $sort, int $limit, int $offset): array
    {
        $sets = $this->resultSets('sp_catalog_search', [$pattern, $categoryId, $sort, $limit, $offset]);

        return [
            array_map(static fn (array $row): CatalogCourse => CatalogCourse::fromRow($row), $sets[0] ?? []),
            (int) ($sets[1][0]['total'] ?? 0),
        ];
    }

    /** @throws \App\Services\Exceptions\BusinessRuleException COURSE_NOT_FOUND */
    public function course(string $courseId): PublicCourse
    {
        return PublicCourse::fromResultSets($this->resultSets('sp_catalog_course', [$courseId]));
    }
}
