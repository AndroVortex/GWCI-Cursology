<?php

declare(strict_types=1);

namespace App\Services;

/** Datos del curso ya validados. $cover y $video son subidas sin verificar (las verifica MediaService). */
final class CourseInput
{
    /**
     * @param list<string> $categoryIds
     */
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly ?string $certifierName,
        public readonly ?string $fullCoursePrice,
        public readonly array $categoryIds,
        public readonly mixed $cover,
        public readonly mixed $video,
    ) {
    }
}
