<?php

declare(strict_types=1);

namespace App\Models\Enums;

/** user_account_event.event_type — ck_account_event_type. */
enum AccountEventType: string
{
    case Locked = 'locked';
    case Reactivated = 'reactivated';
    case Deleted = 'deleted';
    case Banned = 'banned';
    case Unbanned = 'unbanned';
}
