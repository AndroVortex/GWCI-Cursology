<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Models\Dto\LockedAccount;
use App\Models\Dto\UserResponse;
use App\Models\Entities\AppUser;
use App\Services\AccountAdminService;

/** Endpoints /api/admin/users/*: cuentas bloqueadas y su reactivación (solo admin). */
final class AccountController
{
    public function __construct(private readonly AccountAdminService $accounts)
    {
    }

    /** GET /api/admin/users/locked */
    public function listLocked(Request $request): JsonResponse
    {
        return JsonResponse::data([
            'accounts' => array_map(self::present(...), $this->accounts->lockedAccounts($this->user($request))),
        ]);
    }

    /** POST /api/admin/users/{id}/unlock */
    public function unlock(Request $request): JsonResponse
    {
        $user = $this->accounts->unlock($this->user($request), (string) $request->attribute('id'));

        return JsonResponse::data(['user' => UserResponse::fromUser($user)]);
    }

    /** @return array<string, string|bool|null> */
    private static function present(LockedAccount $account): array
    {
        return [
            'id' => $account->id,
            'fullName' => $account->fullName(),
            'email' => $account->email,
            'role' => $account->role->value,
            'avatar' => $account->avatar,
            'banned' => $account->banned,
            'lockedAt' => $account->lockedAt->format(DATE_ATOM),
            'lastAttemptAt' => $account->lastAttemptAt?->format(DATE_ATOM),
        ];
    }

    private function user(Request $request): AppUser
    {
        /** @var AppUser */
        return $request->attribute('user');
    }
}
