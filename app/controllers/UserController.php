<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Session;
use App\Models\Dto\UserResponse;
use App\Models\Entities\AppUser;
use App\Services\ProfileService;

/**
 * Endpoints /api/users/me*: el perfil del usuario de la sesión (RequireAuth lo deja
 * en la petición). Los errores de validación y de negocio los responde el router.
 */
final class UserController
{
    public function __construct(
        private readonly ProfileService $profiles,
        private readonly Session $session,
    ) {
    }

    /** PATCH /api/users/me (application/json) */
    public function updateProfile(Request $request): JsonResponse
    {
        $updated = $this->profiles->updateProfile($this->user($request), $request->json());

        return JsonResponse::data(['user' => UserResponse::fromUser($updated)]);
    }

    /** POST /api/users/me/avatar (multipart/form-data, campo "foto") */
    public function updateAvatar(Request $request): JsonResponse
    {
        if (!$request->isMultipart()) {
            return JsonResponse::error(415, 'UNSUPPORTED_MEDIA_TYPE', 'Envía la foto como multipart/form-data.');
        }

        $updated = $this->profiles->changeAvatar($this->user($request), $request->file('foto'));

        return JsonResponse::data(['user' => UserResponse::fromUser($updated)]);
    }

    /** DELETE /api/users/me/avatar */
    public function removeAvatar(Request $request): JsonResponse
    {
        $updated = $this->profiles->removeAvatar($this->user($request));

        return JsonResponse::data(['user' => UserResponse::fromUser($updated)]);
    }

    /** PUT /api/users/me/password (application/json) */
    public function changePassword(Request $request): JsonResponse
    {
        $this->profiles->changePassword($this->user($request), $request->json());
        $this->session->regenerate();

        return JsonResponse::noContent();
    }

    private function user(Request $request): AppUser
    {
        /** @var AppUser */
        return $request->attribute('user');
    }
}
