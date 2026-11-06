<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Un alumno en el detalle de ventas de un curso (sp_instructor_course_students). */
final class CourseStudentRow
{
    /** @param list<string> $paymentMethods vacío = todo fue gratis */
    private function __construct(
        public readonly string $userId,
        public readonly string $fullName,
        public readonly string $email,
        public readonly string $avatar,
        public readonly DateTimeImmutable $enrolledAt,
        public readonly ?DateTimeImmutable $completedAt,
        public readonly string $progress,
        public readonly string $paid,
        public readonly array $paymentMethods,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);
        $methods = $r->nullableText('payment_methods');

        return new self(
            $r->id('user_id'),
            $r->string('full_name', 350),
            $r->string('email', 254),
            $r->string('avatar', 2048),
            $r->dateTime('enrolled_at'),
            $r->nullableDateTime('completed_at'),
            $r->decimal('progress', 5, 1),
            $r->decimal('paid', 14, 2),
            $methods === null || $methods === '' ? [] : explode('|', $methods),
        );
    }
}
