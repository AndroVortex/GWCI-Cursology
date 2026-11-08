<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;

/** Una página de comentarios de un capítulo (sp_comment_list). */
final class CommentPage
{
    /** @param list<ChapterComment> $comments */
    public function __construct(
        public readonly string $chapterId,
        public readonly array $comments,
        public readonly int $total,
        public readonly bool $viewerIsOwner,
        public readonly int $page,
        public readonly int $pages,
    ) {
    }

    /** @param list<list<array<string, mixed>>> $resultSets */
    public static function fromResultSets(string $chapterId, array $resultSets, int $page, int $pageSize): self
    {
        [$roots, $replies, $reactions, $totals] = $resultSets + [[], [], [], []];
        $r = new RowReader(self::class, $totals[0] ?? ['total' => 0, 'viewer_is_owner' => 0]);
        $total = $r->intUnsigned('total');

        return new self(
            $chapterId,
            ChapterComment::threads($roots, $replies, $reactions),
            $total,
            $r->bool('viewer_is_owner'),
            $page,
            max(1, intdiv($total + $pageSize - 1, $pageSize)),
        );
    }

    public function hasMore(): bool
    {
        return $this->page < $this->pages;
    }
}
