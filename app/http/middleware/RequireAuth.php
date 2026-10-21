<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Session;
use App\Services\AuthService;

/**
 * Exige una sesión cuya cuenta siga activa y sin ban. Vuelve a consultar el estado
 * en cada petición, así que un bloqueo o ban corta las sesiones ya abiertas.
 * Deja el usuario en el atributo 'user' de la petición.
 */
final class RequireAuth
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
        $userId = $this->session->userId();
        $user = $this->auth->userForSession($userId);

        if ($user === null) {
            if ($userId !== null) {
                $this->session->clearUser();
            }

            return JsonResponse::error(401, 'UNAUTHENTICATED', 'Inicia sesión para continuar.');
        }

        return $next($request->withAttribute('user', $user));
    }
}
