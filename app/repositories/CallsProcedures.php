<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\ProcedureException;
use App\Database\StoredProcedures;
use App\Services\Exceptions\BusinessRuleException;
use UnexpectedValueException;

/**
 * Llamadas a SP desde un repositorio: los errores de negocio (SIGNAL) se traducen a
 * BusinessRuleException; los técnicos siguen como ProcedureException.
 *
 * @property-read StoredProcedures $procedures
 */
trait CallsProcedures
{
    /**
     * Única fila que el SP debe devolver.
     *
     * @param list<mixed> $params
     * @return array<string, mixed>
     */
    private function requiredRow(string $procedure, array $params): array
    {
        try {
            $row = $this->procedures->row($procedure, $params);
        } catch (ProcedureException $exception) {
            throw BusinessRuleException::fromProcedure($exception);
        }

        return $row ?? throw new UnexpectedValueException(sprintf('%s no devolvió resultados.', $procedure));
    }

    /**
     * Todos los result sets del SP.
     *
     * @param list<mixed> $params
     * @return list<list<array<string, mixed>>>
     */
    private function resultSets(string $procedure, array $params): array
    {
        try {
            return $this->procedures->call($procedure, $params);
        } catch (ProcedureException $exception) {
            throw BusinessRuleException::fromProcedure($exception);
        }
    }

    /**
     * Referencias de multimedia ("media_refs", arreglo JSON) que un SP de borrado devuelve
     * para que PHP elimine los archivos después del commit.
     *
     * @param list<mixed> $params
     * @return list<string>
     */
    private function deletedMediaRefs(string $procedure, array $params): array
    {
        return self::mediaRefs($this->requiredRow($procedure, $params));
    }

    /**
     * @param array<string, mixed> $row fila con la columna media_refs
     * @return list<string>
     */
    private static function mediaRefs(array $row): array
    {
        $refs = json_decode(is_string($row['media_refs'] ?? null) ? $row['media_refs'] : '[]', true);

        return is_array($refs) ? array_values(array_filter($refs, 'is_string')) : [];
    }
}
