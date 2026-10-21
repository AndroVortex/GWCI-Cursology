<?php

declare(strict_types=1);

namespace App\Services\Exceptions;

use RuntimeException;

/** Cuenta dada de baja o baneada. */
final class AccountUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Tu cuenta no está disponible. Contacta a un administrador.');
    }
}
