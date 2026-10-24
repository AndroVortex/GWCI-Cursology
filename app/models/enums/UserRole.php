<?php

declare(strict_types=1);

namespace App\Models\Enums;

/** app_user.role — ck_user_role. */
enum UserRole: string
{
    case Student = 'student';
    case Instructor = 'instructor';
    case Admin = 'admin';
}
