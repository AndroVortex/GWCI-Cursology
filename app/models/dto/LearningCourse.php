<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Entities\CourseChapterContent;
use App\Models\Mapping\RowReader;
use App\Services\PricingMode;
use DateTimeImmutable;

/**
 * Un curso visto por su alumno (sp_learning_course): niveles con acceso, capítulos con avance
 * y el contenido solo de los niveles que tiene.
 *
 * @phpstan-type Chapter array{id: string, levelId: string, title: string, sortOrder: int, isExam: bool,
 *     minimumGrade: ?string, durationSeconds: ?int, questionCount: int, positionSeconds: int,
 *     lastAccessedAt: ?DateTimeImmutable, completedAt: ?DateTimeImmutable, bestGrade: ?string}
 * @phpstan-type Level array{id: string, title: string, description: string, sortOrder: int, cost: string,
 *     hasAccess: bool, completedAt: ?DateTimeImmutable, chapters: list<Chapter>}
 */
final class LearningCourse
{
    /**
     * @param list<Level> $levels
     * @param array<string, list<CourseChapterContent>> $contents por capítulo, video principal primero
     */
    private function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $coverImage,
        public readonly ?string $fullCoursePrice,
        public readonly string $currency,
        public readonly bool $active,
        public readonly string $instructorName,
        public readonly DateTimeImmutable $enrolledAt,
        public readonly ?DateTimeImmutable $lastAccessedAt,
        public readonly ?DateTimeImmutable $completedAt,
        public readonly bool $ownsCourse,
        public readonly array $levels,
        private readonly array $contents,
    ) {
    }

    /** @param list<list<array<string, mixed>>> $resultSets */
    public static function fromResultSets(array $resultSets): self
    {
        [$courseRows, $levelRows, $chapterRows, $contentRows] = $resultSets + [[], [], [], []];
        $r = new RowReader(self::class, $courseRows[0] ?? []);

        $chaptersByLevel = [];
        foreach ($chapterRows as $row) {
            $c = new RowReader(self::class, $row);
            $chaptersByLevel[$c->id('course_level_id')][] = [
                'id' => $c->id('id'),
                'levelId' => $c->id('course_level_id'),
                'title' => $c->string('title', 200),
                'sortOrder' => $c->intUnsigned('sort_order'),
                'isExam' => $c->bool('is_test'),
                'minimumGrade' => $c->nullableDecimal('minimum_passing_grade', 5, 2),
                'durationSeconds' => $c->nullableIntUnsigned('duration_seconds'),
                'questionCount' => $c->intUnsigned('question_count'),
                'positionSeconds' => $c->nullableIntUnsigned('video_position_seconds') ?? 0,
                'lastAccessedAt' => $c->nullableDateTime('last_accessed_at'),
                'completedAt' => $c->nullableDateTime('completed_at'),
                'bestGrade' => $c->nullableDecimal('best_grade', 5, 2),
            ];
        }

        $levels = [];
        foreach ($levelRows as $row) {
            $l = new RowReader(self::class, $row);
            $levels[] = [
                'id' => $l->id('id'),
                'title' => $l->string('title', 200),
                'description' => $l->text('description'),
                'sortOrder' => $l->intUnsigned('sort_order'),
                'cost' => $l->decimal('cost', 12, 2),
                'hasAccess' => $l->bool('has_access'),
                'completedAt' => $l->nullableDateTime('completed_at'),
                'chapters' => $chaptersByLevel[$l->id('id')] ?? [],
            ];
        }

        $contents = [];
        foreach ($contentRows as $row) {
            $content = CourseChapterContent::fromRow($row);
            $contents[$content->courseChapterId()][] = $content;
        }

        return new self(
            $r->id('id'),
            $r->string('title', 200),
            $r->string('cover_image', 2048),
            $r->nullableDecimal('full_course_price', 12, 2),
            $r->string('currency', 3),
            $r->bool('active'),
            $r->string('instructor_name', 350),
            $r->dateTime('enrolled_at'),
            $r->nullableDateTime('last_accessed_at'),
            $r->nullableDateTime('completed_at'),
            $r->bool('owns_course'),
            $levels,
            $contents,
        );
    }

    public function pricingMode(): PricingMode
    {
        return PricingMode::fromFullCoursePrice($this->fullCoursePrice);
    }

    /** @return list<Chapter> todos los capítulos en orden */
    public function chapters(): array
    {
        return array_merge([], ...array_column($this->levels, 'chapters'));
    }

    /** @return ?Level */
    public function level(string $levelId): ?array
    {
        foreach ($this->levels as $level) {
            if ($level['id'] === $levelId) {
                return $level;
            }
        }

        return null;
    }

    /** @return ?Chapter */
    public function chapter(string $chapterId): ?array
    {
        foreach ($this->chapters() as $chapter) {
            if ($chapter['id'] === $chapterId) {
                return $chapter;
            }
        }

        return null;
    }

    public function hasAccessTo(array $chapter): bool
    {
        return $this->level($chapter['levelId'])['hasAccess'] ?? false;
    }

    /**
     * El capítulo para retomar: el último que visitó; si no, el primero sin terminar con acceso;
     * si no, el primero con acceso.
     *
     * @return ?Chapter
     */
    public function resumeChapter(): ?array
    {
        $accessible = array_values(array_filter($this->chapters(), $this->hasAccessTo(...)));
        $visited = array_filter($accessible, static fn (array $chapter): bool => $chapter['lastAccessedAt'] !== null);
        usort($visited, static fn (array $a, array $b): int => $b['lastAccessedAt'] <=> $a['lastAccessedAt']);

        return $visited[0]
            ?? array_values(array_filter($accessible, static fn (array $chapter): bool => $chapter['completedAt'] === null))[0]
            ?? $accessible[0]
            ?? null;
    }

    /** @return ?Chapter el capítulo que sigue en el orden del curso (tenga o no acceso) */
    public function nextChapter(string $chapterId): ?array
    {
        $chapters = $this->chapters();
        foreach ($chapters as $index => $chapter) {
            if ($chapter['id'] === $chapterId) {
                return $chapters[$index + 1] ?? null;
            }
        }

        return null;
    }

    /** @return list<CourseChapterContent> */
    public function contents(string $chapterId): array
    {
        return $this->contents[$chapterId] ?? [];
    }

    public function mainVideo(string $chapterId): ?CourseChapterContent
    {
        $first = $this->contents($chapterId)[0] ?? null;

        return $first !== null && $first->isMain() ? $first : null;
    }

    /** @return list<CourseChapterContent> */
    public function resources(string $chapterId): array
    {
        return array_values(array_filter($this->contents($chapterId), static fn (CourseChapterContent $content): bool => !$content->isMain()));
    }

    public function chapterCount(): int
    {
        return count($this->chapters());
    }

    public function completedChapterCount(): int
    {
        return count(array_filter($this->chapters(), static fn (array $chapter): bool => $chapter['completedAt'] !== null));
    }

    /** Avance sobre todos los capítulos del curso, de 0 a 100. */
    public function progressPercent(): int
    {
        $total = $this->chapterCount();

        return $total === 0 ? 0 : intdiv(100 * $this->completedChapterCount(), $total);
    }
}
