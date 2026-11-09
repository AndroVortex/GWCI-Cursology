<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\CloudinaryClient;
use App\Integrations\MediaUploadException;

/**
 * Los PDF de los certificados en Cloudinary, como archivos raw "authenticated" en
 * cursology/certificates/u<alumno>/<código>.pdf: solo se descargan con una URL firmada, y la
 * app los entrega al alumno con su nombre (CertificateService::download()).
 */
final class CloudinaryCertificateStore implements CertificateStore
{
    private const FOLDER = 'cursology/certificates';

    public function __construct(private readonly CloudinaryClient $cloudinary)
    {
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

    public function read(string $reference): string
    {
        $asset = MediaService::parseReference($reference)
            ?? throw MediaUploadException::failed('la referencia del certificado no es válida.');

        return $this->cloudinary->fetchPrivate($asset['resource_type'], $asset['type'], $asset['public_id']);
    }
}
