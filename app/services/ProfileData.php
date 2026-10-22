<?php

declare(strict_types=1);

namespace App\Services;

/** Datos del perfil ya validados y normalizados por ProfileValidator. */
final class ProfileData
{
    public function __construct(
        public readonly string $name,
        public readonly string $fatherLastName,
        public readonly ?string $motherLastName,
        public readonly string $gender,
        public readonly string $birthday,
        public readonly ?string $description,
        public readonly string $email,
        public readonly bool $emailChanged,
        #[\SensitiveParameter] public readonly string $currentPassword,
    ) {
    }

    /** La contraseña nunca aparece en var_dump()/print_r(). */
    public function __debugInfo(): array
    {
        return [...get_object_vars($this), 'currentPassword' => '[redacted]'];
    }
}
