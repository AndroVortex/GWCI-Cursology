<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Models\Entities\AppUser;
use App\Models\Enums\UserRole;

/** Va después de RequireAuth: solo deja pasar a los roles indicados. */
final class RequireRole
{
    /** @var list<UserRole> */
    private readonly array $roles;

    public function __construct(UserRole ...$roles)
    {
        $this->roles = array_values($roles);
    }

    /**
     * @param callable(Request): JsonResponse $next
     */
    public function __invoke(Request $request, callable $next): JsonResponse
    {
        $user = $request->attribute('user');
        if (!$user instanceof AppUser || !in_array($user->role(), $this->roles, true)) {
            return JsonResponse::error(403, 'FORBIDDEN', 'No tienes permiso para realizar esta acción.');
        }

        return $next($request);
    }
}
