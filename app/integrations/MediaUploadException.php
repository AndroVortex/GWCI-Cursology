<?php

declare(strict_types=1);

namespace App\Integrations;

use RuntimeException;

/**
 * Falla al subir o borrar multimedia. $notConfigured distingue "faltan credenciales"
 * de un error del servicio. El mensaje es para el log, no para el cliente.
 */
final class MediaUploadException extends RuntimeException
{
    private function __construct(string $message, public readonly bool $notConfigured)
    {
        parent::__construct($message);
    }

    public static function missingConfiguration(): self
    {
        return new self('Cloudinary no está configurado: faltan CLOUDINARY_* en .env.', true);
    }

    public static function failed(string $reason): self
    {
        return new self('Cloudinary: ' . $reason, false);
    }
}
