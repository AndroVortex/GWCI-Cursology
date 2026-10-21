<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/** Error que se responde tal cual al cliente con su código HTTP. */
final class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $fields errores por campo
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    public function toResponse(): JsonResponse
    {
        return JsonResponse::error($this->status, $this->errorCode, $this->getMessage(), $this->fields);
    }
}
