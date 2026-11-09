<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\CloudinaryClient;
use App\Integrations\MediaUploadException;
use App\Models\Entities\AppUser;
use App\Models\Enums\UserRole;
use App\Repositories\UserRepository;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;
use App\Services\Validation\UserRules;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Casos de uso del perfil propio: datos, email, foto, contraseña y dar de baja la cuenta. */
final class ProfileService
{
    /** Motivo que queda en user_account_event si el usuario no escribe uno al darse de baja. */
    public const DEACTIVATE_REASON = 'Baja solicitada por el usuario';
    public const MAX_DEACTIVATE_REASON_CHARS = 500;
    private const AVATAR_FOLDER = 'cursology/avatars';
    private const LOCAL_TIME_ZONE = 'America/Monterrey';

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly ProfileValidator $validator,
        private readonly CloudinaryClient $media,
        private readonly ?Closure $clock = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException|BusinessRuleException
     */
    public function updateProfile(AppUser $user, array $input): AppUser
    {
        $data = $this->validator->validateProfile($input, $user, $this->now());

        // Cambiar el email cambia el usuario con el que se inicia sesión: se confirma con la contraseña.
        if ($data->emailChanged && !password_verify($data->currentPassword, $user->passwordHash())) {
            throw new ValidationException(['current_password' => 'La contraseña actual no es correcta.']);
        }

        return $this->users->updateProfile($user->id(), $data);
    }

    /**
     * @param array{tmp_name: string, error: int, size: int, name: string}|null $photo
     * @throws ValidationException|MediaUploadException
     */
    public function changeAvatar(AppUser $user, ?array $photo): AppUser
    {
        [$path, $mimeType, $error] = UserRules::photo($photo);
        if ($photo === null || $path === null || $mimeType === null) {
            throw new ValidationException(['foto' => $error ?? 'Selecciona una foto.']);
        }

        $uploaded = $this->media->uploadImage($path, $mimeType, self::AVATAR_FOLDER);
        try {
            $updated = $this->users->updateAvatar($user->id(), $uploaded->secureUrl);
        } catch (Throwable $exception) {
            $this->discard($uploaded->secureUrl);
            throw $exception;
        }

        $this->discard($user->avatar());

        return $updated;
    }

    public function removeAvatar(AppUser $user): AppUser
    {
        if ($user->avatar() === AuthService::DEFAULT_AVATAR) {
            return $user;
        }

        $updated = $this->users->updateAvatar($user->id(), AuthService::DEFAULT_AVATAR);
        $this->discard($user->avatar());

        return $updated;
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function changePassword(AppUser $user, array $input): void
    {
        [$current, $new] = $this->validator->validatePasswordChange($input);

        $errors = array_filter([
            'current_password' => password_verify($current, $user->passwordHash()) ? null : 'La contraseña actual no es correcta.',
            'new_password' => password_verify($new, $user->passwordHash()) ? 'La nueva contraseña debe ser distinta de la actual.' : null,
        ]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->users->changePassword($user->id(), password_hash($new, PASSWORD_DEFAULT));
    }

    /**
     * Da de baja la cuenta propia (account_status = 'deleted'), con la contraseña actual como
     * confirmación. Se conservan sus cursos, compras y certificados; solo un admin la reactiva.
     * Un administrador no se da de baja así.
     *
     * @param array<string, mixed> $input {password, motivo?}
     * @throws ValidationException|BusinessRuleException ADMIN_CANNOT_DEACTIVATE, USER_NOT_AVAILABLE
     */
    public function deactivate(AppUser $user, array $input): void
    {
        if ($user->role() === UserRole::Admin) {
            throw BusinessRuleException::of('ADMIN_CANNOT_DEACTIVATE');
        }

        $password = $input['password'] ?? null;
        $reason = UserRules::text($input, 'motivo');
        $errors = array_filter([
            'password' => match (true) {
                !is_string($password) || $password === '' => 'Escribe tu contraseña para confirmar.',
                !password_verify($password, $user->passwordHash()) => 'La contraseña no es correcta.',
                default => null,
            },
            'motivo' => mb_strlen($reason) > self::MAX_DEACTIVATE_REASON_CHARS
                ? sprintf('No puede exceder %d caracteres.', self::MAX_DEACTIVATE_REASON_CHARS)
                : null,
        ]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->users->deactivate($user->id(), $reason === '' ? self::DEACTIVATE_REASON : $reason);
    }

    /** Borra de Cloudinary un avatar anterior; solo los de nuestra carpeta de avatares. */
    private function discard(string $avatarUrl): void
    {
        $asset = $this->media->parseDeliveryUrl($avatarUrl);
        if ($asset === null || !str_starts_with($asset['public_id'], self::AVATAR_FOLDER . '/')) {
            return;
        }

        try {
            $this->media->destroy($asset['public_id'], $asset['resource_type'], $asset['type']);
        } catch (Throwable $exception) {
            error_log(sprintf('[profile] no se pudo borrar el avatar %s: %s', $asset['public_id'], $exception->getMessage()));
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null
            ? ($this->clock)()
            : new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TIME_ZONE));
    }
}
