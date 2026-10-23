<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Enums\ContentType;
use App\Services\Exceptions\ValidationException;
use App\Services\Validation\UserRules;

/**
 * Validación de servidor del editor de cursos. Los errores usan los nombres de los
 * campos del formulario (titulo, descripcion, portada, video, categorias, precio...).
 */
final class CourseValidator
{
    public const MAX_CATEGORIES = 5;

    private const MAX_TITLE_CHARS = 200;
    private const MAX_DESCRIPTION_CHARS = 5000;
    private const MAX_CERTIFIER_CHARS = 300;
    private const MAX_URL_CHARS = 2048;
    private const MAX_TEXT_CHARS = 20000;

    /** tipo del formulario => [content_type, es video principal, propósito de la subida o null] */
    private const CONTENT_KINDS = [
        'video_principal' => [ContentType::Video, true, 'chapter_video'],
        'documento' => [ContentType::Document, false, 'chapter_document'],
        'imagen' => [ContentType::Image, false, 'chapter_image'],
        'audio' => [ContentType::Audio, false, 'chapter_audio'],
        'archivo' => [ContentType::Archive, false, 'chapter_archive'],
        'enlace' => [ContentType::Link, false, null],
        'texto' => [ContentType::Text, false, null],
    ];

    /**
     * @param array<string, mixed> $input
     * @param bool $creating al crear, la portada y el video promocional son obligatorios
     * @throws ValidationException
     */
    public function validateCourse(array $input, bool $creating): CourseInput
    {
        $title = UserRules::text($input, 'titulo');
        $description = UserRules::text($input, 'descripcion');
        $certifier = UserRules::text($input, 'certificador');
        [$price, $priceError] = self::fullCoursePrice($input);
        [$categoryIds, $categoryError] = self::categoryIds($input['categorias'] ?? null);
        $cover = $input['portada'] ?? null;
        $video = $input['video'] ?? null;

        self::fail([
            'titulo' => self::requiredText($title, self::MAX_TITLE_CHARS, 'Escribe el título del curso.'),
            'descripcion' => self::requiredText($description, self::MAX_DESCRIPTION_CHARS, 'Describe de qué trata el curso.'),
            'certificador' => mb_strlen($certifier) > self::MAX_CERTIFIER_CHARS
                ? sprintf('No puede exceder %d caracteres.', self::MAX_CERTIFIER_CHARS)
                : null,
            'precio' => $priceError,
            'categorias' => $categoryError,
            'portada' => $creating && $cover === null ? 'Sube la portada del curso.' : null,
            'video' => $creating && $video === null ? 'Sube el video promocional del curso.' : null,
        ]);

        return new CourseInput(
            title: $title,
            description: $description,
            certifierName: $certifier === '' ? null : $certifier,
            fullCoursePrice: $price,
            categoryIds: $categoryIds,
            cover: $cover,
            video: $video,
        );
    }

    /**
     * @param array<string, mixed> $input
     * @param bool $creating al crear, el video de introducción es obligatorio
     * @throws ValidationException
     */
    public function validateLevel(array $input, bool $creating): LevelInput
    {
        $title = UserRules::text($input, 'titulo');
        $description = UserRules::text($input, 'descripcion');
        $cost = self::money($input['costo'] ?? null);
        $video = $input['video'] ?? null;

        self::fail([
            'titulo' => self::requiredText($title, self::MAX_TITLE_CHARS, 'Escribe el título del nivel.'),
            'descripcion' => self::requiredText($description, self::MAX_DESCRIPTION_CHARS, 'Describe qué se aprende en este nivel.'),
            'costo' => $cost === null ? 'Escribe el precio del nivel (0 si es gratis).' : null,
            'video' => $creating && $video === null ? 'Sube el video de introducción del nivel.' : null,
        ]);

        return new LevelInput($title, $description, (string) $cost, $video);
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function validateChapterTitle(array $input): string
    {
        $title = UserRules::text($input, 'titulo');
        self::fail(['titulo' => self::requiredText($title, self::MAX_TITLE_CHARS, 'Escribe el título del capítulo.')]);

        return $title;
    }

    /**
     * @param array<string, mixed> $input {tipo, titulo, archivo | url | texto}
     * @throws ValidationException
     */
    public function validateContent(array $input): ContentInput
    {
        $kind = self::CONTENT_KINDS[UserRules::text($input, 'tipo')] ?? null;
        if ($kind === null) {
            throw new ValidationException(['tipo' => 'Elige el tipo de contenido.']);
        }
        [$type, $isMain, $purpose] = $kind;

        $title = UserRules::text($input, 'titulo');
        $errors = ['titulo' => mb_strlen($title) > self::MAX_TITLE_CHARS
            ? sprintf('No puede exceder %d caracteres.', self::MAX_TITLE_CHARS)
            : null];

        $url = null;
        $text = null;
        $upload = null;
        if ($type === ContentType::Link) {
            $url = UserRules::text($input, 'url');
            $errors['url'] = self::urlError($url);
            $errors['titulo'] ??= $title === '' ? 'Escribe un título para el enlace.' : null;
        } elseif ($type === ContentType::Text) {
            $text = UserRules::text($input, 'texto');
            $errors['texto'] = self::requiredText($text, self::MAX_TEXT_CHARS, 'Escribe el texto.');
            $errors['titulo'] ??= $title === '' ? 'Escribe un título para el texto.' : null;
        } else {
            $upload = $input['archivo'] ?? null;
            $errors['archivo'] = $upload === null ? 'Sube el archivo.' : null;
        }

        self::fail($errors);

        return new ContentInput($type, $isMain, $title, $purpose, $upload, $url, $text);
    }

    /** @param array<string, ?string> $errors */
    private static function fail(array $errors): void
    {
        $errors = array_filter($errors, static fn (?string $message): bool => $message !== null);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private static function requiredText(string $value, int $maxChars, string $requiredMessage): ?string
    {
        return match (true) {
            $value === '' => $requiredMessage,
            mb_strlen($value) > $maxChars => sprintf('No puede exceder %d caracteres.', $maxChars),
            default => null,
        };
    }

    /**
     * Dirección para reordenar un nivel o capítulo.
     *
     * @param array<string, mixed> $input {direccion: arriba | abajo}
     * @return 'up'|'down'
     * @throws ValidationException
     */
    public function validateMoveDirection(array $input): string
    {
        return match (UserRules::text($input, 'direccion')) {
            'arriba' => 'up',
            'abajo' => 'down',
            default => throw new ValidationException(['direccion' => 'Indica si se mueve hacia arriba o hacia abajo.']),
        };
    }

    /**
     * Precio del curso completo según el modelo de cobro (ver PricingMode):
     * NULL = por niveles, "0.00" = gratis, importe mayor a 0 = curso completo + niveles.
     *
     * @param array<string, mixed> $input
     * @return array{0: ?string, 1: ?string} [precio, error]
     */
    private static function fullCoursePrice(array $input): array
    {
        return match (PricingMode::tryFrom(UserRules::text($input, 'precio_modo'))) {
            PricingMode::Levels => [null, null],
            PricingMode::Free => ['0.00', null],
            PricingMode::Bundle => match (true) {
                ($price = self::money($input['precio'] ?? null)) === null => [null, 'Escribe el precio del curso completo.'],
                $price === '0.00' => [null, 'Un curso completo de $0 es gratis: elige "Gratis".'],
                default => [$price, null],
            },
            null => [null, 'Elige cómo se cobra el curso.'],
        };
    }

    /** Importe exacto de 0 a 9,999,999,999.99 normalizado a dos decimales; null si no es válido. */
    private static function money(mixed $value): ?string
    {
        if (is_int($value) && $value >= 0) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/D', trim($value), $parts) !== 1) {
            return null;
        }

        return $parts[1] . '.' . str_pad($parts[2] ?? '', 2, '0');
    }

    /** @return array{0: list<string>, 1: ?string} [ids, error] */
    private static function categoryIds(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return [[], 'Elige al menos una categoría.'];
        }

        $ids = [];
        foreach ($value as $id) {
            $id = is_int($id) ? (string) $id : $id;
            if (!is_string($id) || preg_match('/^[1-9][0-9]{0,19}$/D', $id) !== 1) {
                return [[], 'Alguna categoría no es válida.'];
            }
            $ids[$id] = $id;
        }

        return count($ids) > self::MAX_CATEGORIES
            ? [[], sprintf('Elige máximo %d categorías.', self::MAX_CATEGORIES)]
            : [array_values($ids), null];
    }

    private static function urlError(string $url): ?string
    {
        if ($url === '') {
            return 'Escribe la dirección del enlace.';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return mb_strlen($url) > self::MAX_URL_CHARS
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array($scheme, ['http', 'https'], true)
            ? 'Escribe un enlace válido que empiece con http:// o https://.'
            : null;
    }
}
