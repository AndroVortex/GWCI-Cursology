<?php

declare(strict_types=1);

namespace App\Services\Exceptions;

use RuntimeException;

/** Cuenta dada de baja o suspendida (ban): no inicia sesión. */
final class AccountUnavailableException extends RuntimeException
{
    public const DELETED_MESSAGE = 'Esta cuenta está dada de baja. Si quieres volver a usarla, pide a un administrador que la reactive.';
    public const BANNED_MESSAGE = 'Esta cuenta está suspendida por un administrador. Si crees que es un error, contáctalo.';

    public function __construct(public readonly bool $banned = false)
    {
        parent::__construct($banned ? self::BANNED_MESSAGE : self::DELETED_MESSAGE);
    }
}
