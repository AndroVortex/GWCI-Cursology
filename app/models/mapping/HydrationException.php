<?php

declare(strict_types=1);

namespace App\Models\Mapping;

use UnexpectedValueException;

/**
 * Error al construir una entidad desde una fila. El mensaje identifica la entidad
 * y la columna, pero nunca incluye el valor recibido ni el resto de la fila.
 */
final class HydrationException extends UnexpectedValueException
{
    private function __construct(
        string $message,
        public readonly string $entity,
        public readonly string $column,
    ) {
        parent::__construct($message);
    }

    public static function missingColumn(string $entity, string $column): self
    {
        return new self(sprintf('%s.%s: la columna no viene en la fila.', $entity, $column), $entity, $column);
    }

    public static function unexpectedNull(string $entity, string $column): self
    {
        return new self(sprintf('%s.%s: NULL en una columna NOT NULL.', $entity, $column), $entity, $column);
    }

    public static function invalidValue(string $entity, string $column, string $reason, string $receivedType): self
    {
        return new self(
            sprintf('%s.%s: %s (tipo recibido: %s).', $entity, $column, $reason, $receivedType),
            $entity,
            $column,
        );
    }
}
