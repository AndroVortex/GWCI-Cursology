<?php

declare(strict_types=1);

namespace App\Models\Mapping;

use LogicException;

/**
 * Metadatos verificables de una tabla: columnas en orden del DDL, PK, UNIQUE y FK
 * como grupos, y columnas que no deben aparecer en volcados de depuración.
 */
final class TableMetadata
{
    /** @var array<string, Column> */
    private readonly array $columnsByName;

    /**
     * @param non-empty-list<Column> $columns
     * @param non-empty-list<string> $primaryKey
     * @param list<UniqueKey> $uniqueKeys
     * @param list<ForeignKey> $foreignKeys
     * @param list<string> $redactedColumns
     */
    public function __construct(
        public readonly string $table,
        public readonly array $columns,
        public readonly array $primaryKey,
        public readonly array $uniqueKeys = [],
        public readonly array $foreignKeys = [],
        public readonly array $redactedColumns = [],
    ) {
        $byName = [];
        $properties = [];
        foreach ($columns as $column) {
            if (isset($byName[$column->name]) || isset($properties[$column->property])) {
                throw new LogicException(sprintf('%s: columna o propiedad duplicada (%s).', $table, $column->name));
            }
            $byName[$column->name] = $column;
            $properties[$column->property] = true;
        }
        $this->columnsByName = $byName;

        $referenced = [$primaryKey, $redactedColumns];
        foreach ($uniqueKeys as $uniqueKey) {
            $referenced[] = $uniqueKey->columns;
        }
        foreach ($foreignKeys as $foreignKey) {
            $referenced[] = $foreignKey->columns;
        }
        foreach (array_merge(...$referenced) as $name) {
            if (!isset($byName[$name])) {
                throw new LogicException(sprintf('%s: la columna %s no existe en los metadatos.', $table, $name));
            }
        }
    }

    /** @return list<string> */
    public function columnNames(): array
    {
        return array_keys($this->columnsByName);
    }

    public function column(string $name): Column
    {
        return $this->columnsByName[$name]
            ?? throw new LogicException(sprintf('%s: la columna %s no existe.', $this->table, $name));
    }

    public function hasCompositePrimaryKey(): bool
    {
        return count($this->primaryKey) > 1;
    }

    /** @return list<string> */
    public function autoIncrementColumns(): array
    {
        return $this->namesWhere(static fn (Column $column): bool => $column->autoIncrement);
    }

    /** @return list<string> */
    public function generatedColumns(): array
    {
        return $this->namesWhere(static fn (Column $column): bool => $column->generated);
    }

    /**
     * DEFAULT declarados en el DDL (expresión SQL literal).
     *
     * @return array<string, string>
     */
    public function defaults(): array
    {
        $defaults = [];
        foreach ($this->columns as $column) {
            if ($column->default !== null) {
                $defaults[$column->name] = $column->default;
            }
        }

        return $defaults;
    }

    /** @return list<string> */
    public function onUpdateColumns(): array
    {
        return $this->namesWhere(static fn (Column $column): bool => $column->onUpdate !== null);
    }

    /** @return list<string> */
    public function mysqlManagedColumns(): array
    {
        return $this->namesWhere(static fn (Column $column): bool => $column->isManagedByMysql());
    }

    public function isRedacted(string $column): bool
    {
        return in_array($column, $this->redactedColumns, true);
    }

    /**
     * @param callable(Column): bool $predicate
     * @return list<string>
     */
    private function namesWhere(callable $predicate): array
    {
        $names = [];
        foreach ($this->columns as $column) {
            if ($predicate($column)) {
                $names[] = $column->name;
            }
        }

        return $names;
    }
}
