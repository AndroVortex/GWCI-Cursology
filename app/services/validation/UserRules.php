<?php

declare(strict_types=1);

namespace App\Services\Validation;

use DateTimeImmutable;
use finfo;

/**
 * Reglas de los datos de una cuenta, compartidas por el registro, el perfil y el
 * script del primer administrador. Cada regla devuelve el mensaje de error o null.
 * Son las mismas que aplica el navegador; el servidor no confía en él.
 */
final class UserRules
{
    public const GENDERS = ['masculino', 'femenino', 'otro'];
    public const MAX_NAME_CHARS = 100;
    public const MAX_EMAIL_CHARS = 254;
    public const MAX_DESCRIPTION_CHARS = 1000;
    // password_hash() con bcrypt ignora lo que pase de 72 bytes.
    public const MAX_PASSWORD_BYTES = 72;
    public const MAX_PHOTO_BYTES = 5 * 1024 * 1024;
    public const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Texto recortado de un campo del formulario; '' si falta, no es texto o no es UTF-8.
     *
     * @param array<string, mixed> $input
     */
    public static function text(array $input, string $field): string
    {
        $value = $input[$field] ?? '';

        return is_string($value) && mb_check_encoding($value, 'UTF-8') ? trim($value) : '';
    }

    public static function nameError(string $value, string $requiredMessage): ?string
    {
        return match (true) {
            $value === '' => $requiredMessage,
            mb_strlen($value) > self::MAX_NAME_CHARS => sprintf('No puede exceder %d caracteres.', self::MAX_NAME_CHARS),
            default => null,
        };
    }

    public static function optionalNameError(string $value): ?string
    {
        return mb_strlen($value) > self::MAX_NAME_CHARS
            ? sprintf('No puede exceder %d caracteres.', self::MAX_NAME_CHARS)
            : null;
    }

    public static function genderError(string $gender): ?string
    {
        return in_array($gender, self::GENDERS, true) ? null : 'Selecciona tu género.';
    }

    public static function birthdayError(string $birthday, DateTimeImmutable $today): ?string
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

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function emailError(string $email): ?string
    {
        return match (true) {
            $email === '' => 'Ingresa tu email.',
            mb_strlen($email) > self::MAX_EMAIL_CHARS, filter_var($email, FILTER_VALIDATE_EMAIL) === false => 'Ingresa un email válido.',
            default => null,
        };
    }

    public static function descriptionError(string $description): ?string
    {
        return mb_strlen($description) > self::MAX_DESCRIPTION_CHARS
            ? sprintf('No puede exceder %d caracteres.', self::MAX_DESCRIPTION_CHARS)
            : null;
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

    /**
     * Foto de perfil subida al servidor: tipo real detectado por su contenido.
     *
     * @param array{tmp_name: string, error: int, size: int, name: string}|null $photo
     * @return array{0: ?string, 1: ?string, 2: ?string} [ruta temporal, tipo MIME, error]
     */
    public static function photo(?array $photo): array
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

    /** Contraseña aleatoria que cumple las reglas (para el primer administrador). */
    public static function generatePassword(int $length = 16): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '#$%&*+-=?@'];
        $all = implode('', $sets);

        $characters = array_map(static fn (string $set): string => $set[random_int(0, strlen($set) - 1)], $sets);
        while (count($characters) < $length) {
            $characters[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }
}
