<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\ProcedureException;
use App\Database\StoredProcedures;
use App\Models\Dto\LoginResult;
use App\Models\Entities\AppUser;
use App\Services\Exceptions\EmailTakenException;
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
}
