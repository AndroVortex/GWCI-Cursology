<?php

declare(strict_types=1);

/**
 * Lee archivos .sql pensados para el cliente mysql (esquema, SP e install.sql) para que los
 * scripts de la línea de comandos los ejecuten con PDO, que no entiende DELIMITER ni SOURCE.
 */
final class SqlScript
{
    /**
     * Separa el script en sentencias, sin el delimitador ni los comentarios. Respeta
     * DELIMITER (p. ej. $$ en los SP), las comillas simples y dobles, y los backticks.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
        $statements = [];
        $delimiter = ';';
        $buffer = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            if (trim($buffer) === '' && preg_match('/\G\s*DELIMITER[ \t]+(\S+)[^\n]*(?:\n|$)/Ai', $sql, $match, 0, $i) === 1) {
                $delimiter = $match[1];
                $buffer = '';
                $i += strlen($match[0]);
                continue;
            }

            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if (($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new RuntimeException('Comentario /* sin cerrar.');
                }
                $buffer .= ' ';
                $i = $end + 2;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $end = self::quoteEnd($sql, $i);
                $buffer .= substr($sql, $i, $end - $i + 1);
                $i = $end + 1;
                continue;
            }

            if (substr_compare($sql, $delimiter, $i, strlen($delimiter)) === 0) {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
                $i += strlen($delimiter);
                continue;
            }

            $buffer .= $char;
            $i++;
        }

        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }

        return $statements;
    }

    /**
     * Rutas de las líneas SOURCE (p. ej. las de install.sql), en orden.
     *
     * @return list<string>
     */
    public static function sourcedFiles(string $sql): array
    {
        preg_match_all('/^[ \t]*SOURCE[ \t]+([^;\r\n]+?)[ \t]*;?[ \t]*\r?$/mi', $sql, $matches);

        return $matches[1];
    }

    /** Posición de la comilla que cierra la que abre en $start. */
    private static function quoteEnd(string $sql, int $start): int
    {
        $quote = $sql[$start];
        $length = strlen($sql);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($quote !== '`' && $sql[$i] === '\\') {
                $i++;
            } elseif ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') !== $quote) {
                    return $i;
                }
                $i++;
            }
        }

        throw new RuntimeException(sprintf('Texto entre %s sin cerrar.', $quote));
    }
}
