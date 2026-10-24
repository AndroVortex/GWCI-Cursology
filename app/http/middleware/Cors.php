<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\JsonResponse;
use App\Http\Request;

/**
 * CORS explícito: solo los orígenes de APP_ALLOWED_ORIGINS reciben cabeceras CORS.
 * Sin lista, solo el propio sitio puede leer las respuestas (el navegador bloquea
 * a los demás). Responde las preflight OPTIONS con 204.
 */
final class Cors
{
    private const ALLOWED_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
    private const ALLOWED_HEADERS = 'Accept, Content-Type, X-CSRF-Token';

    /**
     * @param list<string> $allowedOrigins p. ej. ['http://localhost:5173']
     */
    public function __construct(private readonly array $allowedOrigins)
    {
    }

    /**
     * @param callable(Request): JsonResponse $next
     */
    public function __invoke(Request $request, callable $next): JsonResponse
    {
        $response = $request->method === 'OPTIONS' ? JsonResponse::noContent() : $next($request);

        $origin = $request->header('Origin');
        if ($origin === null || !in_array($origin, $this->allowedOrigins, true)) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Methods', self::ALLOWED_METHODS)
            ->withHeader('Access-Control-Allow-Headers', self::ALLOWED_HEADERS)
            ->withHeader('Vary', 'Origin');
    }
}
