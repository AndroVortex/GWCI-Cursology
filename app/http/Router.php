<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Enrutador de la API: ruta exacta + método. Los middleware de cada ruta se
 * ejecutan en orden antes del controlador; cada uno puede responder y cortar la cadena.
 */
final class Router
{
    /** @var array<string, array<string, array{handler: callable(Request): JsonResponse, middleware: list<callable(Request, callable): JsonResponse>}>> */
    private array $routes = [];

    /**
     * @param callable(Request): JsonResponse $handler
     * @param list<callable(Request, callable): JsonResponse> $middleware
     */
    public function add(string $method, string $path, callable $handler, array $middleware = []): void
    {
        $this->routes[$path][strtoupper($method)] = ['handler' => $handler, 'middleware' => $middleware];
    }

    public function dispatch(Request $request): JsonResponse
    {
        $byMethod = $this->routes[$request->path] ?? null;
        if ($byMethod === null) {
            return JsonResponse::error(404, 'NOT_FOUND', 'El recurso solicitado no existe.');
        }

        $route = $byMethod[$request->method] ?? null;
        if ($route === null) {
            return JsonResponse::error(405, 'METHOD_NOT_ALLOWED', 'Método no permitido para este recurso.')
                ->withHeader('Allow', implode(', ', array_keys($byMethod)));
        }

        $pipeline = array_reduce(
            array_reverse($route['middleware']),
            static fn (callable $next, callable $middleware): callable
                => static fn (Request $request): JsonResponse => $middleware($request, $next),
            $route['handler'],
        );

        try {
            return $pipeline($request);
        } catch (HttpException $exception) {
            return $exception->toResponse();
        }
    }
}
