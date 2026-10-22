<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Entities\Course;
use App\Models\Entities\CourseCategory;
use App\Models\Entities\CourseChapter;
use App\Models\Entities\CourseChapterContent;
use App\Models\Entities\CourseLevel;

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

        return new self(
            Course::fromRow($courseRows[0] ?? []),
            array_map(static fn (array $row): string => CourseCategory::fromRow($row)->categoryId(), $categoryRows),
            array_map(static fn (array $row): CourseLevel => CourseLevel::fromRow($row), $levelRows),
            $chaptersByLevel,
            $contentsByChapter,
        );
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
