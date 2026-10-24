<?php

declare(strict_types=1);

namespace App\Models\Enums;

/** login_attempt.outcome — ck_login_outcome. */
enum LoginOutcome: string
{
    case Success = 'success';
    case WrongPassword = 'wrong_password';
    case Blocked = 'blocked';
    case UnknownUser = 'unknown_user';
}
