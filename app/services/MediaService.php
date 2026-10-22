<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\CloudinaryClient;
use App\Integrations\MediaUploadException;
use App\Models\Entities\AppUser;
use App\Services\Exceptions\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Multimedia de los cursos en Cloudinary, subida directa desde el navegador:
 *   1. ticket(): el servidor firma la subida (carpeta, tipo y formatos permitidos).
 *   2. El navegador sube el archivo directo a Cloudinary.
 *   3. confirm(): el servidor verifica la firma de la respuesta y consulta el recurso
 *      con la Admin API (formato y tamaño reales) antes de guardarlo en la BD.
 */
final class MediaService
{
    public const REFERENCE_PREFIX = 'cloudinary:';

    private const MB = 1024 * 1024;
    private const ROOT_FOLDER = 'cursology/courses';
    private const PREVIEW_SECONDS = 3600;

    /** propósito => reglas de la subida */
    private const PURPOSES = [
        'course_cover' => [
            'resource_type' => 'image', 'type' => 'upload', 'folder' => 'covers',
            'formats' => ['jpg', 'jpeg', 'png', 'webp'], 'max_bytes' => 5 * self::MB,
            'label' => 'una imagen JPG, PNG o WEBP',
        ],
        'course_video' => [
            'resource_type' => 'video', 'type' => 'upload', 'folder' => 'promos',
            'formats' => ['mp4', 'webm', 'mov'], 'max_bytes' => 100 * self::MB,
            'label' => 'un video MP4, WEBM o MOV',
        ],
        'level_video' => [
            'resource_type' => 'video', 'type' => 'upload', 'folder' => 'levels',
            'formats' => ['mp4', 'webm', 'mov'], 'max_bytes' => 100 * self::MB,
            'label' => 'un video MP4, WEBM o MOV',
        ],
        'chapter_video' => [
            'resource_type' => 'video', 'type' => 'authenticated', 'folder' => 'content',
            'formats' => ['mp4', 'webm', 'mov'], 'max_bytes' => 100 * self::MB,
            'label' => 'un video MP4, WEBM o MOV',
        ],
        'chapter_document' => [
            'resource_type' => 'raw', 'type' => 'authenticated', 'folder' => 'content',
            'formats' => ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt'], 'max_bytes' => 10 * self::MB,
            'label' => 'un documento PDF, Word, PowerPoint, Excel o TXT',
        ],
        'chapter_image' => [
            'resource_type' => 'image', 'type' => 'authenticated', 'folder' => 'content',
            'formats' => ['jpg', 'jpeg', 'png', 'webp', 'gif'], 'max_bytes' => 10 * self::MB,
            'label' => 'una imagen JPG, PNG, WEBP o GIF',
        ],
        'chapter_audio' => [
            'resource_type' => 'video', 'type' => 'authenticated', 'folder' => 'content',
            'formats' => ['mp3', 'wav', 'm4a', 'ogg'], 'max_bytes' => 50 * self::MB,
            'label' => 'un audio MP3, WAV, M4A u OGG',
        ],
        'chapter_archive' => [
            'resource_type' => 'raw', 'type' => 'authenticated', 'folder' => 'content',
            'formats' => ['zip', 'rar', '7z'], 'max_bytes' => 10 * self::MB,
            'label' => 'un archivo ZIP, RAR o 7Z',
        ],
    ];

    private const MIME_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
        'pdf' => 'application/pdf', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt' => 'text/plain',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg',
        'zip' => 'application/zip', 'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed',
    ];

    public function __construct(private readonly CloudinaryClient $cloudinary)
    {
    }

    /** @return list<string> */
    public static function purposes(): array
    {
        return array_keys(self::PURPOSES);
    }

    /**
     * Ticket firmado para que el navegador suba un archivo de este propósito.
     *
     * @return array{upload_url: string, fields: array<string, string>, resource_type: string, max_bytes: int, formats: list<string>}
     * @throws ValidationException|MediaUploadException
     */
    public function ticket(AppUser $user, string $purpose): array
    {
        $rules = self::PURPOSES[$purpose] ?? throw new ValidationException(['purpose' => 'Tipo de archivo no válido.']);

        $params = ['folder' => $this->folder($user, $rules)];
        if ($rules['resource_type'] !== 'raw') {
            // En raw el formato se valida después con la Admin API (confirm()).
            $params['allowed_formats'] = implode(',', $rules['formats']);
        }
        if ($rules['type'] !== 'upload') {
            $params['type'] = $rules['type'];
        }

        return $this->cloudinary->directUpload($rules['resource_type'], $params) + [
            'resource_type' => $rules['resource_type'],
            'max_bytes' => $rules['max_bytes'],
            'formats' => $rules['formats'],
        ];
    }

    /**
     * Verifica la subida que el navegador dice haber hecho y devuelve sus datos reales.
     * Si no cumple las reglas, borra el archivo de Cloudinary.
     *
     * @param mixed $upload {public_id, version, signature, original_filename} tal como lo manda el navegador
     * @param string $field campo del formulario al que se atribuye el error
     * @throws ValidationException|MediaUploadException
     */
    public function confirm(AppUser $user, string $purpose, mixed $upload, string $field): VerifiedMedia
    {
        $rules = self::PURPOSES[$purpose] ?? throw new InvalidArgumentException(sprintf('Propósito desconocido: %s', $purpose));

        $publicId = is_array($upload) && is_string($upload['public_id'] ?? null) ? $upload['public_id'] : '';
        $version = is_array($upload) && (is_string($upload['version'] ?? null) || is_int($upload['version'] ?? null)) ? (string) $upload['version'] : '';
        $signature = is_array($upload) && is_string($upload['signature'] ?? null) ? $upload['signature'] : '';
        if ($publicId === '' || $version === '' || $signature === '') {
            throw new ValidationException([$field => 'Sube el archivo antes de guardar.']);
        }

        // La carpeta incluye el id del usuario: nadie puede reclamar un archivo subido por otro.
        if (
            !str_starts_with($publicId, $this->folder($user, $rules) . '/')
            || !$this->cloudinary->verifyUploadResponse($publicId, $version, $signature)
        ) {
            throw new ValidationException([$field => 'El archivo subido no es válido. Súbelo de nuevo.']);
        }

        $resource = $this->cloudinary->resource($rules['resource_type'], $rules['type'], $publicId);
        $format = strtolower(is_string($resource['format'] ?? null) ? $resource['format'] : pathinfo($publicId, PATHINFO_EXTENSION));
        $bytes = is_int($resource['bytes'] ?? null) ? $resource['bytes'] : 0;

        if (!in_array($format, $rules['formats'], true) || $bytes <= 0 || $bytes > $rules['max_bytes']) {
            $this->destroyQuietly($publicId, $rules['resource_type'], $rules['type']);
            throw new ValidationException([$field => sprintf(
                'El archivo debe ser %s de máximo %d MB.',
                $rules['label'],
                intdiv($rules['max_bytes'], self::MB),
            )]);
        }

        $duration = is_numeric($resource['duration'] ?? null) ? (int) ceil((float) $resource['duration']) : null;

        return new VerifiedMedia(
            publicId: $publicId,
            resourceType: $rules['resource_type'],
            type: $rules['type'],
            format: $format,
            bytes: $bytes,
            durationSeconds: $duration !== null && $duration > 0 ? $duration : null,
            originalFilename: self::displayName($upload['original_filename'] ?? null, $format),
            mimeType: self::MIME_TYPES[$format] ?? 'application/octet-stream',
            secureUrl: is_string($resource['secure_url'] ?? null) ? $resource['secure_url'] : '',
        );
    }

    /** Borra (sin interrumpir si falla) el archivo de una referencia guardada en la BD. */
    public function discard(VerifiedMedia|string|null $media): void
    {
        $asset = match (true) {
            $media === null => null,
            $media instanceof VerifiedMedia => ['public_id' => $media->publicId, 'resource_type' => $media->resourceType, 'type' => $media->type],
            default => self::parseReference($media) ?? $this->cloudinary->parseDeliveryUrl($media),
        };

        // Solo archivos de los cursos; nunca algo fuera de nuestra carpeta.
        if ($asset !== null && str_starts_with($asset['public_id'], self::ROOT_FOLDER . '/')) {
            $this->destroyQuietly($asset['public_id'], $asset['resource_type'], $asset['type']);
        }
    }

    /**
     * URL para ver un archivo: la pública tal cual; la protegida, temporal y firmada.
     * Llamar solo después de comprobar que el usuario puede verla.
     */
    public function viewUrl(string $reference, ?string $originalFilename): string
    {
        $asset = self::parseReference($reference);
        if ($asset === null) {
            return $reference;
        }

        $format = $asset['resource_type'] === 'raw'
            ? null
            : (pathinfo((string) $originalFilename, PATHINFO_EXTENSION) ?: null);

        return $this->cloudinary->privateDownloadUrl(
            $asset['resource_type'],
            $asset['type'],
            $asset['public_id'],
            $format,
            time() + self::PREVIEW_SECONDS,
        );
    }

    /**
     * Descompone "cloudinary:<type>/<resource_type>/<public_id>".
     *
     * @return array{type: string, resource_type: string, public_id: string}|null
     */
    public static function parseReference(string $reference): ?array
    {
        if (preg_match('#^' . preg_quote(self::REFERENCE_PREFIX, '#') . '(private|authenticated)/(image|video|raw)/(.+)$#D', $reference, $parts) !== 1) {
            return null;
        }

        return ['type' => $parts[1], 'resource_type' => $parts[2], 'public_id' => $parts[3]];
    }

    /** @param array{folder: string} $rules */
    private function folder(AppUser $user, array $rules): string
    {
        return self::ROOT_FOLDER . '/u' . $user->id() . '/' . $rules['folder'];
    }

    /** Nombre para mostrar: el original sin rutas ni caracteres de control, con la extensión real. */
    private static function displayName(mixed $original, string $format): string
    {
        $name = is_string($original) ? basename(str_replace('\\', '/', $original)) : '';
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = mb_substr($base !== '' ? $base : 'archivo', 0, 200);

        return $base . '.' . $format;
    }

    private function destroyQuietly(string $publicId, string $resourceType, string $type): void
    {
        try {
            $this->cloudinary->destroy($publicId, $resourceType, $type);
        } catch (Throwable $exception) {
            error_log(sprintf('[media] no se pudo borrar %s: %s', $publicId, $exception->getMessage()));
        }
    }
}
