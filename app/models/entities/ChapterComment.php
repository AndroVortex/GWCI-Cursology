<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;
use App\Models\Mapping\UniqueKey;
use DateTimeImmutable;

/**
 * Fila de `chapter_comment`: comentario o respuesta dentro de un capítulo.
 *
 * comment_parent_id es solo un ID: la entidad no carga el padre ni arma el árbol.
 * fk_comment_parent obliga a que la respuesta esté en el mismo capítulo.
 * Baja lógica con active/deactivated_by/deactivated_at/reason.
 */
final class ChapterComment extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $courseChapterId,
        protected readonly ?string $commentParentId,
        protected readonly string $commentedBy,
        protected readonly ?string $comment,
        protected readonly ?string $multimediaContent,
        protected readonly DateTimeImmutable $commentedAt,
        protected readonly DateTimeImmutable $updatedAt,
        protected readonly bool $active,
        protected readonly ?string $deactivatedBy,
        protected readonly ?DateTimeImmutable $deactivatedAt,
        protected readonly ?string $reason,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            courseChapterId: $r->id('course_chapter_id'),
            commentParentId: $r->nullableId('comment_parent_id'),
            commentedBy: $r->id('commented_by'),
            comment: $r->nullableText('comment'),
            multimediaContent: $r->nullableString('multimedia_content', 2048),
            commentedAt: $r->dateTime('commented_at'),
            updatedAt: $r->dateTime('updated_at'),
            active: $r->bool('active'),
            deactivatedBy: $r->nullableId('deactivated_by'),
            deactivatedAt: $r->nullableDateTime('deactivated_at'),
            reason: $r->nullableString('reason', 500),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'chapter_comment',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('course_chapter_id', 'courseChapterId', 'BIGINT UNSIGNED'),
                new Column('comment_parent_id', 'commentParentId', 'BIGINT UNSIGNED', nullable: true),
                new Column('commented_by', 'commentedBy', 'BIGINT UNSIGNED'),
                new Column('comment', 'comment', 'TEXT', nullable: true),
                new Column('multimedia_content', 'multimediaContent', 'VARCHAR(2048)', nullable: true),
                new Column('commented_at', 'commentedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('updated_at', 'updatedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)', onUpdate: 'CURRENT_TIMESTAMP(6)'),
                new Column('active', 'active', 'BOOLEAN', default: 'TRUE'),
                new Column('deactivated_by', 'deactivatedBy', 'BIGINT UNSIGNED', nullable: true),
                new Column('deactivated_at', 'deactivatedAt', 'DATETIME(6)', nullable: true),
                new Column('reason', 'reason', 'VARCHAR(500)', nullable: true),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_comment_chapter', ['id', 'course_chapter_id']),
            ],
            foreignKeys: [
                new ForeignKey('fk_comment_chapter', ['course_chapter_id'], 'course_chapter', ['id']),
                new ForeignKey('fk_comment_author', ['commented_by'], 'app_user', ['id']),
                new ForeignKey('fk_comment_parent', ['comment_parent_id', 'course_chapter_id'], 'chapter_comment', ['id', 'course_chapter_id']),
                new ForeignKey('fk_comment_deactivator', ['deactivated_by'], 'app_user', ['id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function courseChapterId(): string { return $this->courseChapterId; }
    public function commentParentId(): ?string { return $this->commentParentId; }
    public function commentedBy(): string { return $this->commentedBy; }
    public function comment(): ?string { return $this->comment; }
    public function multimediaContent(): ?string { return $this->multimediaContent; }
    public function commentedAt(): DateTimeImmutable { return $this->commentedAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function active(): bool { return $this->active; }
    public function deactivatedBy(): ?string { return $this->deactivatedBy; }
    public function deactivatedAt(): ?DateTimeImmutable { return $this->deactivatedAt; }
    public function reason(): ?string { return $this->reason; }
}
