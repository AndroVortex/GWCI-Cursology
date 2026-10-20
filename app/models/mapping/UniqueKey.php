<?php

declare(strict_types=1);

namespace App\Models\Mapping;

use LogicException;

/**
 * Índice UNIQUE del DDL. Las columnas forman un solo grupo y conservan su orden.
 */
final class UniqueKey
{
    /**
     * @param non-empty-list<string> $columns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
    ) {
        if ($columns === [] || !array_is_list($columns)) {
            throw new LogicException(sprintf('El UNIQUE %s necesita una lista ordenada de columnas.', $name));
        }
    }

    public function isComposite(): bool
    {
        return count($this->columns) > 1;
    }
}
