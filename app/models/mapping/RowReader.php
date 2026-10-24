<?php

declare(strict_types=1);

namespace App\Models\Mapping;

use BackedEnum;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * Lee y convierte las columnas de una fila asociativa de PDO hacia tipos PHP.
 *
 * Centraliza las conversiones de todas las entidades. Cada método exige que la
 * columna exista en la fila (array_key_exists), distingue NULL real de una clave
 * ausente y rechaza valores inválidos sin redondear, recortar ni inventar defaults.
 */
final class RowReader
{
    public const BIGINT_UNSIGNED_MAX = '18446744073709551615';
    public const INT_UNSIGNED_MAX = 4294967295;
    public const TINYINT_UNSIGNED_MAX = 255;
    public const TEXT_MAX_BYTES = 65535;

    private static ?DateTimeZone $utc = null;

    private readonly string $entity;

    /**
     * @param class-string $entityClass
     * @param array<string, mixed> $row
     */
    public function __construct(string $entityClass, private readonly array $row)
    {
        if (PHP_INT_SIZE < 8) {
            throw new LogicException('El mapeo requiere PHP de 64 bits para representar INT UNSIGNED como int.');
        }

        $this->entity = substr((string) strrchr('\\' . $entityClass, '\\'), 1);
    }

    // ---------------------------------------------------------------
    // BIGINT UNSIGNED -> string decimal
    // ---------------------------------------------------------------

    /** Identificadores (PK/FK): BIGINT UNSIGNED >= 1. */
    public function id(string $column): string
    {
        return $this->bigintUnsigned($column, $this->required($column), allowZero: false);
    }

    public function nullableId(string $column): ?string
    {
        $value = $this->value($column);

        return $value === null ? null : $this->bigintUnsigned($column, $value, allowZero: false);
    }

    /** BIGINT UNSIGNED que no es identificador (admite 0), p. ej. size_bytes. */
    public function nullableBigintUnsigned(string $column): ?string
    {
        $value = $this->value($column);

        return $value === null ? null : $this->bigintUnsigned($column, $value, allowZero: true);
    }

    // ---------------------------------------------------------------
    // INT / TINYINT -> int
    // ---------------------------------------------------------------

    public function intUnsigned(string $column): int
    {
        return $this->integer($column, $this->required($column), 0, self::INT_UNSIGNED_MAX, 'INT UNSIGNED');
    }

    public function nullableIntUnsigned(string $column): ?int
    {
        $value = $this->value($column);

        return $value === null ? null : $this->integer($column, $value, 0, self::INT_UNSIGNED_MAX, 'INT UNSIGNED');
    }

    public function tinyIntUnsigned(string $column): int
    {
        return $this->integer($column, $this->required($column), 0, self::TINYINT_UNSIGNED_MAX, 'TINYINT UNSIGNED');
    }

    /** TINYINT con signo; el rango se puede acotar al dominio real de la columna. */
    public function nullableTinyInt(string $column, int $min = -128, int $max = 127): ?int
    {
        $value = $this->value($column);

        return $value === null ? null : $this->integer($column, $value, $min, $max, 'TINYINT');
    }

    // ---------------------------------------------------------------
    // BOOLEAN -> bool
    // ---------------------------------------------------------------

    public function bool(string $column): bool
    {
        return $this->boolean($column, $this->required($column));
    }

    public function nullableBool(string $column): ?bool
    {
        $value = $this->value($column);

        return $value === null ? null : $this->boolean($column, $value);
    }

    // ---------------------------------------------------------------
    // DECIMAL(p,s) -> string exacta con la escala del DDL
    // ---------------------------------------------------------------

    public function decimal(string $column, int $precision, int $scale): string
    {
        return $this->decimalValue($column, $this->required($column), $precision, $scale);
    }

    public function nullableDecimal(string $column, int $precision, int $scale): ?string
    {
        $value = $this->value($column);

        return $value === null ? null : $this->decimalValue($column, $value, $precision, $scale);
    }

    // ---------------------------------------------------------------
    // VARCHAR / CHAR / TEXT / LONGTEXT -> string
    // ---------------------------------------------------------------

    /** VARCHAR(n) o CHAR(n): longitud máxima en caracteres. */
    public function string(string $column, int $maxChars): string
    {
        return $this->characters($column, $this->required($column), $maxChars);
    }

    public function nullableString(string $column, int $maxChars): ?string
    {
        $value = $this->value($column);

        return $value === null ? null : $this->characters($column, $value, $maxChars);
    }

    /** TEXT: hasta 65,535 bytes. */
    public function text(string $column): string
    {
        return $this->textValue($column, $this->required($column), self::TEXT_MAX_BYTES);
    }

    public function nullableText(string $column): ?string
    {
        $value = $this->value($column);

        return $value === null ? null : $this->textValue($column, $value, self::TEXT_MAX_BYTES);
    }

    /** LONGTEXT: sin límite práctico en PHP. */
    public function nullableLongText(string $column): ?string
    {
        $value = $this->value($column);

        return $value === null ? null : $this->textValue($column, $value, null);
    }

    /**
     * VARCHAR restringido por un CHECK IN (...): devuelve el caso del enum cuyo
     * valor coincide exactamente, respetando mayúsculas y minúsculas.
     *
     * @template T of BackedEnum
     * @param class-string<T> $enumClass
     * @return T
     */
    public function enum(string $column, string $enumClass): BackedEnum
    {
        $value = $this->required($column);
        $case = is_string($value) ? $enumClass::tryFrom($value) : null;

        if ($case === null) {
            throw $this->invalid($column, 'valor no permitido por el CHECK de la columna', $value);
        }

        return $case;
    }

    /**
     * Igual que enum(), pero admite NULL (columnas calculadas de un DTO de resultado).
     *
     * @template T of BackedEnum
     * @param class-string<T> $enumClass
     * @return T|null
     */
    public function nullableEnum(string $column, string $enumClass): ?BackedEnum
    {
        $value = $this->value($column);
        if ($value === null) {
            return null;
        }

        $case = is_string($value) ? $enumClass::tryFrom($value) : null;
        if ($case === null) {
            throw $this->invalid($column, 'valor no permitido por el CHECK de la columna', $value);
        }

        return $case;
    }

    // ---------------------------------------------------------------
    // DATE -> string Y-m-d, DATETIME(6) -> DateTimeImmutable UTC
    // ---------------------------------------------------------------

    public function date(string $column): string
    {
        return $this->dateValue($column, $this->required($column));
    }

    public function dateTime(string $column): DateTimeImmutable
    {
        return $this->dateTimeValue($column, $this->required($column));
    }

    public function nullableDateTime(string $column): ?DateTimeImmutable
    {
        $value = $this->value($column);

        return $value === null ? null : $this->dateTimeValue($column, $value);
    }

    // ---------------------------------------------------------------
    // Internos
    // ---------------------------------------------------------------

    private function value(string $column): mixed
    {
        if (!array_key_exists($column, $this->row)) {
            throw HydrationException::missingColumn($this->entity, $column);
        }

        return $this->row[$column];
    }

    private function required(string $column): mixed
    {
        $value = $this->value($column);
        if ($value === null) {
            throw HydrationException::unexpectedNull($this->entity, $column);
        }

        return $value;
    }

    private function bigintUnsigned(string $column, mixed $value, bool $allowZero): string
    {
        if (is_int($value)) {
            if ($value < 0 || (!$allowZero && $value === 0)) {
                throw $this->invalid($column, $this->bigintRangeMessage($allowZero), $value);
            }

            return (string) $value;
        }

        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw $this->invalid($column, 'se esperaba BIGINT UNSIGNED como int o cadena decimal sin signo', $value);
        }

        $length = strlen($value);
        if ($length > 20 || ($length === 20 && strcmp($value, self::BIGINT_UNSIGNED_MAX) > 0)) {
            throw $this->invalid($column, 'excede el máximo de BIGINT UNSIGNED', $value);
        }

        if (!$allowZero && $value === '0') {
            throw $this->invalid($column, $this->bigintRangeMessage($allowZero), $value);
        }

        return $value;
    }

    private function bigintRangeMessage(bool $allowZero): string
    {
        return $allowZero
            ? 'BIGINT UNSIGNED no admite negativos'
            : 'un identificador BIGINT UNSIGNED debe ser mayor o igual a 1';
    }

    private function integer(string $column, mixed $value, int $min, int $max, string $sqlType): int
    {
        if (is_string($value) && preg_match('/^(0|-?[1-9][0-9]{0,9})$/D', $value) === 1) {
            $value = (int) $value;
        }

        if (!is_int($value)) {
            throw $this->invalid($column, sprintf('se esperaba %s como int o cadena entera', $sqlType), $value);
        }

        if ($value < $min || $value > $max) {
            throw $this->invalid($column, sprintf('%s fuera del rango [%d, %d]', $sqlType, $min, $max), $value);
        }

        return $value;
    }

    private function boolean(string $column, mixed $value): bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1' => true,
            $value === false, $value === 0, $value === '0' => false,
            default => throw $this->invalid($column, 'se esperaba BOOLEAN (true, false, 0, 1, "0" o "1")', $value),
        };
    }

    private function decimalValue(string $column, mixed $value, int $precision, int $scale): string
    {
        $type = sprintf('DECIMAL(%d,%d)', $precision, $scale);

        if (is_int($value)) {
            $value = (string) $value;
        } elseif (!is_string($value)) {
            throw $this->invalid($column, sprintf('se esperaba %s como cadena exacta o int; los float se rechazan', $type), $value);
        }

        if (preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/D', $value, $parts) !== 1) {
            throw $this->invalid($column, sprintf('formato inválido para %s', $type), $value);
        }

        $negative = $parts[1] === '-';
        $integer = ltrim($parts[2], '0');
        $fraction = $parts[3] ?? '';

        if (strlen($fraction) > $scale) {
            throw $this->invalid($column, sprintf('excede la escala de %s', $type), $value);
        }

        if (strlen($integer) > $precision - $scale) {
            throw $this->invalid($column, sprintf('excede el rango de %s', $type), $value);
        }

        $integer = $integer === '' ? '0' : $integer;
        $fraction = str_pad($fraction, $scale, '0');
        $normalized = $scale > 0 ? $integer . '.' . $fraction : $integer;
        $isZero = trim($integer . $fraction, '0') === '';

        return $negative && !$isZero ? '-' . $normalized : $normalized;
    }

    private function characters(string $column, mixed $value, int $maxChars): string
    {
        $value = $this->utf8String($column, $value);

        if (mb_strlen($value, 'UTF-8') > $maxChars) {
            throw $this->invalid($column, sprintf('excede %d caracteres', $maxChars), $value);
        }

        return $value;
    }

    private function textValue(string $column, mixed $value, ?int $maxBytes): string
    {
        $value = $this->utf8String($column, $value);

        if ($maxBytes !== null && strlen($value) > $maxBytes) {
            throw $this->invalid($column, sprintf('excede %d bytes', $maxBytes), $value);
        }

        return $value;
    }

    private function utf8String(string $column, mixed $value): string
    {
        if (!is_string($value)) {
            throw $this->invalid($column, 'se esperaba una cadena', $value);
        }

        if (!mb_check_encoding($value, 'UTF-8')) {
            throw $this->invalid($column, 'la cadena no es UTF-8 válido', $value);
        }

        return $value;
    }

    private function dateValue(string $column, mixed $value): string
    {
        if (
            !is_string($value)
            || preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            throw $this->invalid($column, 'se esperaba una DATE existente con formato Y-m-d', $value);
        }

        return $value;
    }

    private function dateTimeValue(string $column, mixed $value): DateTimeImmutable
    {
        $pattern = '/^([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.([0-9]{1,6}))?$/D';

        if (!is_string($value) || preg_match($pattern, $value, $parts) !== 1) {
            throw $this->invalid($column, 'se esperaba DATETIME(6) con formato Y-m-d H:i:s[.uuuuuu]', $value);
        }

        [, $year, $month, $day, $hour, $minute, $second] = $parts;
        $micro = str_pad($parts[7] ?? '', 6, '0');

        if (
            !checkdate((int) $month, (int) $day, (int) $year)
            || (int) $hour > 23 || (int) $minute > 59 || (int) $second > 59
        ) {
            throw $this->invalid($column, 'fecha u hora inexistente', $value);
        }

        $normalized = sprintf('%s-%s-%s %s:%s:%s.%s', $year, $month, $day, $hour, $minute, $second, $micro);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $normalized, self::utc());

        if ($date === false || $date->format('Y-m-d H:i:s.u') !== $normalized) {
            throw $this->invalid($column, 'fecha u hora inexistente', $value);
        }

        return $date;
    }

    private static function utc(): DateTimeZone
    {
        return self::$utc ??= new DateTimeZone('UTC');
    }

    private function invalid(string $column, string $reason, mixed $value): HydrationException
    {
        return HydrationException::invalidValue($this->entity, $column, $reason, get_debug_type($value));
    }
}
