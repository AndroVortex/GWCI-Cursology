<?php

declare(strict_types=1);

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * Cuenta bloqueada por intentos fallidos. $justLocked indica que este intento
 * provocó el bloqueo. Solo un administrador puede reactivarla.
 */
final class AccountLockedException extends RuntimeException
{
    public function __construct(public readonly bool $justLocked = false)
    {
        parent::__construct($justLocked
            ? 'Tu cuenta se bloqueó por 3 intentos fallidos consecutivos. Contacta a un administrador.'
            : 'Tu cuenta está bloqueada por intentos fallidos. Contacta a un administrador.');
    }
}
