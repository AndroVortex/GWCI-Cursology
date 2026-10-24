<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;

/** Lo que un usuario ya tiene de un curso (sp_course_access). */
final class CourseAccess
{
    /** @param list<string> $levelIds niveles con acceso */
    public function __construct(
        public readonly bool $enrolled,
        public readonly bool $ownsCourse,
        public readonly array $levelIds,
    ) {
    }

    public static function none(): self
    {
        return new self(false, false, []);
    }

    /** @param list<list<array<string, mixed>>> $resultSets */
    public static function fromResultSets(array $resultSets): self
    {
        $r = new RowReader(self::class, $resultSets[0][0] ?? ['enrolled' => 0, 'owns_course' => 0]);

        return new self(
            $r->bool('enrolled'),
            $r->bool('owns_course'),
            array_map(static fn (array $row): string => (new RowReader(self::class, $row))->id('course_level_id'), $resultSets[1] ?? []),
        );
    }

    public function hasLevel(string $levelId): bool
    {
        return in_array($levelId, $this->levelIds, true);
    }

    public function hasAnyLevel(): bool
    {
        return $this->levelIds !== [];
    }
}
