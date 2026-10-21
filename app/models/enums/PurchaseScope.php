<?php

declare(strict_types=1);

namespace App\Models\Enums;

/** purchase.purchase_scope — ck_purchase_scope. */
enum PurchaseScope: string
{
    case Course = 'course';
    case Level = 'level';
}
