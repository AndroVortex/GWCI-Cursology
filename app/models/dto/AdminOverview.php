<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;

/** Cifras del panel del admin (sp_admin_overview). */
final class AdminOverview
{
    private function __construct(
        public readonly int $activeStudents,
        public readonly int $activeInstructors,
        public readonly int $publishedCourses,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);

        return new self($r->intUnsigned('active_students'), $r->intUnsigned('active_instructors'), $r->intUnsigned('published_courses'));
    }
}
