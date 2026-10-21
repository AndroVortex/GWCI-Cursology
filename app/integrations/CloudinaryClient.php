<?php

declare(strict_types=1);

namespace App\Integrations;

use CURLFile;

/**
 * Cliente mínimo de la Upload API de Cloudinary con peticiones firmadas (sin SDK).
 *
 * La firma es SHA-1 de los parámetros ordenados alfabéticamente ("a=1&b=2") seguidos
 * del API secret; file, api_key, resource_type y cloud_name no se firman.
 * El secret nunca sale del servidor.
 */
final class CloudinaryClient
{
    private const API_BASE = 'https://api.cloudinary.com/v1_1/';

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
     * Sube una imagen pública (avatar, portada) y devuelve su URL HTTPS.
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

    /** @throws MediaUploadException */
    public function destroy(string $publicId): void
    {
        $this->post('image/destroy', ['public_id' => $publicId, 'timestamp' => (string) time()]);
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

    /**
     * @param array<string, string> $params
     * @param array<string, CURLFile> $files
     * @return array<string, mixed>
     * @throws MediaUploadException
     */
    private function post(string $endpoint, array $params, array $files = []): array
    {
        if (!$this->isConfigured()) {
            throw MediaUploadException::missingConfiguration();
        }

        $fields = $params + ['api_key' => $this->apiKey, 'signature' => $this->sign($params)] + $files;

        $curl = curl_init(self::API_BASE . rawurlencode($this->cloudName) . '/' . $endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if (!is_string($body)) {
            throw MediaUploadException::failed('no hubo respuesta (' . $curlError . ').');
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
