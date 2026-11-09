<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Una opinión reciente de los cursos del instructor, visible u oculta (sp_instructor_recent_reviews). */
final class RecentReview
{
    private function __construct(
        public readonly string $opinionId,
        public readonly string $courseId,
        public readonly string $courseTitle,
        public readonly ?string $opinion,
        public readonly ?int $rate,
        public readonly string $authorName,
        public readonly string $authorAvatar,
        public readonly DateTimeImmutable $commentedAt,
        public readonly bool $active,
        public readonly ?string $reason,
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
            $r->nullableText('opinion'),
            $r->nullableTinyInt('rate', min: 1, max: 5),
            $r->string('author_name', 350),
            $r->string('author_avatar', 2048),
            $r->dateTime('commented_at'),
            $r->bool('active'),
            $r->nullableString('reason', 500),
        );
    }

    /** Las primeras palabras de la opinión, para la lista del panel. */
    public function excerpt(int $maxChars = 140): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $this->opinion));

        return mb_strlen($text) <= $maxChars ? $text : rtrim(mb_substr($text, 0, $maxChars - 1)) . '…';
    }
}
