<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Un intento en el historial del examen (sp_attempt_list). */
final class AttemptSummary
{
    private function __construct(
        public readonly string $id,
        public readonly int $number,
        public readonly DateTimeImmutable $startedAt,
        public readonly ?DateTimeImmutable $submittedAt,
        public readonly string $minimumGrade,
        public readonly ?string $grade,
        public readonly ?bool $passed,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);

        return new self(
            $r->id('id'),
            $r->intUnsigned('attempt_number'),
            $r->dateTime('started_at'),
            $r->nullableDateTime('submitted_at'),
            $r->decimal('minimum_grade_snapshot', 5, 2),
            $r->nullableDecimal('grade', 5, 2),
            $r->nullableBool('passed'),
        );
    }
}
