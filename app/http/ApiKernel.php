<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Middleware\Cors;
use Closure;
use Throwable;

/**
 * Entrada de la API: aplica CORS a todas las respuestas, despacha la ruta y
 * convierte cualquier error inesperado en un 500 genérico (el detalle va al log).
 */
final class ApiKernel
{
    private readonly Closure $logger;

    /**
     * @param (Closure(string): void)|null $logger
     */
    public function __construct(
        private readonly Router $router,
        private readonly Cors $cors,
        ?Closure $logger = null,
    ) {
        $this->logger = $logger ?? static function (string $message): void {
            error_log($message);
        };
    }

    public function handle(Request $request): JsonResponse
    {
        try {
            return ($this->cors)($request, $this->router->dispatch(...));
        } catch (Throwable $exception) {
            ($this->logger)(sprintf('[api] %s %s: %s', $request->method, $request->path, $exception));

            return JsonResponse::error(500, 'INTERNAL_ERROR', 'Ocurrió un error inesperado. Inténtalo más tarde.');
        }
    }
}
