<?php

declare(strict_types=1);

namespace App\Models\Mapping;

use BackedEnum;
use DateTimeImmutable;
use LogicException;

/**
 * Base de las entidades: una instancia representa una fila existente de una tabla.
 *
 * Las entidades solo se construyen con fromRow() a partir de una fila completa,
 * no abren conexiones ni ejecutan consultas, y no cargan objetos relacionados.
 * Sus propiedades no son públicas, así que json_encode() produce "{}"; para
 * responder al cliente se usará un DTO por caso de uso.
 */
abstract class Entity
{
    abstract public static function metadata(): TableMetadata;

    /**
     * Hidrata la entidad desde una fila asociativa de PDO que trae TODAS sus columnas.
     *
     * @param array<string, mixed> $row
     * @throws HydrationException si falta una columna o un valor no corresponde a su tipo.
     */
    abstract public static function fromRow(array $row): static;

    /**
     * Vista para var_dump()/print_r(): columnas con su nombre de MySQL, fechas en UTC
     * con microsegundos y las columnas sensibles ocultas.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $metadata = static::metadata();
        $info = [];

        foreach ($metadata->columns as $column) {
            $value = $this->{$column->property};
            $info[$column->name] = match (true) {
                $metadata->isRedacted($column->name) => '[redacted]',
                $value instanceof DateTimeImmutable => $value->format('Y-m-d H:i:s.u') . ' UTC',
                $value instanceof BackedEnum => $value->value,
                default => $value,
            };
        }

        return $info;
    }

    /**
     * Evita que una entidad (con hash de contraseña o claves de examen) termine en la
     * sesión, en caché o en un archivo mediante serialize().
     */
    public function __serialize(): array
    {
        throw new LogicException(sprintf('%s no se serializa; guarda su id o usa un DTO.', static::class));
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException(sprintf('%s no se reconstruye desde serialize().', static::class));
    }
}
