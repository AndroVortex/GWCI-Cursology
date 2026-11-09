<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Enums\ReportReason;
use App\Models\Enums\ReportTarget;
use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Un contenido en la cola de moderación con sus reportes pendientes (sp_moderation_reports). */
final class ModerationReport
{
    /** @param array<string, int> $reasonCounts valor de ReportReason => cuántos */
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
        public readonly int $reportCount,
        public readonly array $reasonCounts,
        public readonly DateTimeImmutable $firstReportedAt,
        public readonly DateTimeImmutable $lastReportedAt,
        public readonly ?string $lastDetails,
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
            $r->intUnsigned('report_count'),
            [
                ReportReason::Spam->value => $r->intUnsigned('spam_count'),
                ReportReason::Offensive->value => $r->intUnsigned('offensive_count'),
                ReportReason::Answers->value => $r->intUnsigned('answers_count'),
                ReportReason::Other->value => $r->intUnsigned('other_count'),
            ],
            $r->dateTime('first_reported_at'),
            $r->dateTime('last_reported_at'),
            $r->nullableString('last_details', 500),
        );
    }

    /** "2 × Spam o publicidad, 1 × Ofensivo o acoso", del motivo más repetido al menos. */
    public function reasonsSummary(): string
    {
        $parts = [];
        foreach ($this->sortedReasons() as [$reason, $count]) {
            $parts[] = sprintf('%d × %s', $count, $reason->label());
        }

        return implode(', ', $parts);
    }

    /** El motivo más repetido, para proponerlo como motivo al ocultar. */
    public function topReason(): ReportReason
    {
        return $this->sortedReasons()[0][0] ?? ReportReason::Other;
    }

    /** @return list<array{0: ReportReason, 1: int}> */
    private function sortedReasons(): array
    {
        $reasons = [];
        foreach (ReportReason::cases() as $order => $reason) {
            $count = $this->reasonCounts[$reason->value] ?? 0;
            if ($count > 0) {
                $reasons[] = [$reason, $count, $order];
            }
        }
        usort($reasons, static fn (array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        return array_map(static fn (array $item): array => [$item[0], $item[1]], $reasons);
    }
}
