<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Enums\UserRole;
use App\Services\Exceptions\ValidationException;
use DateTimeImmutable;
use finfo;

/**
 * Validación de servidor del formulario de registro. Aplica las mismas reglas que
 * login.js; el navegador puede saltarse esas validaciones, el servidor no.
 * Los errores usan los nombres de los campos del formulario.
 */
final class RegistrationValidator
{
    public const GENDERS = ['masculino', 'femenino', 'otro'];
    public const MAX_PHOTO_BYTES = 5 * 1024 * 1024;
    public const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const MAX_NAME_CHARS = 100;
    private const MAX_EMAIL_CHARS = 254;
    // password_hash() con bcrypt ignora lo que pase de 72 bytes.
    private const MAX_PASSWORD_BYTES = 72;

    /**
     * @param array<string, mixed> $input
     * @param array{tmp_name: string, error: int, size: int, name: string}|null $photo
     * @throws ValidationException
     */
    public function validate(array $input, ?array $photo, DateTimeImmutable $today): RegistrationData
    {
        $errors = [];

        $name = self::text($input, 'nombre');
        $errors['nombre'] = self::nameError($name, 'Ingresa tu nombre.');

        $fatherLastName = self::text($input, 'apellido_paterno');
        $errors['apellido_paterno'] = self::nameError($fatherLastName, 'Ingresa tu apellido paterno.');

        $motherLastName = self::text($input, 'apellido_materno');
        $errors['apellido_materno'] = mb_strlen($motherLastName) > self::MAX_NAME_CHARS
            ? sprintf('No puede exceder %d caracteres.', self::MAX_NAME_CHARS)
            : null;

        $gender = self::text($input, 'genero');
        $errors['genero'] = in_array($gender, self::GENDERS, true) ? null : 'Selecciona tu género.';

        $birthday = self::text($input, 'fecha_nacimiento');
        $errors['fecha_nacimiento'] = self::birthdayError($birthday, $today);

        $email = mb_strtolower(self::text($input, 'email'));
        $errors['email'] = match (true) {
            $email === '' => 'Ingresa tu email.',
            mb_strlen($email) > self::MAX_EMAIL_CHARS, filter_var($email, FILTER_VALIDATE_EMAIL) === false => 'Ingresa un email válido.',
            default => null,
        };

        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $errors['password'] = self::passwordError($password);

        $role = UserRole::tryFrom(self::text($input, 'rol'));
        $errors['rol'] = in_array($role, [UserRole::Student, UserRole::Instructor], true)
            ? null
            : 'Elige si quieres aprender o enseñar.';

        [$photoPath, $photoMimeType, $errors['foto']] = self::photo($photo);

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
        if ($password === '') {
            return 'Crea una contraseña.';
        }
        if (!mb_check_encoding($password, 'UTF-8')) {
            return 'La contraseña contiene caracteres no válidos.';
        }

        $missing = [];
        if (mb_strlen($password) < 8) {
            $missing[] = 'mínimo 8 caracteres';
        }
        if (preg_match('/\p{Lu}/u', $password) !== 1) {
            $missing[] = 'una letra mayúscula';
        }
        if (preg_match('/\d/', $password) !== 1) {
            $missing[] = 'un número';
        }
        if (preg_match('/[^\p{L}\p{N}\s]/u', $password) !== 1) {
            $missing[] = 'un carácter especial';
        }
        if ($missing !== []) {
            return 'La contraseña necesita: ' . implode(', ', $missing) . '.';
        }

        return strlen($password) > self::MAX_PASSWORD_BYTES
            ? sprintf('La contraseña es demasiado larga (máximo %d caracteres).', self::MAX_PASSWORD_BYTES)
            : null;
    }

    /** @param array<string, mixed> $input */
    private static function text(array $input, string $field): string
    {
        $value = $input[$field] ?? '';

        return is_string($value) && mb_check_encoding($value, 'UTF-8') ? trim($value) : '';
    }

    private static function nameError(string $value, string $requiredMessage): ?string
    {
        return match (true) {
            $value === '' => $requiredMessage,
            mb_strlen($value) > self::MAX_NAME_CHARS => sprintf('No puede exceder %d caracteres.', self::MAX_NAME_CHARS),
            default => null,
        };
    }

    private static function birthdayError(string $birthday, DateTimeImmutable $today): ?string
    {
        if ($birthday === '') {
            return 'Ingresa tu fecha de nacimiento.';
        }
        if (
            preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $birthday, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || $birthday < '1900-01-01'
        ) {
            return 'Ingresa una fecha válida.';
        }

        return $birthday > $today->format('Y-m-d') ? 'La fecha no puede ser futura.' : null;
    }

    /**
     * @param array{tmp_name: string, error: int, size: int, name: string}|null $photo
     * @return array{0: ?string, 1: ?string, 2: ?string} [ruta temporal, tipo MIME, error]
     */
    private static function photo(?array $photo): array
    {
        if ($photo === null) {
            return [null, null, null];
        }

        $tooBig = sprintf('La foto no debe exceder %d MB.', self::MAX_PHOTO_BYTES / 1024 / 1024);
        if (in_array($photo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return [null, null, $tooBig];
        }
        if ($photo['error'] !== UPLOAD_ERR_OK || !is_file($photo['tmp_name'])) {
            return [null, null, 'No se pudo recibir la foto. Inténtalo de nuevo.'];
        }
        if (filesize($photo['tmp_name']) > self::MAX_PHOTO_BYTES) {
            return [null, null, $tooBig];
        }

        // El tipo se detecta por el contenido del archivo, no por la extensión ni por lo que diga el navegador.
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($photo['tmp_name']);
        if (!in_array($mimeType, self::PHOTO_TYPES, true) || @getimagesize($photo['tmp_name']) === false) {
            return [null, null, 'La foto debe ser una imagen JPG, PNG o WEBP.'];
        }

        return [$photo['tmp_name'], $mimeType, null];
    }
}
