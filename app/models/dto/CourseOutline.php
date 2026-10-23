<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Entities\Course;
use App\Models\Entities\CourseCategory;
use App\Models\Entities\CourseChapter;
use App\Models\Entities\CourseChapterContent;
use App\Models\Entities\CourseLevel;
use App\Models\Mapping\RowReader;
use App\Services\PricingMode;

/**
 * El curso completo de sp_course_get_for_owner (cinco result sets), agrupado para el
 * editor: niveles en orden, capítulos por nivel y contenido por capítulo.
 */
final class CourseOutline
{
    /**
     * @param list<string> $categoryIds
     * @param list<CourseLevel> $levels
     * @param array<string, list<CourseChapter>> $chaptersByLevel
     * @param array<string, list<CourseChapterContent>> $contentsByChapter
     */
    private function __construct(
        public readonly Course $course,
        public readonly array $categoryIds,
        public readonly array $levels,
        private readonly array $chaptersByLevel,
        private readonly array $contentsByChapter,
        public readonly int $studentCount,
        public readonly int $activeCategoryCount,
    ) {
    }

    /** @param list<list<array<string, mixed>>> $resultSets */
    public static function fromResultSets(array $resultSets): self
    {
        [$courseRows, $categoryRows, $levelRows, $chapterRows, $contentRows] = $resultSets + [[], [], [], [], []];

        $chaptersByLevel = [];
        foreach ($chapterRows as $row) {
            $chapter = CourseChapter::fromRow($row);
            $chaptersByLevel[$chapter->courseLevelId()][] = $chapter;
        }

        $contentsByChapter = [];
        foreach ($contentRows as $row) {
            $content = CourseChapterContent::fromRow($row);
            $contentsByChapter[$content->courseChapterId()][] = $content;
        }

        $counts = new RowReader(self::class, $courseRows[0] ?? []);

        return new self(
            Course::fromRow($courseRows[0] ?? []),
            array_map(static fn (array $row): string => CourseCategory::fromRow($row)->categoryId(), $categoryRows),
            array_map(static fn (array $row): CourseLevel => CourseLevel::fromRow($row), $levelRows),
            $chaptersByLevel,
            $contentsByChapter,
            $counts->intUnsigned('student_count'),
            $counts->intUnsigned('active_category_count'),
        );
    }

    public function pricingMode(): PricingMode
    {
        return PricingMode::fromFullCoursePrice($this->course->fullCoursePrice());
    }

    /** Con alumnos inscritos en un curso publicado, la forma de cobro ya no cambia. */
    public function pricingLocked(): bool
    {
        return $this->course->publishedAt() !== null && $this->studentCount > 0;
    }

    /** Suma exacta (sin float) de los precios de los niveles. */
    public function levelPriceTotal(): string
    {
        $cents = array_sum(array_map(static fn (CourseLevel $level): int => self::cents($level->cost()), $this->levels));

        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Aviso del editor: el paquete cuesta más que comprar todos los niveles por separado. */
    public function bundleExceedsLevels(): bool
    {
        $price = $this->course->fullCoursePrice();

        return $this->pricingMode() === PricingMode::Bundle
            && $this->levels !== []
            && self::cents((string) $price) > self::cents($this->levelPriceTotal());
    }

    /** DECIMAL(…,2) como centavos enteros. */
    private static function cents(string $amount): int
    {
        [$integer, $fraction] = explode('.', $amount) + [1 => '0'];

        return (int) $integer * 100 + (int) str_pad($fraction, 2, '0');
    }

    /** @return list<CourseChapter> */
    public function chapters(string $levelId): array
    {
        return $this->chaptersByLevel[$levelId] ?? [];
    }

    public function mainVideo(string $chapterId): ?CourseChapterContent
    {
        foreach ($this->contentsByChapter[$chapterId] ?? [] as $content) {
            if ($content->isMain()) {
                return $content;
            }
        }

        return null;
    }

    /** @return list<CourseChapterContent> */
    public function resources(string $chapterId): array
    {
        return array_values(array_filter(
            $this->contentsByChapter[$chapterId] ?? [],
            static fn (CourseChapterContent $content): bool => !$content->isMain(),
        ));
    }

    public function chapterCount(): int
    {
        return array_sum(array_map('count', $this->chaptersByLevel));
    }
}
