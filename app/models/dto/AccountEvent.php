<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Enums\AccountEventType;
use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Un evento del historial de una cuenta (sp_admin_user_events). */
final class AccountEvent
{
    private function __construct(
        public readonly string $id,
        public readonly AccountEventType $type,
        public readonly string $reason,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?string $actorId,
        public readonly ?string $actorName,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);

        /** @var AccountEventType $type */
        $type = $r->enum('event_type', AccountEventType::class);

        return new self(
            $r->id('id'),
            $type,
            $r->string('reason', 500),
            $r->dateTime('created_at'),
            $r->nullableId('actor_id'),
            $r->nullableString('actor_name', 350),
        );
    }

    public function label(): string
    {
        return match ($this->type) {
            AccountEventType::Locked => 'Bloqueada',
            AccountEventType::Reactivated => 'Reactivada',
            AccountEventType::Deleted => 'Dada de baja',
            AccountEventType::Banned => 'Suspendida',
            AccountEventType::Unbanned => 'Suspensión retirada',
        };
    }

    /** Quién lo hizo: el admin, la propia cuenta o el sistema (bloqueo automático). */
    public function actorLabel(string $userId): string
    {
        return match (true) {
            $this->actorId === null => 'Sistema',
            $this->actorId === $userId => 'La propia cuenta',
            default => (string) $this->actorName,
        };
    }
}
