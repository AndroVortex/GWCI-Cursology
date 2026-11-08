<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\StoredProcedures;
use App\Models\Dto\ChapterComment;
use App\Models\Dto\ModerationItem;
use App\Models\Dto\ModerationReport;
use App\Models\Entities\CourseOpinion;
use App\Models\Enums\ReportReason;
use App\Models\Enums\ReportTarget;

/** Reportes de contenido y moderación del admin, exclusivamente mediante SP. */
final class ModerationRepository
{
    use CallsProcedures;

    public function __construct(private readonly StoredProcedures $procedures)
    {
    }

    /** @return bool true si se creó; false si el usuario ya lo había reportado */
    public function report(string $userId, ReportTarget $target, string $targetId, ReportReason $reason, ?string $details): bool
    {
        return (bool) $this->requiredRow('sp_report_create', [$userId, $target->value, $targetId, $reason->value, $details])['created'];
    }

    /** @return int cuántos reportes pendientes se descartaron */
    public function dismiss(string $adminId, ReportTarget $target, string $targetId): int
    {
        return (int) $this->requiredRow('sp_report_dismiss', [$adminId, $target->value, $targetId])['dismissed'];
    }

    /** @return array{0: list<ModerationReport>, 1: int, 2: int} [cola, contenidos, reportes pendientes] */
    public function reports(string $adminId, int $limit, int $offset): array
    {
        [$rows, $totals] = $this->resultSets('sp_moderation_reports', [$adminId, $limit, $offset]) + [[], []];

        return [array_map(ModerationReport::fromRow(...), $rows), (int) ($totals[0]['total'] ?? 0), (int) ($totals[0]['report_total'] ?? 0)];
    }

    /**
     * @param ?string $pattern patrón LIKE ya escapado, con % alrededor
     * @return array{0: list<ModerationItem>, 1: int} [contenido, total]
     */
    public function content(string $adminId, ?ReportTarget $type, ?string $state, ?string $pattern, int $limit, int $offset): array
    {
        [$rows, $totals] = $this->resultSets('sp_moderation_content', [$adminId, $type?->value, $state, $pattern, $limit, $offset]) + [[], []];

        return [array_map(ModerationItem::fromRow(...), $rows), (int) ($totals[0]['total'] ?? 0)];
    }

    public function restoreComment(string $adminId, string $commentId): ChapterComment
    {
        return ChapterComment::fromRow($this->requiredRow('sp_comment_restore', [$adminId, $commentId]));
    }

    public function restoreOpinion(string $adminId, string $opinionId): CourseOpinion
    {
        return CourseOpinion::fromRow($this->requiredRow('sp_opinion_restore', [$adminId, $opinionId]));
    }
}
