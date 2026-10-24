<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\CloudinaryClient;
use App\Integrations\MediaUploadException;

/**
 * Los PDF de los certificados en Cloudinary, como archivos raw "authenticated" en
 * cursology/certificates/u<alumno>/<código>.pdf: solo se descargan con una URL firmada.
 */
final class CloudinaryCertificateStore implements CertificateStore
{
    private const FOLDER = 'cursology/certificates';

    public function __construct(
        private readonly CloudinaryClient $cloudinary,
        private readonly MediaService $media,
    ) {
    }

    public function save(string $userId, string $verificationCode, string $pdf): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cert');
        if ($path === false || file_put_contents($path, $pdf) !== strlen($pdf)) {
            throw MediaUploadException::failed('no se pudo escribir el PDF temporal.');
        }

        try {
            $publicId = $this->cloudinary->uploadFile($path, 'application/pdf', 'raw', 'authenticated', self::FOLDER . '/u' . $userId, $verificationCode . '.pdf');
        } finally {
            @unlink($path);
        }

        return MediaService::REFERENCE_PREFIX . 'authenticated/raw/' . $publicId;
    }

    public function downloadUrl(string $reference): string
    {
        return $this->media->viewUrl($reference, null);
    }
}
