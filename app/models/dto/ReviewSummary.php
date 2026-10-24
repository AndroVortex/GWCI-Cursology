<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;

/** Promedio y reparto de las calificaciones de un curso (sp_review_list, primer resultado). */
final class ReviewSummary
{
    /** @param array<int, int> $stars estrellas (1..5) => cuántas calificaciones */
    private function __construct(
        public readonly ?string $ratingAvg,
        public readonly int $ratingCount,
        public readonly array $stars,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);
        $stars = [];
        for ($star = 5; $star >= 1; $star--) {
            $stars[$star] = $r->intUnsigned('stars_' . $star);
        }

        return new self($r->nullableDecimal('rating_avg', 3, 1), $r->intUnsigned('rating_count'), $stars);
    }

    /** Porcentaje de calificaciones con esas estrellas, de 0 a 100. */
    public function percent(int $star): int
    {
        return $this->ratingCount === 0 ? 0 : intdiv(100 * ($this->stars[$star] ?? 0), $this->ratingCount);
    }
}
