<?php

declare(strict_types=1);

namespace App\Models\Dto;

/** Una página de la vista "Cuentas" del admin, con sus filtros. */
final class AdminUserPage
{
    /**
     * @param list<AdminUserRow> $users
     * @param array{q: string, rol: string, estado: string} $filters
     */
    public function __construct(
        public readonly array $users,
        public readonly int $total,
        public readonly int $page,
        public readonly int $pages,
        public readonly array $filters,
    ) {
    }

    public function hasMore(): bool
    {
        return $this->page < $this->pages;
    }
}
