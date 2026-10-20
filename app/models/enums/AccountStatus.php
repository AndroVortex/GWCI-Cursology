<?php

declare(strict_types=1);

namespace App\Models\Enums;

/** app_user.account_status — ck_user_status. El ban es independiente de este estado. */
enum AccountStatus: string
{
    case Active = 'active';
    case Locked = 'locked';
    case Deleted = 'deleted';
}
