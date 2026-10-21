<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Enums\UserRole;

/** Datos de registro ya validados y normalizados por RegistrationValidator. */
final class RegistrationData
{
    public function __construct(
        public readonly string $name,
        public readonly string $fatherLastName,
        public readonly ?string $motherLastName,
        public readonly string $gender,
        public readonly string $birthday,
        public readonly string $email,
        #[\SensitiveParameter] public readonly string $password,
        public readonly UserRole $role,
        public readonly ?string $photoPath,
        public readonly ?string $photoMimeType,
    ) {
    }

    /** La contraseña nunca aparece en var_dump()/print_r(). */
    public function __debugInfo(): array
    {
        return [...get_object_vars($this), 'password' => '[redacted]'];
    }
}
