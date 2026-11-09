<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Un alumno en el reporte del admin (sp_admin_report_students). */
final class StudentReportRow
{
    private function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $fullName,
        public readonly string $avatar,
        public readonly DateTimeImmutable $createdAt,
        public readonly int $enrolledCourses,
        public readonly int $completedCourses,
        public readonly string $completedPercent,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);

        return new self(
            $r->id('id'),
            $r->string('email', 254),
            $r->string('full_name', 350),
            $r->string('avatar', 2048),
            $r->dateTime('created_at'),
            $r->intUnsigned('enrolled_courses'),
            $r->intUnsigned('completed_courses'),
            $r->decimal('completed_percent', 5, 1),
        );
    }
}
