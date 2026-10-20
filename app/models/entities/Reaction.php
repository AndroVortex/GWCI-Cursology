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

/** Fila de `reaction`: catálogo de emojis administrado por admin. */
final class Reaction extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $emoji,
        protected readonly string $reactionName,
        protected readonly string $createdBy,
        protected readonly DateTimeImmutable $createdAt,
        protected readonly ?string $updatedBy,
        protected readonly DateTimeImmutable $updatedAt,
        protected readonly bool $active,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            emoji: $r->string('emoji', 32),
            reactionName: $r->string('reaction_name', 60),
            createdBy: $r->id('created_by'),
            createdAt: $r->dateTime('created_at'),
            updatedBy: $r->nullableId('updated_by'),
            updatedAt: $r->dateTime('updated_at'),
            active: $r->bool('active'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'reaction',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('emoji', 'emoji', 'VARCHAR(32)'),
                new Column('reaction_name', 'reactionName', 'VARCHAR(60)'),
                new Column('created_by', 'createdBy', 'BIGINT UNSIGNED'),
                new Column('created_at', 'createdAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
                new Column('updated_by', 'updatedBy', 'BIGINT UNSIGNED', nullable: true),
                new Column('updated_at', 'updatedAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)', onUpdate: 'CURRENT_TIMESTAMP(6)'),
                new Column('active', 'active', 'BOOLEAN', default: 'TRUE'),
            ],
            primaryKey: ['id'],
            uniqueKeys: [
                new UniqueKey('uq_reaction_name', ['reaction_name']),
            ],
            foreignKeys: [
                new ForeignKey('fk_reaction_creator', ['created_by'], 'app_user', ['id']),
                new ForeignKey('fk_reaction_updater', ['updated_by'], 'app_user', ['id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function emoji(): string { return $this->emoji; }
    public function reactionName(): string { return $this->reactionName; }
    public function createdBy(): string { return $this->createdBy; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedBy(): ?string { return $this->updatedBy; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function active(): bool { return $this->active; }
}
