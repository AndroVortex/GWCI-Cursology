<?php

declare(strict_types=1);

namespace App\Services\Exceptions;

use RuntimeException;
use Throwable;

/** Ya existe una cuenta con ese email. */
final class EmailTakenException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Ya existe una cuenta con ese email.', 0, $previous);
    }
}
