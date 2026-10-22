<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\StoredProcedures;
use App\Models\Entities\Category;

/** Catálogo de categorías, exclusivamente mediante SP. */
final class CategoryRepository
{
    use CallsProcedures;

    public function __construct(private readonly StoredProcedures $procedures)
    {
    }

    /** @return list<Category> */
    public function list(bool $includeInactive): array
    {
        return array_map(
            static fn (array $row): Category => Category::fromRow($row),
            $this->procedures->rows('sp_category_list', [$includeInactive]),
        );
    }

    public function create(string $adminId, string $name, ?string $description): Category
    {
        return Category::fromRow($this->requiredRow('sp_category_create', [$adminId, $name, $description]));
    }

    public function update(string $adminId, string $categoryId, string $name, ?string $description): Category
    {
        return Category::fromRow($this->requiredRow('sp_category_update', [$adminId, $categoryId, $name, $description]));
    }

    public function setActive(string $adminId, string $categoryId, bool $active): Category
    {
        return Category::fromRow($this->requiredRow('sp_category_set_active', [$adminId, $categoryId, $active]));
    }
}
