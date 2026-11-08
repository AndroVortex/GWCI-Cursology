<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;

/** Los comentarios de un curso del instructor, con sus filtros y su página (sp_instructor_course_comments). */
final class InstructorCommentPage
{
    /**
     * @param list<array{id: string, title: string, levelSort: int, chapterSort: int, commentCount: int, unansweredCount: int}> $chapters
     * @param list<ChapterComment> $threads
     * @param array{capitulo: string, estado: string} $filters
     */
    private function __construct(
        public readonly string $courseId,
        public readonly string $courseTitle,
        public readonly bool $courseActive,
        public readonly array $chapters,
        public readonly array $threads,
        public readonly int $total,
        public readonly array $filters,
        public readonly int $page,
        public readonly int $pages,
    ) {
    }

    /**
     * @param list<list<array<string, mixed>>> $resultSets
     * @param array{capitulo: string, estado: string} $filters
     */
    public static function fromResultSets(array $resultSets, array $filters, int $page, int $pageSize): self
    {
        [$courseRows, $chapterRows, $roots, $replies, $reactions, $totals] = $resultSets + [[], [], [], [], [], []];
        $course = new RowReader(self::class, $courseRows[0] ?? []);
        $total = (new RowReader(self::class, $totals[0] ?? ['total' => 0]))->intUnsigned('total');

        return new self(
            $course->id('id'),
            $course->string('title', 200),
            $course->bool('active'),
            array_map(static function (array $row): array {
                $r = new RowReader(self::class, $row);

                return [
                    'id' => $r->id('id'),
                    'title' => $r->string('title', 200),
                    'levelSort' => $r->intUnsigned('level_sort'),
                    'chapterSort' => $r->intUnsigned('chapter_sort'),
                    'commentCount' => $r->intUnsigned('comment_count'),
                    'unansweredCount' => $r->intUnsigned('unanswered_count'),
                ];
            }, $chapterRows),
            ChapterComment::threads($roots, $replies, $reactions),
            $total,
            $filters,
            $page,
            max(1, intdiv($total + $pageSize - 1, $pageSize)),
        );
    }

    /**
     * Los hilos de la página agrupados por capítulo, en el orden del temario.
     *
     * @return list<array{chapterId: string, label: string, threads: list<ChapterComment>}>
     */
    public function byChapter(): array
    {
        $groups = [];
        foreach ($this->threads as $thread) {
            $groups[$thread->chapterId] ??= ['chapterId' => $thread->chapterId, 'label' => $thread->chapterLabel(), 'sort' => [$thread->levelSort, $thread->chapterSort], 'threads' => []];
            $groups[$thread->chapterId]['threads'][] = $thread;
        }
        usort($groups, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_map(static fn (array $group): array => ['chapterId' => $group['chapterId'], 'label' => $group['label'], 'threads' => $group['threads']], $groups);
    }

    public function unansweredTotal(): int
    {
        return array_sum(array_column($this->chapters, 'unansweredCount'));
    }

    public function hasMore(): bool
    {
        return $this->page < $this->pages;
    }
}
