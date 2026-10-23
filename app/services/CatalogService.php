<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Dto\CatalogCourse;
use App\Models\Dto\CatalogPage;
use App\Models\Dto\PublicCourse;
use App\Repositories\CatalogRepository;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Validation\UserRules;

/**
 * Catálogo público: búsqueda y página de cada curso. Solo muestra cursos publicados y
 * activos. Los filtros de búsqueda nunca fallan: un valor inválido vuelve al de omisión.
 */
final class CatalogService
{
    public const PAGE_SIZE = 12;
    public const SORTS = ['recientes', 'titulo', 'precio'];
    public const MAX_QUERY_CHARS = 100;
    private const MAX_PAGE = 1000;

    public function __construct(private readonly CatalogRepository $catalog)
    {
    }

    /** @param array<string, mixed> $input {q, categoria, orden, pagina} (p. ej. $_GET) */
    public function search(array $input): CatalogPage
    {
        $filters = self::filters($input);
        [$courses, $total] = $this->catalog->search(
            $filters['q'] === '' ? null : self::escapeLike($filters['q']),
            $filters['categoria'],
            $filters['orden'],
            self::PAGE_SIZE,
            ($filters['pagina'] - 1) * self::PAGE_SIZE,
        );

        return new CatalogPage($courses, $total, $filters['pagina'], max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE)), $filters);
    }

    /** @return list<CatalogCourse> los publicados más recientes (inicio) */
    public function recent(int $limit = 8): array
    {
        return $this->catalog->search(null, null, 'recientes', $limit, 0)[0];
    }

    /** @throws BusinessRuleException COURSE_NOT_FOUND (no existe, es borrador o está dado de baja) */
    public function course(string $courseId): PublicCourse
    {
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $courseId) !== 1) {
            throw BusinessRuleException::of('COURSE_NOT_FOUND');
        }

        return $this->catalog->course($courseId);
    }

    /**
     * Filtros normalizados: texto recortado a 100 caracteres, categoría numérica o null,
     * orden conocido (recientes por omisión) y página de 1 a 1000.
     *
     * @param array<string, mixed> $input
     * @return array{q: string, categoria: ?string, orden: string, pagina: int}
     */
    public static function filters(array $input): array
    {
        $category = UserRules::text($input, 'categoria');
        $sort = UserRules::text($input, 'orden');
        $page = UserRules::text($input, 'pagina');

        return [
            'q' => mb_substr(UserRules::text($input, 'q'), 0, self::MAX_QUERY_CHARS),
            'categoria' => preg_match('/^[1-9][0-9]{0,19}$/D', $category) === 1 ? $category : null,
            'orden' => in_array($sort, self::SORTS, true) ? $sort : 'recientes',
            'pagina' => preg_match('/^[1-9][0-9]{0,3}$/D', $page) === 1 ? min((int) $page, self::MAX_PAGE) : 1,
        ];
    }

    /** Escapa los comodines de LIKE (% y _) y la diagonal invertida con la que se escapan. */
    public static function escapeLike(string $text): string
    {
        return addcslashes($text, '\\%_');
    }
}
