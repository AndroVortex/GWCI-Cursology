<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Enums\ReportReason;
use App\Models\Enums\ReportStatus;
use App\Models\Enums\ReportTarget;
use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;
use App\Models\Mapping\UniqueKey;
use DateTimeImmutable;

/**
 * Fila de `content_report`: el reporte de un usuario sobre un comentario o una opinión.
 * ck_report_target obliga a que solo uno de comment_id/opinion_id tenga valor, según target_type.
 * Quién reportó y quién lo resolvió son datos de moderación: solo los ve el admin.
 */
final class ContentReport extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly ReportTarget $targetType,
        protected readonly ?string $commentId,
        protected readonly ?string $opinionId,
        protected readonly string $reportedBy,
        protected readonly ReportReason $reasonCode,
        protected readonly ?string $details,
        protected readonly DateTimeImmutable $reportedAt,
        protected readonly ReportStatus $status,
        protected readonly ?string $resolvedBy,
        protected readonly ?DateTimeImmutable $resolvedAt,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        /** @var ReportTarget $target */
        $target = $r->enum('target_type', ReportTarget::class);
        /** @var ReportReason $reason */
        $reason = $r->enum('reason_code', ReportReason::class);
        /** @var ReportStatus $status */
        $status = $r->enum('status', ReportStatus::class);

        return new self(
            id: $r->id('id'),
            targetType: $target,
            commentId: $r->nullableId('comment_id'),
            opinionId: $r->nullableId('opinion_id'),
            reportedBy: $r->id('reported_by'),
            reasonCode: $reason,
            details: $r->nullableString('details', 500),
            reportedAt: $r->dateTime('reported_at'),
            status: $status,
            resolvedBy: $r->nullableId('resolved_by'),
            resolvedAt: $r->nullableDateTime('resolved_at'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'content_report',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('target_type', 'targetType', 'VARCHAR(10)', enum: ReportTarget::class),
                new Column('comment_id', 'commentId', 'BIGINT UNSIGNED', nullable: true),
                new Column('opinion_id', 'opinionId', 'BIGINT UNSIGNED', nullable: true),
                new Column('reported_by', 'reportedBy', 'BIGINT UNSIGNED'),
                new Column('reason_code', 'reasonCode', 'VARCHAR(12)', enum: ReportReason::class),
                new Column('details', 'details', 'VARCHAR(500)', nullable: true),
                new Column('reported_at', 'reportedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('status', 'status', 'VARCHAR(12)', default: "'pending'", enum: ReportStatus::class),
                new Column('resolved_by', 'resolvedBy', 'BIGINT UNSIGNED', nullable: true),
                new Column('resolved_at', 'resolvedAt', 'DATETIME(6)', nullable: true),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_report_comment_user', ['comment_id', 'reported_by']),
                new UniqueKey('uq_report_opinion_user', ['opinion_id', 'reported_by']),
            ],
            foreignKeys: [
                new ForeignKey('fk_report_comment', ['comment_id'], 'chapter_comment', ['id']),
                new ForeignKey('fk_report_opinion', ['opinion_id'], 'course_opinion', ['id']),
                new ForeignKey('fk_report_user', ['reported_by'], 'app_user', ['id']),
                new ForeignKey('fk_report_resolver', ['resolved_by'], 'app_user', ['id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function targetType(): ReportTarget { return $this->targetType; }
    public function commentId(): ?string { return $this->commentId; }
    public function opinionId(): ?string { return $this->opinionId; }
    public function reportedBy(): string { return $this->reportedBy; }
    public function reasonCode(): ReportReason { return $this->reasonCode; }
    public function details(): ?string { return $this->details; }
    public function reportedAt(): DateTimeImmutable { return $this->reportedAt; }
    public function status(): ReportStatus { return $this->status; }
    public function resolvedBy(): ?string { return $this->resolvedBy; }
    public function resolvedAt(): ?DateTimeImmutable { return $this->resolvedAt; }
}
