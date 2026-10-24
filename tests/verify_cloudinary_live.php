<?php

declare(strict_types=1);

/**
 * Prueba OPCIONAL contra Cloudinary real, con las credenciales CLOUDINARY_* del .env.
 *
 *   php tests/verify_cloudinary_live.php
 *
 * Hace lo mismo que el navegador: pide un ticket, sube el archivo directo a Cloudinary
 * y el servidor lo verifica con la Admin API. También prueba la subida desde el servidor
 * (la del avatar). Usa una imagen diminuta y un documento de texto en
 * cursology/courses/u999999999/ y los borra al terminar. No toca la base de datos.
 */

use App\Integrations\CloudinaryClient;
use App\Integrations\MediaUploadException;
use App\Models\Entities\AppUser;
use App\Services\Exceptions\ValidationException;
use App\Services\MediaService;
use App\Services\VerifiedMedia;

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/app/database/conn.php';

$cloudinary = new CloudinaryClient(
    $ENV['CLOUDINARY_CLOUD_NAME'] ?? '',
    $ENV['CLOUDINARY_API_KEY'] ?? '',
    $ENV['CLOUDINARY_API_SECRET'] ?? '',
);
if (!$cloudinary->isConfigured()) {
    fwrite(STDERR, 'Faltan CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY o CLOUDINARY_API_SECRET en el .env.' . PHP_EOL);
    exit(1);
}

$media = new MediaService($cloudinary);
$timestamp = '2026-10-19 12:00:00.000000';
$user = AppUser::fromRow([
    'id' => '999999999', 'name' => 'Prueba', 'father_last_name' => 'Cloudinary', 'mother_last_name' => null, 'gender' => 'otro',
    'birthday' => '2000-01-01', 'avatar' => 'img/avatar-default.svg', 'email' => 'prueba-cloudinary@cursology.test',
    'password_hash' => 'x', 'role' => 'instructor', 'description' => null, 'account_status' => 'active', 'active' => 1,
    'consecutive_failed_logins' => 0, 'status_changed_at' => $timestamp, 'ban' => 0, 'banned_by' => null, 'banned_at' => null,
    'ban_reason' => null, 'created_at' => $timestamp, 'updated_at' => $timestamp, 'last_login_at' => null,
]);

/**
 * Subida como la hace media-upload.js: los campos del ticket tal cual, más el archivo
 * (o la URL de un archivo remoto, que Cloudinary descarga por su cuenta).
 *
 * @param array{upload_url: string, fields: array<string, string>} $ticket
 * @return array<string, mixed>
 */
function browserUpload(array $ticket, CURLFile|string $file): array
{
    $curl = curl_init($ticket['upload_url']);
    curl_setopt_array($curl, CloudinaryClient::tlsOptions() + [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $ticket['fields'] + ['file' => $file],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $body = curl_exec($curl);
    curl_close($curl);
    $data = is_string($body) ? json_decode($body, true) : null;

    return is_array($data) ? $data : [];
}

/** @return array{status: int, body: string} */
function fetchUrl(string $url): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, CloudinaryClient::tlsOptions() + [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    return ['status' => $status, 'body' => is_string($body) ? $body : ''];
}

/** @param array<string, mixed> $response */
function uploadOf(array $response, string $filename): array
{
    return [
        'public_id' => (string) ($response['public_id'] ?? ''),
        'version' => (string) ($response['version'] ?? ''),
        'signature' => (string) ($response['signature'] ?? ''),
        'original_filename' => $filename,
    ];
}

function exists(CloudinaryClient $cloudinary, VerifiedMedia $file): bool
{
    try {
        $cloudinary->resource($file->resourceType, $file->type, $file->publicId);

        return true;
    } catch (MediaUploadException) {
        return false;
    }
}

$folder = 'cursology/courses/u' . $user->id();
$tempFiles = [];
/** @var list<VerifiedMedia> $uploaded */
$uploaded = [];
$failure = null;

try {
    // -----------------------------------------------------------
    Report::section('Imagen pública (portada del curso)');
    $png = tempnam(sys_get_temp_dir(), 'cld');
    $tempFiles[] = $png;
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));

    $response = browserUpload($media->ticket($user, 'course_cover'), new CURLFile($png, 'image/png', 'portada.png'));
    check(str_starts_with((string) ($response['public_id'] ?? ''), "$folder/covers/"), 'Cloudinary acepta el ticket firmado y respeta la carpeta del usuario');
    $upload = uploadOf($response, 'portada.png');

    $cover = $media->confirm($user, 'course_cover', $upload, 'portada');
    $uploaded[] = $cover;
    check($cover->format === 'png' && $cover->bytes > 0 && $cover->type === 'upload', 'confirm() toma formato y tamaño reales de la Admin API');
    check($cover->reference() === $cover->secureUrl && str_starts_with($cover->secureUrl, 'https://res.cloudinary.com/'), 'se guarda la secure_url');
    check(fetchUrl($cover->secureUrl)['status'] === 200, 'la portada se ve sin firma (es pública)');
    check(
        thrown(ValidationException::class, static fn () => $media->confirm($user, 'course_cover', ['version' => $upload['version'] . '1'] + $upload, 'portada')) !== null,
        'una versión alterada se rechaza (firma de la respuesta)',
    );
    check(
        thrown(ValidationException::class, static fn () => $media->confirm($user, 'course_video', $upload, 'video')) !== null,
        'la imagen no se puede reclamar como video promocional (otra carpeta)',
    );

    // -----------------------------------------------------------
    Report::section('Subida desde el servidor (avatar)');
    $avatar = $cloudinary->uploadImage($png, 'image/png', "$folder/avatars");
    $avatarFile = new VerifiedMedia($avatar->publicId, 'image', 'upload', 'png', 1, null, 'avatar.png', 'image/png', $avatar->secureUrl);
    $uploaded[] = $avatarFile;
    $avatarAsset = $cloudinary->parseDeliveryUrl($avatar->secureUrl);
    check(str_starts_with($avatar->publicId, "$folder/avatars/") && fetchUrl($avatar->secureUrl)['status'] === 200, 'uploadImage() sube la foto y devuelve su secure_url');
    check($avatarAsset !== null && $avatarAsset['public_id'] === $avatar->publicId, 'la URL guardada se puede volver a convertir en su public_id');
    $cloudinary->destroy($avatar->publicId);
    array_pop($uploaded);
    check(!exists($cloudinary, $avatarFile), 'destroy() borra la foto anterior');

    // -----------------------------------------------------------
    Report::section('Documento protegido (authenticated)');
    $content = 'Guía de prueba de Cursology ' . bin2hex(random_bytes(4));
    $txt = tempnam(sys_get_temp_dir(), 'cld');
    $tempFiles[] = $txt;
    file_put_contents($txt, $content);

    $response = browserUpload($media->ticket($user, 'chapter_document'), new CURLFile($txt, 'text/plain', 'guia.txt'));
    check(($response['type'] ?? null) === 'authenticated' && ($response['resource_type'] ?? null) === 'raw', 'se sube como raw authenticated');
    $document = $media->confirm($user, 'chapter_document', uploadOf($response, 'guia.txt'), 'archivo');
    $uploaded[] = $document;
    check($document->format === 'txt' && $document->bytes === strlen($content), 'formato y tamaño reales del documento');
    check(str_starts_with($document->reference(), "cloudinary:authenticated/raw/$folder/content/"), 'se guarda como referencia protegida, no como URL');
    check($document->originalFilename === 'guia.txt' && $document->mimeType === 'text/plain', 'nombre original y MIME según el formato real');

    $unsigned = 'https://res.cloudinary.com/' . rawurlencode($ENV['CLOUDINARY_CLOUD_NAME']) . '/raw/authenticated/' . $document->publicId;
    check(fetchUrl($unsigned)['status'] !== 200, 'sin firma no se puede descargar');
    $download = fetchUrl($media->viewUrl($document->reference(), $document->originalFilename));
    check($download['status'] === 200 && $download['body'] === $content, 'la URL temporal firmada sí lo descarga');

    // -----------------------------------------------------------
    Report::section('Video protegido (duración)');
    // Cloudinary descarga el video de muestra de su cuenta demo: no hace falta un archivo local.
    $response = browserUpload($media->ticket($user, 'chapter_video'), 'https://res.cloudinary.com/demo/video/upload/dog.mp4');
    $video = $media->confirm($user, 'chapter_video', uploadOf($response, 'clase.mp4'), 'archivo');
    $uploaded[] = $video;
    check($video->type === 'authenticated' && $video->format === 'mp4' && $video->mimeType === 'video/mp4', 'video principal protegido en MP4');
    check($video->durationSeconds !== null && $video->durationSeconds > 0, "confirm() lee la duración real ({$video->durationSeconds} s)");

    // -----------------------------------------------------------
    Report::section('Formato no permitido');
    // En raw Cloudinary no filtra formatos: lo hace confirm() y borra el archivo.
    $response = browserUpload($media->ticket($user, 'chapter_archive'), new CURLFile($txt, 'text/plain', 'no-es-zip.txt'));
    $rejectedUpload = uploadOf($response, 'no-es-zip.txt');
    $exception = thrown(ValidationException::class, static fn () => $media->confirm($user, 'chapter_archive', $rejectedUpload, 'archivo'));
    check($exception !== null && str_contains($exception->fields['archivo'] ?? '', 'ZIP'), 'un .txt como archivo comprimido se rechaza');
    $rejected = new VerifiedMedia($rejectedUpload['public_id'], 'raw', 'authenticated', 'txt', 1, null, 'x.txt', 'text/plain', '');
    check(!exists($cloudinary, $rejected), 'y se borra de Cloudinary');

    // -----------------------------------------------------------
    Report::section('Borrado');
    foreach ($uploaded as $file) {
        $media->discard($file->reference());
        check(!exists($cloudinary, $file), "discard() borra {$file->resourceType}/{$file->type}");
    }
    $uploaded = [];
} catch (Throwable $exception) {
    $failure = $exception;
}

// exit() no ejecuta los finally: se limpia antes de salir.
array_map($media->discard(...), $uploaded);
array_map('unlink', $tempFiles);

if ($failure !== null) {
    fwrite(STDERR, sprintf('Error inesperado: %s: %s (%s:%d)%s', $failure::class, $failure->getMessage(), $failure->getFile(), $failure->getLine(), PHP_EOL));
    exit(1);
}

Report::finish();
