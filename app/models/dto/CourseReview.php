<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Una opinión visible en la página del curso, con las estrellas de su autor y sus reacciones (sp_review_list). */
final class CourseReview
{
    private function __construct(
        public readonly string $opinionId,
        public readonly string $opinion,
        public readonly ?int $rate,
        public readonly string $authorName,
        public readonly string $authorAvatar,
        public readonly DateTimeImmutable $commentedAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly ReactionSummary $reactions,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row, ReactionSummary $reactions = new ReactionSummary()): self
    {
        $r = new RowReader(self::class, $row);

        return new self(
            $r->id('id'),
            $r->text('opinion'),
            $r->nullableTinyInt('rate', min: 1, max: 5),
            $r->string('author_name', 350),
            $r->string('author_avatar', 2048),
            $r->dateTime('commented_at'),
            $r->dateTime('updated_at'),
            $reactions,
        );
    }

    public function wasEdited(): bool
    {
        return $this->updatedAt > $this->commentedAt;
    }
}
