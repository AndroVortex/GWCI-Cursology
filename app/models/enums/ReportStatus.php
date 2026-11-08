<?php

declare(strict_types=1);

namespace App\Models\Enums;

/** content_report.status — ck_report_status. */
enum ReportStatus: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
