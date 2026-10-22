<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Enums\ContentType;

/**
 * Contenido de un capítulo ya validado. Si $purpose no es null, $upload es la subida
 * a Cloudinary sin verificar; los enlaces usan $url y el texto, $text.
 */
final class ContentInput
{
    public function __construct(
        public readonly ContentType $type,
        public readonly bool $isMain,
        public readonly string $title,
        public readonly ?string $purpose,
        public readonly mixed $upload,
        public readonly ?string $url,
        public readonly ?string $text,
    ) {
    }
}
