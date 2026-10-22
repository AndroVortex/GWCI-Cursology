<?php

declare(strict_types=1);

namespace App\Integrations;

use CURLFile;

/**
 * Cliente mínimo de Cloudinary sin SDK (Upload API firmada + Admin API).
 *
 * La firma es SHA-1 de los parámetros ordenados alfabéticamente ("a=1&b=2") seguidos
 * del API secret; file, api_key, resource_type y cloud_name no se firman.
 * El secret nunca sale del servidor.
 */
final class CloudinaryClient
{
    private const API_BASE = 'https://api.cloudinary.com/v1_1/';
    private const DELIVERY_BASE = 'https://res.cloudinary.com/';

    public function __construct(
        private readonly string $cloudName,
        private readonly string $apiKey,
        #[\SensitiveParameter] private readonly string $apiSecret,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->cloudName !== '' && $this->apiKey !== '' && $this->apiSecret !== '';
    }

    /**
     * Sube desde el servidor una imagen pública (avatar) y devuelve su URL HTTPS.
     *
     * @throws MediaUploadException
     */
    public function uploadImage(string $filePath, string $mimeType, string $folder): UploadedMedia
    {
        $params = ['folder' => $folder, 'timestamp' => (string) time()];
        $response = $this->post('image/upload', $params, ['file' => new CURLFile($filePath, $mimeType)]);

        $secureUrl = $response['secure_url'] ?? null;
        $publicId = $response['public_id'] ?? null;
        if (!is_string($secureUrl) || !is_string($publicId) || !str_starts_with($secureUrl, 'https://')) {
            throw MediaUploadException::failed('la respuesta de la carga no trae secure_url/public_id.');
        }

        return new UploadedMedia($secureUrl, $publicId);
    }

    /**
     * Campos firmados para que el NAVEGADOR suba el archivo directo a Cloudinary
     * (los videos no pasan por PHP). Se deben enviar tal cual, más el archivo.
     *
     * @param array<string, string> $params p. ej. folder, allowed_formats, type
     * @return array{upload_url: string, fields: array<string, string>}
     * @throws MediaUploadException
     */
    public function directUpload(string $resourceType, array $params): array
    {
        $this->assertConfigured();
        $params['timestamp'] = (string) time();

        return [
            'upload_url' => self::API_BASE . rawurlencode($this->cloudName) . '/' . $resourceType . '/upload',
            'fields' => $params + ['api_key' => $this->apiKey, 'signature' => $this->sign($params)],
        ];
    }

    /**
     * Verifica la firma que Cloudinary devuelve al terminar una subida:
     * SHA-1 de "public_id=<id>&version=<versión>" seguido del API secret.
     */
    public function verifyUploadResponse(string $publicId, string $version, string $signature): bool
    {
        return $this->isConfigured()
            && hash_equals(sha1('public_id=' . $publicId . '&version=' . $version . $this->apiSecret), $signature);
    }

    /**
     * Datos reales del recurso según la Admin API (bytes, formato, duración...).
     *
     * @return array<string, mixed>
     * @throws MediaUploadException
     */
    public function resource(string $resourceType, string $type, string $publicId): array
    {
        $path = 'resources/' . $resourceType . '/' . $type . '/'
            . implode('/', array_map('rawurlencode', explode('/', $publicId)));

        return $this->request('GET', $path);
    }

    /** @throws MediaUploadException */
    public function destroy(string $publicId, string $resourceType = 'image', string $type = 'upload'): void
    {
        $this->post($resourceType . '/destroy', [
            'public_id' => $publicId,
            'type' => $type,
            'timestamp' => (string) time(),
        ]);
    }

    /**
     * URL temporal y firmada para un recurso protegido (private/authenticated).
     * Se genera en el servidor solo después de comprobar que el usuario puede verlo.
     */
    public function privateDownloadUrl(string $resourceType, string $type, string $publicId, ?string $format, int $expiresAt): string
    {
        $params = array_filter([
            'public_id' => $publicId,
            'format' => $format ?? '',
            'type' => $type,
            'expires_at' => (string) $expiresAt,
            'timestamp' => (string) time(),
        ], static fn (string $value): bool => $value !== '');

        $query = $params + ['api_key' => $this->apiKey, 'signature' => $this->sign($params)];

        return self::API_BASE . rawurlencode($this->cloudName) . '/' . $resourceType . '/download?' . http_build_query($query);
    }

    /**
     * Descompone una URL de entrega de ESTA cuenta (res.cloudinary.com/<cloud>/...).
     *
     * @return array{resource_type: string, type: string, public_id: string}|null
     */
    public function parseDeliveryUrl(string $url): ?array
    {
        $pattern = '#^' . preg_quote(self::DELIVERY_BASE . $this->cloudName . '/', '#')
            . '(image|video|raw)/(upload|private|authenticated)/(?:v[0-9]+/)?(.+)$#D';
        if ($this->cloudName === '' || preg_match($pattern, $url, $parts) !== 1) {
            return null;
        }

        // En image y video la extensión es el formato de entrega, no parte del public_id.
        $publicId = $parts[1] === 'raw' ? $parts[3] : (string) preg_replace('/\.[A-Za-z0-9]+$/', '', $parts[3]);

        return ['resource_type' => $parts[1], 'type' => $parts[2], 'public_id' => rawurldecode($publicId)];
    }

    /**
     * @param array<string, string> $params parámetros que se firman
     */
    public function sign(array $params): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value !== '') {
                $pairs[] = $key . '=' . $value;
            }
        }

        return sha1(implode('&', $pairs) . $this->apiSecret);
    }

    /** Sin credenciales en var_dump()/print_r(). */
    public function __debugInfo(): array
    {
        return ['cloudName' => $this->cloudName, 'configured' => $this->isConfigured()];
    }

    /** @throws MediaUploadException */
    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw MediaUploadException::missingConfiguration();
        }
    }

    /**
     * Petición firmada a la Upload API.
     *
     * @param array<string, string> $params
     * @param array<string, CURLFile> $files
     * @return array<string, mixed>
     * @throws MediaUploadException
     */
    private function post(string $endpoint, array $params, array $files = []): array
    {
        $this->assertConfigured();

        $fields = $params + ['api_key' => $this->apiKey, 'signature' => $this->sign($params)] + $files;

        return $this->send(self::API_BASE . rawurlencode($this->cloudName) . '/' . $endpoint, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
        ]);
    }

    /**
     * Petición a la Admin API (autenticación básica con API key y secret).
     *
     * @return array<string, mixed>
     * @throws MediaUploadException
     */
    private function request(string $method, string $path): array
    {
        $this->assertConfigured();

        return $this->send(self::API_BASE . rawurlencode($this->cloudName) . '/' . $path, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => $this->apiKey . ':' . $this->apiSecret,
        ]);
    }

    /**
     * Opciones TLS de curl. En Windows también se confía en el almacén de certificados del
     * sistema: un proxy que inspecciona HTTPS (p. ej. Zscaler) instala ahí su raíz, que no
     * viene en el cacert.pem de PHP. La verificación del certificado sigue activa.
     *
     * @return array<int, mixed>
     */
    public static function tlsOptions(): array
    {
        return PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')
            ? [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA]
            : [];
    }

    /**
     * @param array<int, mixed> $options
     * @return array<string, mixed>
     * @throws MediaUploadException
     */
    private function send(string $url, array $options): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, $options + self::tlsOptions() + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if (!is_string($body)) {
            $hint = str_contains($curlError, 'SSL certificate')
                ? ' Revisa si un proxy o antivirus intercepta HTTPS y si PHP confía en su certificado raíz (curl.cainfo).'
                : '';
            throw MediaUploadException::failed('no hubo respuesta (' . $curlError . ').' . $hint);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw MediaUploadException::failed(sprintf('respuesta no JSON (HTTP %d).', $status));
        }
        if ($status >= 400) {
            $message = is_string($data['error']['message'] ?? null) ? $data['error']['message'] : 'sin detalle';
            throw MediaUploadException::failed(sprintf('HTTP %d: %s', $status, $message));
        }

        return $data;
    }
}
