<?php

declare(strict_types=1);

namespace App\Http;

use JsonException;

/**
 * Petición HTTP inmutable. $path es relativo a la carpeta donde vive public/index.php,
 * así que funciona igual con `php -S` que con Apache en una subcarpeta.
 */
final class Request
{
    /**
     * @param array<string, string> $headers nombres en minúsculas
     * @param array<string, mixed> $post
     * @param array<string, array{tmp_name: string, error: int, size: int, name: string}> $files
     * @param array<string, mixed> $attributes datos que agregan los middleware (p. ej. el usuario)
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $headers,
        private readonly string $body,
        private readonly array $post,
        private readonly array $files,
        private readonly array $attributes = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? rawurldecode($path) : '/';
        $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }
        if (str_starts_with($path, '/index.php/')) {
            $path = substr($path, strlen('/index.php'));
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with((string) $key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $headers[$name] = $_SERVER[$key];
            }
        }

        $isMultipart = str_starts_with(strtolower($headers['content-type'] ?? ''), 'multipart/form-data');
        $body = $isMultipart ? '' : (string) file_get_contents('php://input');

        $files = [];
        foreach ($_FILES as $name => $file) {
            if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || !is_int($file['error'] ?? null)) {
                continue;
            }
            if ($file['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($file['error'] === UPLOAD_ERR_OK && !is_uploaded_file($file['tmp_name'])) {
                continue;
            }
            $files[(string) $name] = [
                'tmp_name' => $file['tmp_name'],
                'error' => $file['error'],
                'size' => (int) ($file['size'] ?? 0),
                'name' => (string) ($file['name'] ?? ''),
            ];
        }

        return new self($method, $path, $headers, $body, $_POST, $files);
    }

    /**
     * Construye una petición sin variables globales (pruebas o llamadas internas).
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $post
     * @param array<string, array{tmp_name: string, error: int, size: int, name: string}> $files
     */
    public static function create(
        string $method,
        string $path,
        array $headers = [],
        string $body = '',
        array $post = [],
        array $files = [],
    ): self {
        return new self(strtoupper($method), $path, array_change_key_case($headers, CASE_LOWER), $body, $post, $files);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isApi(): bool
    {
        return $this->path === '/api' || str_starts_with($this->path, '/api/');
    }

    /** Tipo de contenido sin parámetros (charset, boundary), en minúsculas. */
    public function contentType(): string
    {
        return strtolower(trim(explode(';', $this->header('content-type') ?? '')[0]));
    }

    public function isMultipart(): bool
    {
        return $this->contentType() === 'multipart/form-data';
    }

    /**
     * Cuerpo JSON como objeto (arreglo asociativo).
     *
     * @return array<string, mixed>
     * @throws HttpException 415 si no es application/json, 400 si no es un objeto JSON válido.
     */
    public function json(): array
    {
        if ($this->contentType() !== 'application/json') {
            throw new HttpException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Envía el cuerpo como application/json.');
        }

        try {
            $data = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'INVALID_JSON', 'El cuerpo de la petición no es JSON válido.');
        }

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new HttpException(400, 'INVALID_JSON', 'Se esperaba un objeto JSON.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function post(): array
    {
        return $this->post;
    }

    /** @return array{tmp_name: string, error: int, size: int, name: string}|null */
    public function file(string $name): ?array
    {
        return $this->files[$name] ?? null;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->headers,
            $this->body,
            $this->post,
            $this->files,
            [...$this->attributes, $name => $value],
        );
    }

    public function attribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}
