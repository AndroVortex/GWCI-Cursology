<?php

declare(strict_types=1);

namespace App\Integrations;

/** Opciones TLS de curl que comparten las integraciones (Cloudinary, PayPal). */
final class Tls
{
    /**
     * En Windows curl también confía en el almacén de certificados del sistema: un proxy
     * que inspecciona HTTPS (p. ej. Zscaler) instala ahí su raíz, que no viene en el
     * cacert.pem de PHP. La verificación del certificado sigue activa.
     *
     * @return array<int, mixed>
     */
    public static function curlOptions(): array
    {
        return PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')
            ? [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA]
            : [];
    }

    /** Pista para el log cuando curl falla por el certificado. */
    public static function hint(string $curlError): string
    {
        return str_contains($curlError, 'SSL certificate')
            ? ' Revisa si un proxy o antivirus intercepta HTTPS y si PHP confía en su certificado raíz (curl.cainfo).'
            : '';
    }
}
