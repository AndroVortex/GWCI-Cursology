<?php

declare(strict_types=1);

namespace App\Database;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use UnexpectedValueException;

/**
 * Único punto por el que PHP ejecuta SQL: CALL de un SP con parámetros enlazados.
 *
 * El nombre del SP lo fija el código del servidor (nunca el cliente) y se valida;
 * los valores siempre van como parámetros de un prepared statement.
 */
final class StoredProcedures
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Ejecuta el SP y devuelve TODOS sus result sets (cada uno, una lista de filas).
     *
     * @param list<mixed> $params
     * @return list<list<array<string, mixed>>>
     * @throws ProcedureException
     */
    public function call(string $procedure, array $params = []): array
    {
        if (preg_match('/^sp_[a-z0-9_]+$/D', $procedure) !== 1) {
            throw new InvalidArgumentException(sprintf('Nombre de procedimiento inválido: %s', $procedure));
        }

        $bindings = array_map(self::parameter(...), array_values($params));
        $placeholders = implode(', ', array_fill(0, count($bindings), '?'));

        try {
            $statement = $this->connection->pdo()->prepare(sprintf('CALL %s(%s)', $procedure, $placeholders));
            foreach ($bindings as $index => [$value, $type]) {
                $statement->bindValue($index + 1, $value, $type);
            }
            $statement->execute();

            // MySQL deja result sets pendientes tras un CALL (al menos uno de estado);
            // hay que recorrerlos todos o la siguiente llamada falla con el error 2014.
            $resultSets = [];
            do {
                if ($statement->columnCount() > 0) {
                    $resultSets[] = $statement->fetchAll(PDO::FETCH_ASSOC);
                }
            } while ($statement->nextRowset());
            $statement->closeCursor();
        } catch (PDOException $exception) {
            throw ProcedureException::fromPdo($procedure, $exception);
        }

        return $resultSets;
    }

    /**
     * Filas del primer result set.
     *
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function rows(string $procedure, array $params = []): array
    {
        return $this->call($procedure, $params)[0] ?? [];
    }

    /**
     * Única fila del primer result set, o null si no hay ninguna.
     *
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    public function row(string $procedure, array $params = []): ?array
    {
        $rows = $this->rows($procedure, $params);
        if (count($rows) > 1) {
            throw new UnexpectedValueException(sprintf('%s devolvió %d filas y se esperaba como máximo una.', $procedure, count($rows)));
        }

        return $rows[0] ?? null;
    }

    /**
     * Convierte un valor PHP en [valor, tipo PDO]. IDs y DECIMAL viajan como cadena;
     * los float se rechazan para no perder precisión.
     *
     * @return array{0: mixed, 1: int}
     */
    public static function parameter(mixed $value): array
    {
        return match (true) {
            $value === null => [null, PDO::PARAM_NULL],
            is_bool($value) => [$value ? 1 : 0, PDO::PARAM_INT],
            is_int($value) => [$value, PDO::PARAM_INT],
            is_string($value) => [$value, PDO::PARAM_STR],
            $value instanceof BackedEnum => [(string) $value->value, PDO::PARAM_STR],
            $value instanceof DateTimeInterface => [
                DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                PDO::PARAM_STR,
            ],
            default => throw new InvalidArgumentException(sprintf('Tipo de parámetro no soportado: %s', get_debug_type($value))),
        };
    }
}
