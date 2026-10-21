<?php

declare(strict_types=1);

namespace App\Services\Exceptions;

use RuntimeException;

/** Datos de entrada inválidos. $fields usa los nombres de los campos del formulario. */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(public readonly array $fields)
    {
        parent::__construct('Revisa los campos marcados.');
    }
}
