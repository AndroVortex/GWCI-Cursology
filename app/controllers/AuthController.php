<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Session;
use App\Integrations\MediaUploadException;
use App\Models\Dto\UserResponse;
use App\Models\Entities\AppUser;
use App\Services\AuthService;
use App\Services\Exceptions\AccountLockedException;
use App\Services\Exceptions\AccountUnavailableException;
use App\Services\Exceptions\EmailTakenException;
use App\Services\Exceptions\InvalidCredentialsException;
use App\Services\Exceptions\ValidationException;

/** Endpoints /api/auth/*: traduce los casos de uso de AuthService a JSON y códigos HTTP. */
final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Session $session,
    ) {
    }

    /** GET /api/auth/csrf — token para clientes que no cargan la página (p. ej. Postman). */
    public function csrf(Request $request): JsonResponse
    {
        return JsonResponse::data(['token' => $this->session->csrfToken()]);
    }

    /** POST /api/auth/register (multipart/form-data) */
    public function register(Request $request): JsonResponse
    {
        if (!$request->isMultipart()) {
            return JsonResponse::error(415, 'UNSUPPORTED_MEDIA_TYPE', 'Envía el formulario como multipart/form-data.');
        }

        try {
            $user = $this->auth->register($request->post(), $request->file('foto'));
        } catch (ValidationException $exception) {
            return JsonResponse::error(422, 'VALIDATION_FAILED', $exception->getMessage(), $exception->fields);
        } catch (EmailTakenException $exception) {
            return JsonResponse::error(409, 'EMAIL_TAKEN', $exception->getMessage(), ['email' => $exception->getMessage()]);
        } catch (MediaUploadException $exception) {
            error_log('[auth] carga de avatar: ' . $exception->getMessage());

            return $exception->notConfigured
                ? JsonResponse::error(503, 'MEDIA_UNAVAILABLE', 'Por ahora no se pueden subir fotos. Regístrate sin foto o inténtalo más tarde.', [
                    'foto' => 'La carga de fotos no está disponible por el momento.',
                ])
                : JsonResponse::error(502, 'MEDIA_UPLOAD_FAILED', 'No se pudo subir la foto. Inténtalo de nuevo.', [
                    'foto' => 'No se pudo subir la foto.',
                ]);
        }

        return $this->startSession($user, 201);
    }

    /** POST /api/auth/login (application/json: {"email", "password"}) */
    public function login(Request $request): JsonResponse
    {
        $body = $request->json();

        try {
            $user = $this->auth->login($body['email'] ?? null, $body['password'] ?? null);
        } catch (ValidationException $exception) {
            return JsonResponse::error(422, 'VALIDATION_FAILED', $exception->getMessage(), $exception->fields);
        } catch (InvalidCredentialsException $exception) {
            return JsonResponse::error(401, 'INVALID_CREDENTIALS', $exception->getMessage());
        } catch (AccountLockedException $exception) {
            return JsonResponse::error(423, 'ACCOUNT_LOCKED', $exception->getMessage());
        } catch (AccountUnavailableException $exception) {
            return JsonResponse::error(403, 'ACCOUNT_UNAVAILABLE', $exception->getMessage());
        }

        return $this->startSession($user, 200);
    }

    /** POST /api/auth/logout */
    public function logout(Request $request): JsonResponse
    {
        $this->session->logout();

        return JsonResponse::noContent();
    }

    /** GET /api/auth/me (requiere sesión: RequireAuth deja el usuario en la petición) */
    public function me(Request $request): JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->attribute('user');

        return JsonResponse::data(['user' => UserResponse::fromUser($user)]);
    }

    private function startSession(AppUser $user, int $status): JsonResponse
    {
        $this->session->login($user->id());

        return JsonResponse::data(['user' => UserResponse::fromUser($user)], $status);
    }
}
