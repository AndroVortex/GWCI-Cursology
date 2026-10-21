<?php

declare(strict_types=1);

namespace App\Services\Exceptions;

use RuntimeException;

/** Email inexistente o contraseña incorrecta: el mensaje no distingue entre ambos. */
final class InvalidCredentialsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Email o contraseña incorrectos.');
    }
}
