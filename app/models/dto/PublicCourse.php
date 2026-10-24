<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;
use App\Services\PricingMode;
use DateTimeImmutable;

/**
 * Página pública de un curso (sp_catalog_course): datos del curso, su instructor, categorías
 * y temario. Nunca trae las URIs del contenido de los capítulos.
 */
final class PublicCourse
{
    /**
     * @param list<array{id: string, name: string}> $categories
     * @param list<array{id: string, title: string, description: string, video: string, sortOrder: int, cost: string,
     *     durationSeconds: int, chapters: list<array{id: string, title: string, sortOrder: int, isExam: bool,
     *     durationSeconds: ?int, questionCount: int}>}> $levels
     */
    private function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $description,
        public readonly string $coverImage,
        public readonly string $promoVideo,
        public readonly ?string $fullCoursePrice,
        public readonly string $currency,
        public readonly DateTimeImmutable $publishedAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly string $instructorId,
        public readonly string $instructorName,
        public readonly string $instructorAvatar,
        public readonly ?string $instructorDescription,
        public readonly array $categories,
        public readonly array $levels,
    ) {
    }

    /** @param list<list<array<string, mixed>>> $resultSets */
    public static function fromResultSets(array $resultSets): self
    {
        [$courseRows, $categoryRows, $levelRows, $chapterRows] = $resultSets + [[], [], [], []];
        $r = new RowReader(self::class, $courseRows[0] ?? []);

        $chaptersByLevel = [];
        foreach ($chapterRows as $row) {
            $c = new RowReader(self::class, $row);
            $chaptersByLevel[$c->id('course_level_id')][] = [
                'id' => $c->id('id'),
                'title' => $c->string('title', 200),
                'sortOrder' => $c->intUnsigned('sort_order'),
                'isExam' => $c->bool('is_test'),
                'durationSeconds' => $c->nullableIntUnsigned('duration_seconds'),
                'questionCount' => $c->intUnsigned('question_count'),
            ];
        }

        $levels = [];
        foreach ($levelRows as $row) {
            $l = new RowReader(self::class, $row);
            $chapters = $chaptersByLevel[$l->id('id')] ?? [];
            $levels[] = [
                'id' => $l->id('id'),
                'title' => $l->string('title', 200),
                'description' => $l->text('description'),
                'video' => $l->string('onboarding_video', 2048),
                'sortOrder' => $l->intUnsigned('sort_order'),
                'cost' => $l->decimal('cost', 12, 2),
                'durationSeconds' => array_sum(array_map(static fn (array $chapter): int => $chapter['durationSeconds'] ?? 0, $chapters)),
                'chapters' => $chapters,
            ];
        }

        return new self(
            $r->id('id'),
            $r->string('title', 200),
            $r->text('description'),
            $r->string('cover_image', 2048),
            $r->string('onboarding_video', 2048),
            $r->nullableDecimal('full_course_price', 12, 2),
            $r->string('currency', 3),
            $r->dateTime('published_at'),
            $r->dateTime('updated_at'),
            $r->id('instructor_id'),
            $r->string('instructor_name', 350),
            $r->string('instructor_avatar', 2048),
            $r->nullableText('instructor_description'),
            array_map(static function (array $row): array {
                $c = new RowReader(self::class, $row);

                return ['id' => $c->id('id'), 'name' => $c->string('name', 100)];
            }, $categoryRows),
            $levels,
        );
    }

    public function pricingMode(): PricingMode
    {
        return PricingMode::fromFullCoursePrice($this->fullCoursePrice);
    }

    public function chapterCount(): int
    {
        return array_sum(array_map(static fn (array $level): int => count($level['chapters']), $this->levels));
    }

    public function examCount(): int
    {
        return array_sum(array_map(
            static fn (array $level): int => count(array_filter($level['chapters'], static fn (array $chapter): bool => $chapter['isExam'])),
            $this->levels,
        ));
    }

    public function durationSeconds(): int
    {
        return array_sum(array_column($this->levels, 'durationSeconds'));
    }

    /** Suma exacta de los precios de los niveles. */
    public function levelPriceTotal(): string
    {
        $cents = array_sum(array_map(static fn (array $level): int => self::cents($level['cost']), $this->levels));

        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** El nivel más barato (para "desde $X por nivel"). */
    public function cheapestLevelCost(): ?string
    {
        $costs = array_column($this->levels, 'cost');
        usort($costs, static fn (string $a, string $b): int => self::cents($a) <=> self::cents($b));

        return $costs[0] ?? null;
    }

    private static function cents(string $amount): int
    {
        [$integer, $fraction] = explode('.', $amount);

        return (int) $integer * 100 + (int) $fraction;
    }
}
