<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Enums\AccountEventType;
use App\Models\Enums\AccountStatus;
use App\Models\Enums\UserRole;
use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Una cuenta en la vista "Cuentas" del admin (sp_admin_list_users). Nunca trae el hash de la contraseña. */
final class AdminUserRow
{
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $fatherLastName,
        public readonly ?string $motherLastName,
        public readonly string $email,
        public readonly string $avatar,
        public readonly UserRole $role,
        public readonly AccountStatus $status,
        public readonly bool $banned,
        public readonly ?string $banReason,
        public readonly ?DateTimeImmutable $bannedAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $lastLoginAt,
        public readonly DateTimeImmutable $statusChangedAt,
        public readonly ?AccountEventType $lastEventType,
        public readonly ?DateTimeImmutable $lastEventAt,
        public readonly ?string $lastEventReason,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);

        /** @var UserRole $role */
        $role = $r->enum('role', UserRole::class);
        /** @var AccountStatus $status */
        $status = $r->enum('account_status', AccountStatus::class);
        /** @var ?AccountEventType $event */
        $event = $r->nullableEnum('last_event_type', AccountEventType::class);

        return new self(
            $r->id('id'),
            $r->string('name', 100),
            $r->string('father_last_name', 100),
            $r->nullableString('mother_last_name', 100),
            $r->string('email', 254),
            $r->string('avatar', 2048),
            $role,
            $status,
            $r->bool('ban'),
            $r->nullableString('ban_reason', 500),
            $r->nullableDateTime('banned_at'),
            $r->dateTime('created_at'),
            $r->nullableDateTime('last_login_at'),
            $r->dateTime('status_changed_at'),
            $event,
            $r->nullableDateTime('last_event_at'),
            $r->nullableString('last_event_reason', 500),
        );
    }

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([$this->name, $this->fatherLastName, $this->motherLastName], static fn (?string $part): bool => $part !== null && $part !== '')));
    }

    /** No se suspende a un administrador (tampoco a uno mismo). */
    public function canBan(): bool
    {
        return !$this->banned && $this->role !== UserRole::Admin;
    }

    public function canReactivate(): bool
    {
        return $this->status !== AccountStatus::Active;
    }
}
