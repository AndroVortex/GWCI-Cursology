<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Entities\AppUser;
use App\Services\Exceptions\ValidationException;
use App\Services\Validation\UserRules;
use DateTimeImmutable;

/** Validación de servidor de los formularios del perfil (mismas reglas que el registro). */
final class ProfileValidator
{
    /**
     * @param array<string, mixed> $input JSON del formulario "Información personal"
     * @throws ValidationException
     */
    public function validateProfile(array $input, AppUser $current, DateTimeImmutable $today): ProfileData
    {
        $errors = [];

        $name = UserRules::text($input, 'nombre');
        $errors['nombre'] = UserRules::nameError($name, 'Ingresa tu nombre.');

        $fatherLastName = UserRules::text($input, 'apellido_paterno');
        $errors['apellido_paterno'] = UserRules::nameError($fatherLastName, 'Ingresa tu apellido paterno.');

        $motherLastName = UserRules::text($input, 'apellido_materno');
        $errors['apellido_materno'] = UserRules::optionalNameError($motherLastName);

        $gender = UserRules::text($input, 'genero');
        $errors['genero'] = UserRules::genderError($gender);

        $birthday = UserRules::text($input, 'fecha_nacimiento');
        $errors['fecha_nacimiento'] = UserRules::birthdayError($birthday, $today);

        $description = UserRules::text($input, 'descripcion');
        $errors['descripcion'] = UserRules::descriptionError($description);

        $email = UserRules::normalizeEmail(UserRules::text($input, 'email'));
        $errors['email'] = UserRules::emailError($email);

        $emailChanged = $email !== $current->email();
        $currentPassword = is_string($input['current_password'] ?? null) ? $input['current_password'] : '';
        if ($emailChanged && $currentPassword === '') {
            $errors['current_password'] = 'Escribe tu contraseña actual para cambiar el email.';
        }

        $errors = array_filter($errors, static fn (?string $message): bool => $message !== null);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new ProfileData(
            name: $name,
            fatherLastName: $fatherLastName,
            motherLastName: $motherLastName === '' ? null : $motherLastName,
            gender: $gender,
            birthday: $birthday,
            description: $description === '' ? null : $description,
            email: $email,
            emailChanged: $emailChanged,
            currentPassword: $currentPassword,
        );
    }

    /**
     * @param array<string, mixed> $input {current_password, new_password, confirm_password}
     * @return array{0: string, 1: string} [contraseña actual, contraseña nueva]
     * @throws ValidationException
     */
    public function validatePasswordChange(array $input): array
    {
        $current = is_string($input['current_password'] ?? null) ? $input['current_password'] : '';
        $new = is_string($input['new_password'] ?? null) ? $input['new_password'] : '';
        $confirm = is_string($input['confirm_password'] ?? null) ? $input['confirm_password'] : '';

        $errors = array_filter([
            'current_password' => $current === '' ? 'Escribe tu contraseña actual.' : null,
            'new_password' => UserRules::passwordError($new),
            'confirm_password' => $confirm !== $new ? 'Las contraseñas no coinciden.' : null,
        ]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$current, $new];
    }
}
