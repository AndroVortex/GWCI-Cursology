<?php

declare(strict_types=1);

namespace App\Database;

use PDOException;
use RuntimeException;

/**
 * Error al ejecutar un SP. Si el SP hizo SIGNAL SQLSTATE '45000' con un código de
 * negocio (p. ej. EMAIL_TAKEN), queda en $businessCode; si no, es un error técnico.
 * El mensaje nunca incluye los parámetros enviados.
 */
final class ProcedureException extends RuntimeException
{
    /** Código de error de MySQL para SIGNAL sin manejar. */
    private const ER_SIGNAL_EXCEPTION = 1644;

    private function __construct(
        string $message,
        public readonly string $procedure,
        public readonly ?string $businessCode,
        ?PDOException $previous,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromPdo(string $procedure, PDOException $exception): self
    {
        $info = $exception->errorInfo ?? [];
        $driverCode = $info[1] ?? null;
        $text = $info[2] ?? null;

        if ($driverCode === self::ER_SIGNAL_EXCEPTION && is_string($text) && preg_match('/^[A-Z][A-Z0-9_]*$/D', $text) === 1) {
            return new self(sprintf('%s: %s', $procedure, $text), $procedure, $text, $exception);
        }

        return new self(sprintf('%s: error de base de datos', $procedure), $procedure, null, $exception);
    }

    public function is(string $businessCode): bool
    {
        return $this->businessCode === $businessCode;
    }
}
