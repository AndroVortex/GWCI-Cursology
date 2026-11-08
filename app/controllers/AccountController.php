<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Models\Dto\AccountEvent;
use App\Models\Dto\AdminUserRow;
use App\Models\Dto\LockedAccount;
use App\Models\Dto\UserResponse;
use App\Models\Entities\AppUser;
use App\Services\AccountAdminService;

/** Endpoints /api/admin/users/*: cuentas, bloqueos, suspensiones (ban), reactivación e historial (solo admin). */
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

    /** GET /api/admin/users?q=&rol=&estado=&pagina= */
    public function list(Request $request): JsonResponse
    {
        $page = $this->accounts->users($this->user($request), $request->queryParams());

        return JsonResponse::data([
            'users' => array_map(self::presentRow(...), $page->users),
            'filters' => $page->filters,
            'total' => $page->total,
            'page' => $page->page,
            'pages' => $page->pages,
        ]);
    }

    /** POST /api/admin/users/{id}/ban ({motivo}) */
    public function ban(Request $request): JsonResponse
    {
        $user = $this->accounts->ban($this->user($request), (string) $request->attribute('id'), $request->json());

        return JsonResponse::data(['user' => self::presentState($user)]);
    }

    /** POST /api/admin/users/{id}/unban */
    public function unban(Request $request): JsonResponse
    {
        $user = $this->accounts->unban($this->user($request), (string) $request->attribute('id'));

        return JsonResponse::data(['user' => self::presentState($user)]);
    }

    /** POST /api/admin/users/{id}/reactivate: una cuenta bloqueada o dada de baja vuelve a estar activa */
    public function reactivate(Request $request): JsonResponse
    {
        $user = $this->accounts->reactivate($this->user($request), (string) $request->attribute('id'));

        return JsonResponse::data(['user' => self::presentState($user)]);
    }

    /** GET /api/admin/users/{id}/events */
    public function events(Request $request): JsonResponse
    {
        $userId = (string) $request->attribute('id');

        return JsonResponse::data([
            'events' => array_map(static fn (AccountEvent $event): array => [
                'id' => $event->id,
                'type' => $event->type->value,
                'label' => $event->label(),
                'reason' => $event->reason,
                'actor' => $event->actorLabel($userId),
                'createdAt' => $event->createdAt->format(DATE_ATOM),
                'createdAtLabel' => format_date_time($event->createdAt),
            ], $this->accounts->events($this->user($request), $userId)),
        ]);
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

    /** @return array<string, mixed> */
    private static function presentRow(AdminUserRow $row): array
    {
        return [
            'id' => $row->id,
            'fullName' => $row->fullName(),
            'email' => $row->email,
            'avatar' => $row->avatar,
            'role' => $row->role->value,
            'status' => $row->status->value,
            'banned' => $row->banned,
            'banReason' => $row->banReason,
            'createdAt' => $row->createdAt->format(DATE_ATOM),
            'lastLoginAt' => $row->lastLoginAt?->format(DATE_ATOM),
            'lastEvent' => $row->lastEventType === null ? null : [
                'type' => $row->lastEventType->value,
                'reason' => $row->lastEventReason,
                'at' => $row->lastEventAt?->format(DATE_ATOM),
            ],
        ];
    }

    /** @return array<string, string|bool|null> */
    private static function presentState(AppUser $user): array
    {
        return [
            'id' => $user->id(),
            'status' => $user->accountStatus()->value,
            'banned' => $user->ban(),
            'banReason' => $user->banReason(),
        ];
    }

    private function user(Request $request): AppUser
    {
        /** @var AppUser */
        return $request->attribute('user');
    }
}
