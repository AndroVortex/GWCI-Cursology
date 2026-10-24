<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Dto\LockedAccount;
use App\Models\Entities\AppUser;
use App\Repositories\UserRepository;
use App\Services\Exceptions\BusinessRuleException;

/** Administración de cuentas: ver las bloqueadas y reactivarlas. */
final class AccountAdminService
{
    /** Motivo que queda en user_account_event al reactivar desde el panel. */
    public const UNLOCK_REASON = 'Reactivación manual por un administrador';

    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * @return list<LockedAccount>
     * @throws BusinessRuleException NOT_ADMIN
     */
    public function lockedAccounts(AppUser $admin): array
    {
        return $this->users->listLocked($admin->id());
    }

    /**
     * La cuenta vuelve a estar activa con el contador de fallos en 0; la contraseña no cambia.
     *
     * @throws BusinessRuleException NOT_ADMIN, USER_NOT_FOUND, USER_NOT_LOCKED
     */
    public function unlock(AppUser $admin, string $userId): AppUser
    {
        return $this->users->unlock($admin->id(), $userId, self::UNLOCK_REASON);
    }
}
