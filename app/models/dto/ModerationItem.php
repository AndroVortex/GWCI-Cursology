<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Enums\ReportTarget;
use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Un comentario u opinión en "Todo el contenido" de la moderación (sp_moderation_content). */
final class ModerationItem
{
    private function __construct(
        public readonly ReportTarget $targetType,
        public readonly string $targetId,
        public readonly ?string $content,
        public readonly bool $active,
        public readonly DateTimeImmutable $createdAt,
        public readonly string $authorId,
        public readonly string $authorName,
        public readonly string $authorEmail,
        public readonly string $authorAvatar,
        public readonly string $courseId,
        public readonly string $courseTitle,
        public readonly ?string $chapterId,
        public readonly ?string $chapterTitle,
        public readonly ?DateTimeImmutable $deactivatedAt,
        public readonly ?string $deactivationReason,
        public readonly ?string $deactivatedByName,
        public readonly bool $removedByAuthor,
        public readonly bool $restorable,
        public readonly int $reportCount,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);

        /** @var ReportTarget $target */
        $target = $r->enum('target_type', ReportTarget::class);

        return new self(
            $target,
            $r->id('target_id'),
            $r->nullableText('content'),
            $r->bool('active'),
            $r->dateTime('created_at'),
            $r->id('author_id'),
            $r->string('author_name', 350),
            $r->string('author_email', 254),
            $r->string('author_avatar', 2048),
            $r->id('course_id'),
            $r->string('course_title', 200),
            $r->nullableId('chapter_id'),
            $r->nullableString('chapter_title', 200),
            $r->nullableDateTime('deactivated_at'),
            $r->nullableString('deactivation_reason', 500),
            $r->nullableString('deactivated_by_name', 350),
            $r->bool('removed_by_author'),
            $r->bool('restorable'),
            $r->intUnsigned('report_count'),
        );
    }

    /** "Visible", "Eliminado por su autor" u "Oculto por Irene López: motivo". */
    public function statusLabel(): string
    {
        return match (true) {
            $this->active => 'Visible',
            $this->removedByAuthor => 'Eliminado por su autor',
            default => trim(sprintf('Oculto por %s: %s', $this->deactivatedByName ?? 'un moderador', $this->deactivationReason ?? '')),
        };
    }
}
