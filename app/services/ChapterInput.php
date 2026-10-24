<?php

declare(strict_types=1);

namespace App\Services;

/** Datos validados de un capítulo (CourseValidator::validateChapter). */
final class ChapterInput
{
    /**
     * @param ?string $minimumPassingGrade DECIMAL(5,2) normalizado; solo en exámenes
     *                                     (al editar, null = conservar el actual)
     */
    public function __construct(
        public readonly string $title,
        public readonly bool $isTest,
        public readonly ?string $minimumPassingGrade,
    ) {
    }
}
