<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\ProcedureException;
use App\Database\StoredProcedures;
use App\Models\Dto\LockedAccount;
use App\Models\Dto\LoginResult;
use App\Models\Entities\AppUser;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\EmailTakenException;
use App\Services\ProfileData;
use App\Services\RegistrationData;
use UnexpectedValueException;

/** Acceso a cuentas de usuario, exclusivamente mediante SP. */
final class UserRepository
{
    public function __construct(private readonly StoredProcedures $procedures)
    {
    }

    /** @throws EmailTakenException */
    public function register(RegistrationData $data, string $passwordHash, string $avatar): AppUser
    {
        try {
            $row = $this->procedures->row('sp_auth_register', [
                $data->name,
                $data->fatherLastName,
                $data->motherLastName,
                $data->gender,
                $data->birthday,
                $avatar,
                $data->email,
                $passwordHash,
                $data->role,
            ]);
        } catch (ProcedureException $exception) {
            if ($exception->is('EMAIL_TAKEN')) {
                throw new EmailTakenException($exception);
            }
            throw $exception;
        }

        if ($row === null) {
            throw new UnexpectedValueException('sp_auth_register no devolvió la cuenta creada.');
        }

        return AppUser::fromRow($row);
    }

    /** Cuenta con su password_hash, para verificar la contraseña. */
    public function findForLogin(string $email): ?AppUser
    {
        $row = $this->procedures->row('sp_auth_get_login_data', [$email]);

        return $row === null ? null : AppUser::fromRow($row);
    }

    /**
     * Registra el intento y aplica el bloqueo por 3 fallos consecutivos.
     * $userId null = el email no existe.
     */
    public function recordLogin(?string $userId, bool $passwordOk): LoginResult
    {
        $row = $this->procedures->row('sp_auth_record_login', [$userId, $passwordOk]);
        if ($row === null) {
            throw new UnexpectedValueException('sp_auth_record_login no devolvió el resultado del intento.');
        }

        return LoginResult::fromRow($row);
    }

    public function findById(string $userId): ?AppUser
    {
        $row = $this->procedures->row('sp_user_get_by_id', [$userId]);

        return $row === null ? null : AppUser::fromRow($row);
    }

    /**
     * Solo para scripts/create_admin.php.
     *
     * @throws BusinessRuleException ADMIN_EXISTS, EMAIL_TAKEN
     */
    public function createFirstAdmin(
        string $name,
        string $fatherLastName,
        ?string $motherLastName,
        string $gender,
        string $birthday,
        string $avatar,
        string $email,
        string $passwordHash,
    ): AppUser {
        return $this->user('sp_admin_create_first', [
            $name, $fatherLastName, $motherLastName, $gender, $birthday, $avatar, $email, $passwordHash,
        ]);
    }

    /** @throws BusinessRuleException EMAIL_TAKEN, USER_NOT_AVAILABLE */
    public function updateProfile(string $userId, ProfileData $data): AppUser
    {
        return $this->user('sp_user_update_profile', [
            $userId,
            $data->name,
            $data->fatherLastName,
            $data->motherLastName,
            $data->gender,
            $data->birthday,
            $data->description,
            $data->email,
        ]);
    }

    public function updateAvatar(string $userId, string $avatar): AppUser
    {
        return $this->user('sp_user_update_avatar', [$userId, $avatar]);
    }

    public function changePassword(string $userId, string $passwordHash): AppUser
    {
        return $this->user('sp_user_change_password', [$userId, $passwordHash]);
    }

    /**
     * @return list<LockedAccount>
     * @throws BusinessRuleException NOT_ADMIN
     */
    public function listLocked(string $adminId): array
    {
        try {
            $rows = $this->procedures->rows('sp_admin_list_locked_users', [$adminId]);
        } catch (ProcedureException $exception) {
            throw BusinessRuleException::fromProcedure($exception);
        }

        return array_map(static fn (array $row): LockedAccount => LockedAccount::fromRow($row), $rows);
    }

    /**
     * Reactiva una cuenta bloqueada. $actorId null solo desde scripts/unlock_account.php.
     *
     * @throws BusinessRuleException NOT_ADMIN, USER_NOT_FOUND, USER_NOT_LOCKED
     */
    public function unlock(?string $actorId, string $userId, string $reason): AppUser
    {
        return $this->user('sp_admin_unlock_user', [$actorId, $userId, $reason]);
    }

    /**
     * SP que devuelve la fila completa de app_user; sus errores de negocio se traducen.
     *
     * @param list<mixed> $params
     */
    private function user(string $procedure, array $params): AppUser
    {
        try {
            $row = $this->procedures->row($procedure, $params);
        } catch (ProcedureException $exception) {
            throw BusinessRuleException::fromProcedure($exception);
        }

        if ($row === null) {
            throw new UnexpectedValueException(sprintf('%s no devolvió la cuenta.', $procedure));
        }

        return AppUser::fromRow($row);
    }
}
