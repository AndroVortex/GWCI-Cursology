<?php

declare(strict_types=1);

namespace App\Models\Mapping;

use LogicException;

/**
 * Restricción FOREIGN KEY del DDL. Una FK compuesta es UNA restricción: sus pares
 * de columnas se validan juntos y en el orden declarado.
 */
final class ForeignKey
{
    /**
     * @param non-empty-list<string> $columns
     * @param non-empty-list<string> $referencedColumns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly string $referencedTable,
        public readonly array $referencedColumns,
    ) {
        if ($columns === [] || !array_is_list($columns) || !array_is_list($referencedColumns)
            || count($columns) !== count($referencedColumns)) {
            throw new LogicException(sprintf('La FK %s necesita listas de columnas del mismo tamaño.', $name));
        }
    }

    public function isComposite(): bool
    {
        return count($this->columns) > 1;
    }

    /**
     * Pares columna local => columna referenciada, en el orden del DDL.
     *
     * @return array<string, string>
     */
    public function columnPairs(): array
    {
        return array_combine($this->columns, $this->referencedColumns);
    }
}
