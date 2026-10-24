<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Respuesta de la API. Formato:
 *   éxito: {"data": ...}
 *   error: {"error": {"code": "...", "message": "...", "fields": {...}}}
 *   204:   sin cuerpo
 */
final class JsonResponse
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly int $status,
        public readonly mixed $payload,
        private readonly array $headers = [],
    ) {
    }

    public static function data(mixed $data, int $status = 200): self
    {
        return new self($status, ['data' => $data]);
    }

    /**
     * @param array<string, string> $fields
     */
    public static function error(int $status, string $code, string $message, array $fields = []): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return new self($status, ['error' => $error]);
    }

    public static function noContent(): self
    {
        return new self(204, null);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->payload, [...$this->headers, $name => $value]);
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function body(): string
    {
        if ($this->payload === null) {
            return '';
        }

        return json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function send(): void
    {
        $body = $this->body();

        http_response_code($this->status);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        if ($this->payload !== null) {
            header('Content-Type: application/json; charset=utf-8');
        }
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $body;
    }
}
