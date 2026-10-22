<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Enums\UserRole;
use App\Models\Mapping\RowReader;
use DateTimeImmutable;

/** Fila de sp_admin_list_locked_users: una cuenta bloqueada para el panel del admin. */
final class LockedAccount
{
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $fatherLastName,
        public readonly ?string $motherLastName,
        public readonly string $email,
        public readonly UserRole $role,
        public readonly string $avatar,
        public readonly bool $banned,
        public readonly DateTimeImmutable $lockedAt,
        public readonly ?DateTimeImmutable $lastAttemptAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);

        return new self(
            $r->id('id'),
            $r->string('name', 100),
            $r->string('father_last_name', 100),
            $r->nullableString('mother_last_name', 100),
            $r->string('email', 254),
            $r->enum('role', UserRole::class),
            $r->string('avatar', 2048),
            $r->bool('ban'),
            $r->dateTime('status_changed_at'),
            $r->nullableDateTime('last_attempt_at'),
        );
    }

    public function fullName(): string
    {
        return implode(' ', array_filter([$this->name, $this->fatherLastName, $this->motherLastName]));
    }
}
