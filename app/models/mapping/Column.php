<?php

declare(strict_types=1);

namespace App\Models\Mapping;

use BackedEnum;
use LogicException;

/**
 * Descripción de una columna tal como la declara el DDL.
 *
 * - $name: nombre exacto en MySQL.
 * - $property: propiedad y método de acceso en la entidad (camelCase).
 * - $sqlType: tipo del DDL sin charset/collation, p. ej. 'BIGINT UNSIGNED' o 'DECIMAL(12,2)'.
 * - $nullable: nulabilidad declarada en el DDL.
 * - $autoIncrement, $generated, $default y $onUpdate: valores administrados por MySQL.
 *   $default y $onUpdate conservan la expresión SQL literal del DDL.
 * - $enum: enum PHP con los valores permitidos por el CHECK de la columna.
 */
final class Column
{
    /**
     * @param class-string<BackedEnum>|null $enum
     */
    public function __construct(
        public readonly string $name,
        public readonly string $property,
        public readonly string $sqlType,
        public readonly bool $nullable = false,
        public readonly bool $autoIncrement = false,
        public readonly bool $generated = false,
        public readonly ?string $default = null,
        public readonly ?string $onUpdate = null,
        public readonly ?string $enum = null,
    ) {
        if ($generated && ($default !== null || $onUpdate !== null || $autoIncrement)) {
            throw new LogicException(sprintf('La columna generada %s no puede tener DEFAULT, ON UPDATE ni AUTO_INCREMENT.', $name));
        }
    }

    /** Valor que MySQL asigna o mantiene por su cuenta al crear o actualizar la fila. */
    public function isManagedByMysql(): bool
    {
        return $this->autoIncrement || $this->generated || $this->default !== null || $this->onUpdate !== null;
    }
}
