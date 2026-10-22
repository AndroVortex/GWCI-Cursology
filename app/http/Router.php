<?php

declare(strict_types=1);

namespace App\Http;

use App\Integrations\MediaUploadException;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;

/**
 * Enrutador de la API: ruta + método. Una ruta puede tener parámetros numéricos
 * como `/api/instructor/levels/{id}`; su valor queda en $request->attribute('id').
 * Los middleware de cada ruta se ejecutan en orden antes del controlador; cada uno
 * puede responder y cortar la cadena.
 */
final class Router
{
    /** @var array<string, array<string, array{handler: callable(Request): JsonResponse, middleware: list<callable(Request, callable): JsonResponse>}>> */
    private array $routes = [];

    /** @var array<string, string> plantilla de ruta => expresión regular */
    private array $patterns = [];

    /**
     * @param callable(Request): JsonResponse $handler
     * @param list<callable(Request, callable): JsonResponse> $middleware
     */
    public function add(string $method, string $path, callable $handler, array $middleware = []): void
    {
        $this->routes[$path][strtoupper($method)] = ['handler' => $handler, 'middleware' => $middleware];

        if (str_contains($path, '{')) {
            $this->patterns[$path] = self::compile($path);
        }
    }

    public function dispatch(Request $request): JsonResponse
    {
        [$byMethod, $params] = $this->match($request->path);
        if ($byMethod === null) {
            return JsonResponse::error(404, 'NOT_FOUND', 'El recurso solicitado no existe.');
        }

        $route = $byMethod[$request->method] ?? null;
        if ($route === null) {
            return JsonResponse::error(405, 'METHOD_NOT_ALLOWED', 'Método no permitido para este recurso.')
                ->withHeader('Allow', implode(', ', array_keys($byMethod)));
        }

        foreach ($params as $name => $value) {
            $request = $request->withAttribute($name, $value);
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
        } catch (ValidationException $exception) {
            return JsonResponse::error(422, 'VALIDATION_FAILED', $exception->getMessage(), $exception->fields);
        } catch (BusinessRuleException $exception) {
            return JsonResponse::error($exception->status, $exception->errorCode, $exception->getMessage(), $exception->fields());
        } catch (MediaUploadException $exception) {
            error_log('[media] ' . $exception->getMessage());

            return $exception->notConfigured
                ? JsonResponse::error(503, 'MEDIA_UNAVAILABLE', 'La carga de archivos no está disponible por el momento.')
                : JsonResponse::error(502, 'MEDIA_UPLOAD_FAILED', 'No se pudo procesar el archivo. Inténtalo de nuevo.');
        }
    }

    /**
     * @return array{0: array<string, array{handler: callable, middleware: list<callable>}>|null, 1: array<string, string>}
     */
    private function match(string $path): array
    {
        if (isset($this->routes[$path]) && !isset($this->patterns[$path])) {
            return [$this->routes[$path], []];
        }

        foreach ($this->patterns as $template => $regex) {
            if (preg_match($regex, $path, $matches) === 1) {
                return [$this->routes[$template], array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY)];
            }
        }

        return [null, []];
    }

    /** `{nombre}` acepta solo identificadores numéricos (BIGINT UNSIGNED >= 1). */
    private static function compile(string $template): string
    {
        $segments = array_map(
            static fn (string $segment): string => preg_match('/^\{([a-z_]+)\}$/D', $segment, $m) === 1
                ? '(?P<' . $m[1] . '>[1-9][0-9]{0,19})'
                : preg_quote($segment, '#'),
            explode('/', $template),
        );

        return '#^' . implode('/', $segments) . '$#D';
    }
}
