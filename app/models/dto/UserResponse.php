<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Entities\AppUser;
use JsonSerializable;

/**
 * Datos del usuario que la API puede devolver a su propio dueño. No incluye el hash,
 * el estado de la cuenta, el ban ni la auditoría.
 */
final class UserResponse implements JsonSerializable
{
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $fatherLastName,
        public readonly ?string $motherLastName,
        public readonly string $email,
        public readonly string $role,
        public readonly string $avatar,
    ) {
    }

    public static function fromUser(AppUser $user): self
    {
        return new self(
            $user->id(),
            $user->name(),
            $user->fatherLastName(),
            $user->motherLastName(),
            $user->email(),
            $user->role()->value,
            $user->avatar(),
        );
    }

    public function fullName(): string
    {
        return implode(' ', array_filter([$this->name, $this->fatherLastName, $this->motherLastName]));
    }

    /** @return array<string, string|null> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'fatherLastName' => $this->fatherLastName,
            'motherLastName' => $this->motherLastName,
            'fullName' => $this->fullName(),
            'email' => $this->email,
            'role' => $this->role,
            'avatar' => $this->avatar,
        ];
    }
}
