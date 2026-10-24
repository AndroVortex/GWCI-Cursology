<?php

declare(strict_types=1);

namespace App\Models\Entities;

use App\Models\Enums\AccountEventType;
use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\ForeignKey;
use App\Models\Mapping\RowReader;
use App\Models\Mapping\TableMetadata;
use DateTimeImmutable;

/**
 * Fila de `user_account_event`: historial de bloqueo, reactivación, baja y ban.
 * actor_id es NULL cuando el evento lo origina el sistema (p. ej. bloqueo automático).
 */
final class UserAccountEvent extends Entity
{
    private function __construct(
        protected readonly string $id,
        protected readonly string $userId,
        protected readonly ?string $actorId,
        protected readonly AccountEventType $eventType,
        protected readonly string $reason,
        protected readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $r = new RowReader(self::class, $row);

        return new self(
            id: $r->id('id'),
            userId: $r->id('user_id'),
            actorId: $r->nullableId('actor_id'),
            eventType: $r->enum('event_type', AccountEventType::class),
            reason: $r->string('reason', 500),
            createdAt: $r->dateTime('created_at'),
        );
    }

    public static function metadata(): TableMetadata
    {
        return new TableMetadata(
            table: 'user_account_event',
            columns: [
                new Column('id', 'id', 'BIGINT UNSIGNED', autoIncrement: true),
                new Column('user_id', 'userId', 'BIGINT UNSIGNED'),
                new Column('actor_id', 'actorId', 'BIGINT UNSIGNED', nullable: true),
                new Column('event_type', 'eventType', 'VARCHAR(20)', enum: AccountEventType::class),
                new Column('reason', 'reason', 'VARCHAR(500)'),
                new Column('created_at', 'createdAt', 'DATETIME(6)', default: 'CURRENT_TIMESTAMP(6)'),
            ],
            primaryKey: ['id'],
            foreignKeys: [
                new ForeignKey('fk_account_event_user', ['user_id'], 'app_user', ['id']),
                new ForeignKey('fk_account_event_actor', ['actor_id'], 'app_user', ['id']),
            ],
        );
    }

    public function id(): string { return $this->id; }
    public function userId(): string { return $this->userId; }
    public function actorId(): ?string { return $this->actorId; }
    public function eventType(): AccountEventType { return $this->eventType; }
    public function reason(): string { return $this->reason; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
}
