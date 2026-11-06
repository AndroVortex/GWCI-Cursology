<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\MediaUploadException;

/** Dónde se guardan los PDF de los certificados (Cloudinary; en las pruebas, un doble). */
interface CertificateStore
{
    /**
     * Guarda el PDF y devuelve su referencia para la BD ("cloudinary:authenticated/raw/...").
     * Guardarlo otra vez con el mismo código reemplaza el archivo.
     *
     * @throws MediaUploadException
     */
    public function save(string $userId, string $verificationCode, string $pdf): string;

    /**
     * El PDF guardado. Llamar solo después de comprobar que el certificado es del alumno.
     *
     * @throws MediaUploadException
     */
    public function read(string $reference): string;
}
