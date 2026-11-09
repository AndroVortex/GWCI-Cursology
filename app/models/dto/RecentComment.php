<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Un comentario reciente de un alumno en los cursos del instructor (sp_instructor_recent_comments). */
final class RecentComment
{
    private function __construct(
        public readonly string $id,
        public readonly string $courseId,
        public readonly string $courseTitle,
        public readonly string $chapterId,
        public readonly string $chapterTitle,
        public readonly string $authorName,
        public readonly string $authorAvatar,
        public readonly string $content,
        public readonly DateTimeImmutable $commentedAt,
        public readonly bool $answered,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);

        return new self(
            $r->id('id'),
            $r->id('course_id'),
            $r->string('course_title', 200),
            $r->id('chapter_id'),
            $r->string('chapter_title', 200),
            $r->string('author_name', 350),
            $r->string('author_avatar', 2048),
            $r->text('comment'),
            $r->dateTime('commented_at'),
            $r->bool('answered'),
        );
    }

    /** El enlace al hilo en la vista de comentarios del curso. */
    public function url(): string
    {
        return sprintf('?view=course-comments&id=%s&capitulo=%s#comentario-%s', rawurlencode($this->courseId), rawurlencode($this->chapterId), rawurlencode($this->id));
    }

    public function excerpt(int $maxChars = 140): string
    {
        return ChatMessage::excerpt($this->content, $maxChars);
    }
}
