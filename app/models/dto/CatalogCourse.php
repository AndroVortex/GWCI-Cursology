<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;
use App\Services\PricingMode;
use DateTimeImmutable;

/** Tarjeta del catálogo público (sp_catalog_search). Solo datos públicos. */
final class CatalogCourse
{
    /** @param list<string> $categoryNames */
    private function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $coverImage,
        public readonly ?string $fullCoursePrice,
        public readonly string $currency,
        public readonly DateTimeImmutable $publishedAt,
        public readonly string $instructorName,
        public readonly array $categoryNames,
        public readonly int $levelCount,
        public readonly int $chapterCount,
        public readonly string $levelPriceTotal,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);
        $categories = $r->nullableText('category_names');

        return new self(
            $r->id('id'),
            $r->string('title', 200),
            $r->string('cover_image', 2048),
            $r->nullableDecimal('full_course_price', 12, 2),
            $r->string('currency', 3),
            $r->dateTime('published_at'),
            $r->string('instructor_name', 350),
            $categories === null || $categories === '' ? [] : explode('|', $categories),
            $r->intUnsigned('level_count'),
            $r->intUnsigned('chapter_count'),
            $r->decimal('level_price_total', 14, 2),
        );
    }

    public function pricingMode(): PricingMode
    {
        return PricingMode::fromFullCoursePrice($this->fullCoursePrice);
    }
}
