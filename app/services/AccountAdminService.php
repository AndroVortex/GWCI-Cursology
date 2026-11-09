<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Dto\AccountEvent;
use App\Models\Dto\AdminUserPage;
use App\Models\Dto\LockedAccount;
use App\Models\Entities\AppUser;
use App\Models\Enums\UserRole;
use App\Repositories\UserRepository;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;
use App\Services\Validation\UserRules;

/**
 * Administración de cuentas: las bloqueadas y su reactivación, la lista de todas las cuentas,
 * suspender (ban) y quitar la suspensión, reactivar las dadas de baja y el historial.
 */
final class AccountAdminService
{
    /** Motivo que queda en user_account_event al reactivar desde el panel. */
    public const UNLOCK_REASON = 'Reactivación manual por un administrador';
    public const UNBAN_REASON = 'Suspensión retirada por un administrador';
    public const PAGE_SIZE = 20;
    public const MAX_SEARCH_CHARS = 100;
    private const MAX_PAGE = 1000;
    /** Valor del filtro "estado" => estado que entiende sp_admin_list_users. */
    private const STATES = ['activas' => 'active', 'bloqueadas' => 'locked', 'de_baja' => 'deleted', 'suspendidas' => 'banned'];
    /** Valor del filtro "rol" => rol de la cuenta. */
    private const ROLES = ['alumno' => UserRole::Student, 'instructor' => UserRole::Instructor, 'admin' => UserRole::Admin];

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

    /**
     * Todas las cuentas con los filtros {q, rol, estado, pagina}; los inválidos se ignoran.
     *
     * @param array<string, mixed> $query
     * @throws BusinessRuleException NOT_ADMIN
     */
    public function users(AppUser $admin, array $query = []): AdminUserPage
    {
        $filters = self::filters($query);
        $page = UserRules::text($query, 'pagina');
        $page = preg_match('/^[1-9][0-9]{0,3}$/D', $page) === 1 ? min((int) $page, self::MAX_PAGE) : 1;

        [$rows, $total] = $this->users->listUsers(
            $admin->id(),
            ModerationService::likePattern($filters['q']),
            self::ROLES[$filters['rol']] ?? null,
            self::STATES[$filters['estado']] ?? null,
            self::PAGE_SIZE,
            ($page - 1) * self::PAGE_SIZE,
        );

        return new AdminUserPage($rows, $total, $page, max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE)), $filters);
    }

    /**
     * Suspende la cuenta (ban): ya no inicia sesión y, si es instructor, sus cursos salen del catálogo.
     *
     * @param array<string, mixed> $input {motivo}
     * @throws ValidationException|BusinessRuleException NOT_ADMIN, USER_NOT_FOUND, CANNOT_BAN_ADMIN, ALREADY_BANNED
     */
    public function ban(AppUser $admin, string $userId, array $input): AppUser
    {
        self::requireId($userId);

        return $this->users->ban($admin->id(), $userId, ReviewService::validateReason($input));
    }

    /** @throws BusinessRuleException NOT_ADMIN, USER_NOT_FOUND, NOT_BANNED */
    public function unban(AppUser $admin, string $userId): AppUser
    {
        self::requireId($userId);

        return $this->users->unban($admin->id(), $userId, self::UNBAN_REASON);
    }

    /**
     * Una cuenta bloqueada o dada de baja vuelve a estar activa (el ban no cambia).
     *
     * @throws BusinessRuleException NOT_ADMIN, USER_NOT_FOUND, USER_ALREADY_ACTIVE
     */
    public function reactivate(AppUser $admin, string $userId): AppUser
    {
        self::requireId($userId);

        return $this->users->reactivate($admin->id(), $userId, self::UNLOCK_REASON);
    }

    /**
     * @return list<AccountEvent>
     * @throws BusinessRuleException NOT_ADMIN, USER_NOT_FOUND
     */
    public function events(AppUser $admin, string $userId): array
    {
        self::requireId($userId);

        return $this->users->events($admin->id(), $userId);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{q: string, rol: string, estado: string}
     */
    public static function filters(array $query): array
    {
        $role = UserRules::text($query, 'rol');
        $state = UserRules::text($query, 'estado');

        return [
            'q' => mb_substr(UserRules::text($query, 'q'), 0, self::MAX_SEARCH_CHARS),
            'rol' => isset(self::ROLES[$role]) ? $role : '',
            'estado' => isset(self::STATES[$state]) ? $state : '',
        ];
    }

    private static function requireId(string $value): void
    {
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $value) !== 1) {
            throw BusinessRuleException::of('USER_NOT_FOUND');
        }
    }
}
