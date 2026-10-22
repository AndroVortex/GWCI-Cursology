<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Archivo ya subido a Cloudinary y verificado por el servidor (firma de la respuesta
 * y datos reales de la Admin API). Nada de esto viene de lo que dice el navegador,
 * salvo el nombre original, que solo se muestra.
 */
final class VerifiedMedia
{
    public function __construct(
        public readonly string $publicId,
        public readonly string $resourceType,
        public readonly string $type,
        public readonly string $format,
        public readonly int $bytes,
        public readonly ?int $durationSeconds,
        public readonly string $originalFilename,
        public readonly string $mimeType,
        public readonly string $secureUrl,
    ) {
    }

    /**
     * Lo que se guarda en la BD: la URL pública, o una referencia para lo protegido
     * ("cloudinary:authenticated/<resource_type>/<public_id>"), que solo el servidor
     * convierte en una URL temporal después de comprobar el acceso.
     */
    public function reference(): string
    {
        return $this->type === 'upload'
            ? $this->secureUrl
            : MediaService::REFERENCE_PREFIX . $this->type . '/' . $this->resourceType . '/' . $this->publicId;
    }
}
