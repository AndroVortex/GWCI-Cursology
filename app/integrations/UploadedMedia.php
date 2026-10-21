<?php

declare(strict_types=1);

namespace App\Integrations;

/** Archivo ya guardado en Cloudinary. */
final class UploadedMedia
{
    public function __construct(
        public readonly string $secureUrl,
        public readonly string $publicId,
    ) {
    }
}
