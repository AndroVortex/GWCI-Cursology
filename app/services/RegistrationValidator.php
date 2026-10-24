<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Enums\UserRole;
use App\Services\Exceptions\ValidationException;
use App\Services\Validation\UserRules;
use DateTimeImmutable;

/**
 * Validación de servidor del formulario de registro. Aplica las mismas reglas que
 * login.js; el navegador puede saltarse esas validaciones, el servidor no.
 * Los errores usan los nombres de los campos del formulario.
 */
final class RegistrationValidator
{
    public const GENDERS = UserRules::GENDERS;
    public const MAX_PHOTO_BYTES = UserRules::MAX_PHOTO_BYTES;
    public const PHOTO_TYPES = UserRules::PHOTO_TYPES;

    /**
     * @param array<string, mixed> $input
     * @param array{tmp_name: string, error: int, size: int, name: string}|null $photo
     * @throws ValidationException
     */
    public function validate(array $input, ?array $photo, DateTimeImmutable $today): RegistrationData
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

        $email = UserRules::normalizeEmail(UserRules::text($input, 'email'));
        $errors['email'] = UserRules::emailError($email);

        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $errors['password'] = UserRules::passwordError($password);

        $role = UserRole::tryFrom(UserRules::text($input, 'rol'));
        $errors['rol'] = in_array($role, [UserRole::Student, UserRole::Instructor], true)
            ? null
            : 'Elige si quieres aprender o enseñar.';

        [$photoPath, $photoMimeType, $errors['foto']] = UserRules::photo($photo);

        $errors = array_filter($errors, static fn (?string $message): bool => $message !== null);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new RegistrationData(
            name: $name,
            fatherLastName: $fatherLastName,
            motherLastName: $motherLastName === '' ? null : $motherLastName,
            gender: $gender,
            birthday: $birthday,
            email: $email,
            password: $password,
            role: $role,
            photoPath: $photoPath,
            photoMimeType: $photoMimeType,
        );
    }

    /** Mismas reglas que la lista de requisitos del formulario. */
    public static function passwordError(string $password): ?string
    {
        return UserRules::passwordError($password);
    }
}
