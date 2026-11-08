<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/**
 * Un comentario de un capítulo con su autor, sus reacciones y, si es principal, sus respuestas
 * (sp_comment_list, sp_comment_create, sp_instructor_course_comments). content es null si se
 * eliminó o se ocultó: se muestra como "Comentario eliminado".
 * chapterTitle, levelSort, chapterSort y answered solo vienen en la vista del instructor.
 */
final class ChapterComment
{
    /** @param list<ChapterComment> $replies */
    private function __construct(
        public readonly string $id,
        public readonly string $chapterId,
        public readonly ?string $parentId,
        public readonly string $authorId,
        public readonly string $authorName,
        public readonly string $authorAvatar,
        public readonly bool $authorIsInstructor,
        public readonly ?string $content,
        public readonly DateTimeImmutable $commentedAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly bool $active,
        public readonly int $replyCount,
        public readonly ?string $chapterTitle,
        public readonly ?int $levelSort,
        public readonly ?int $chapterSort,
        public readonly ?bool $answered,
        public readonly ReactionSummary $reactions,
        public readonly array $replies,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param list<ChapterComment> $replies
     */
    public static function fromRow(array $row, ReactionSummary $reactions = new ReactionSummary(), array $replies = []): self
    {
        $r = new RowReader(self::class, $row);
        $withChapter = array_key_exists('chapter_title', $row);

        return new self(
            $r->id('id'),
            $r->id('course_chapter_id'),
            $r->nullableId('comment_parent_id'),
            $r->id('commented_by'),
            $r->string('author_name', 350),
            $r->string('author_avatar', 2048),
            $r->bool('author_is_instructor'),
            $r->nullableText('comment'),
            $r->dateTime('commented_at'),
            $r->dateTime('updated_at'),
            $r->bool('active'),
            $r->intUnsigned('reply_count'),
            $withChapter ? $r->string('chapter_title', 200) : null,
            $withChapter ? $r->intUnsigned('level_sort') : null,
            $withChapter ? $r->intUnsigned('chapter_sort') : null,
            $withChapter ? $r->bool('answered') : null,
            $reactions,
            $replies,
        );
    }

    /**
     * Arma los hilos: cada comentario principal con sus respuestas y las reacciones de todos.
     *
     * @param list<array<string, mixed>> $rootRows
     * @param list<array<string, mixed>> $replyRows
     * @param list<array<string, mixed>> $reactionRows
     * @return list<ChapterComment>
     */
    public static function threads(array $rootRows, array $replyRows, array $reactionRows): array
    {
        $reactions = ReactionSummary::byTarget($reactionRows);
        $replies = [];
        foreach ($replyRows as $row) {
            $reply = self::fromRow($row, ReactionSummary::of($reactions, (string) $row['id']));
            $replies[(string) $reply->parentId][] = $reply;
        }

        return array_map(static function (array $row) use ($reactions, $replies): self {
            $id = (string) $row['id'];

            return self::fromRow($row, ReactionSummary::of($reactions, $id), $replies[$id] ?? []);
        }, $rootRows);
    }

    public function isRemoved(): bool
    {
        return !$this->active;
    }

    public function wasEdited(): bool
    {
        return $this->active && $this->updatedAt > $this->commentedAt;
    }

    public function isFrom(string $userId): bool
    {
        return $this->authorId === $userId;
    }

    /** "Nivel 1 · 1.2 Variables" en la vista del instructor; '' si no trae el capítulo. */
    public function chapterLabel(): string
    {
        return $this->chapterTitle === null ? '' : sprintf('Nivel %d · %d.%d %s', $this->levelSort, $this->levelSort, $this->chapterSort, $this->chapterTitle);
    }
}
