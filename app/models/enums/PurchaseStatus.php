<?php

declare(strict_types=1);

namespace App\Models\Enums;

/** purchase.status — ck_purchase_status. */
enum PurchaseStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
