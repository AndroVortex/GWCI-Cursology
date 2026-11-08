<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Session;
use App\Services\AuthService;

/**
 * Para rutas públicas que cambian un poco con sesión (p. ej. marcar "tu reacción"): si hay una
 * cuenta activa la deja en el atributo 'user'; si no, sigue sin usuario. Nunca bloquea.
 */
final class OptionalAuth
{
    public function __construct(
        private readonly Session $session,
        private readonly AuthService $auth,
    ) {
    }

    /**
     * @param callable(Request): JsonResponse $next
     */
    public function __invoke(Request $request, callable $next): JsonResponse
    {
        $user = $this->auth->userForSession($this->session->userId());

        return $next($user === null ? $request : $request->withAttribute('user', $user));
    }
}
