<?php

declare(strict_types=1);

namespace App\Models\Dto;

/**
 * Una página de la moderación: la cola de reportes (ModerationReport, con reportTotal) o todo el
 * contenido (ModerationItem, con sus filtros).
 *
 * @template T of ModerationReport|ModerationItem
 */
final class ModerationPage
{
    /**
     * @param list<T> $items
     * @param array<string, string> $filters
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $pages,
        public readonly array $filters = [],
        public readonly ?int $reportTotal = null,
    ) {
    }

    public static function pagesFor(int $total, int $pageSize): int
    {
        return max(1, intdiv($total + $pageSize - 1, $pageSize));
    }

    public function hasMore(): bool
    {
        return $this->page < $this->pages;
    }
}
