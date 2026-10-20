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
 * Fila de `chapter_comment_reaction`: UNA reacción por usuario y comentario
 * (uq_chapter_comment_reaction_user). Cambiarla actualiza reaction_id.
 */
final class ChapterCommentReaction extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $commentId,
        protected readonly string $reactedBy,
        protected readonly string $reactionId,
        protected readonly DateTimeImmutable $reactedAt,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            commentId: $r->id('comment_id'),
            reactedBy: $r->id('reacted_by'),
            reactionId: $r->id('reaction_id'),
            reactedAt: $r->dateTime('reacted_at'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'chapter_comment_reaction',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('comment_id', 'commentId', 'BIGINT UNSIGNED'),
                new Column('reacted_by', 'reactedBy', 'BIGINT UNSIGNED'),
                new Column('reaction_id', 'reactionId', 'BIGINT UNSIGNED'),
                new Column('reacted_at', 'reactedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_chapter_comment_reaction_user', ['comment_id', 'reacted_by']),
            ],
            foreignKeys: [
                new ForeignKey('fk_chapter_comment_reaction_target', ['comment_id'], 'chapter_comment', ['id']),
                new ForeignKey('fk_chapter_comment_reaction_user', ['reacted_by'], 'app_user', ['id']),
                new ForeignKey('fk_chapter_comment_reaction_reaction', ['reaction_id'], 'reaction', ['id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function commentId(): string { return $this->commentId; }
    public function reactedBy(): string { return $this->reactedBy; }
    public function reactionId(): string { return $this->reactionId; }
    public function reactedAt(): DateTimeImmutable { return $this->reactedAt; }
}
