<?php

declare(strict_types=1);

namespace App\Services;

/** Datos de un nivel ya validados. $video es la subida sin verificar (o null para conservar la actual). */
final class LevelInput
{
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $cost,
        public readonly mixed $video,
    ) {
    }
}
