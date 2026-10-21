<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\CloudinaryClient;
use App\Integrations\MediaUploadException;
use App\Integrations\UploadedMedia;
use App\Models\Entities\AppUser;
use App\Models\Enums\AccountStatus;
use App\Models\Enums\LoginOutcome;
use App\Repositories\UserRepository;
use App\Services\Exceptions\AccountLockedException;
use App\Services\Exceptions\AccountUnavailableException;
use App\Services\Exceptions\EmailTakenException;
use App\Services\Exceptions\InvalidCredentialsException;
use App\Services\Exceptions\ValidationException;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Casos de uso de registro, login y validación de la sesión. */
final class AuthService
{
    /** Avatar para quien se registra sin foto (archivo de public/). */
    public const DEFAULT_AVATAR = 'img/avatar-default.svg';

    private const AVATAR_FOLDER = 'cursology/avatars';
    private const LOCAL_TIME_ZONE = 'America/Monterrey';

    // Hash de una cadena aleatoria ya descartada: verificar contra él cuando el email
    // no existe hace que la respuesta tarde lo mismo que con una contraseña incorrecta.
    private const TIMING_HASH = '$2y$10$gKXYAHNE7cLGR5JjuPFL7O9EI2LndtUrlp8kzFNJKHcqs7eMJ2ii6';

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly RegistrationValidator $validator,
        private readonly CloudinaryClient $media,
        private readonly ?Closure $clock = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input campos del formulario
     * @param array{tmp_name: string, error: int, size: int, name: string}|null $photo
     * @throws ValidationException|EmailTakenException|MediaUploadException
     */
    public function register(array $input, ?array $photo): AppUser
    {
        $data = $this->validator->validate($input, $photo, $this->now());

        // Evita subir la foto si el email ya está ocupado; el SP vuelve a comprobarlo.
        if ($this->users->findForLogin($data->email) !== null) {
            throw new EmailTakenException();
        }

        $uploaded = $data->photoPath === null
            ? null
            : $this->media->uploadImage($data->photoPath, (string) $data->photoMimeType, self::AVATAR_FOLDER);

        try {
            return $this->users->register(
                $data,
                password_hash($data->password, PASSWORD_DEFAULT),
                $uploaded?->secureUrl ?? self::DEFAULT_AVATAR,
            );
        } catch (Throwable $exception) {
            if ($uploaded !== null) {
                $this->discardUpload($uploaded);
            }
            throw $exception;
        }
    }

    /**
     * @throws ValidationException|InvalidCredentialsException|AccountLockedException|AccountUnavailableException
     */
    public function login(mixed $email, #[\SensitiveParameter] mixed $password): AppUser
    {
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';
        $password = is_string($password) ? $password : '';

        $errors = array_filter([
            'email' => $email === '' ? 'Ingresa tu email.' : null,
            'password' => $password === '' ? 'Ingresa tu contraseña.' : null,
        ]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $user = $this->users->findForLogin($email);
        if ($user === null) {
            password_verify($password, self::TIMING_HASH);
            $this->users->recordLogin(null, false);
            throw new InvalidCredentialsException();
        }

        $result = $this->users->recordLogin($user->id(), password_verify($password, $user->passwordHash()));

        return match ($result->outcome) {
            LoginOutcome::Success => $this->users->findById($user->id()) ?? $user,
            LoginOutcome::WrongPassword => throw ($result->accountStatus === AccountStatus::Locked
                ? new AccountLockedException(justLocked: true)
                : new InvalidCredentialsException()),
            LoginOutcome::Blocked => throw ($result->accountStatus === AccountStatus::Locked
                ? new AccountLockedException()
                : new AccountUnavailableException()),
            LoginOutcome::UnknownUser => throw new InvalidCredentialsException(),
        };
    }

    /** Usuario de la sesión, solo si su cuenta sigue activa y sin ban. */
    public function userForSession(?string $userId): ?AppUser
    {
        if ($userId === null) {
            return null;
        }

        $user = $this->users->findById($userId);

        return $user !== null && $user->active() && !$user->ban() ? $user : null;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null
            ? ($this->clock)()
            : new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TIME_ZONE));
    }

    private function discardUpload(UploadedMedia $media): void
    {
        try {
            $this->media->destroy($media->publicId);
        } catch (Throwable $exception) {
            error_log(sprintf('[auth] no se pudo borrar el avatar huérfano %s: %s', $media->publicId, $exception->getMessage()));
        }
    }
}
