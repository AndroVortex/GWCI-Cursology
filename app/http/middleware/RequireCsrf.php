<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Session;

/** Exige el header X-CSRF-Token con el token de la sesión en peticiones que modifican datos. */
final class RequireCsrf
{
    public function __construct(private readonly Session $session)
    {
    }

    /**
     * @param callable(Request): JsonResponse $next
     */
    public function __invoke(Request $request, callable $next): JsonResponse
    {
        if (!$this->session->isValidCsrf($request->header('X-CSRF-Token'))) {
            return JsonResponse::error(
                403,
                'CSRF_INVALID',
                'La sesión expiró o la petición no es válida. Recarga la página e inténtalo de nuevo.',
            );
        }

        return $next($request);
    }
}
