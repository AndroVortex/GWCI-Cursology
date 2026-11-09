<?php

declare(strict_types=1);

namespace App\Models\Enums;

/** content_report.target_type — ck_report_target. */
enum ReportTarget: string
{
    case Comment = 'comment';
    case Opinion = 'opinion';

    public function label(): string
    {
        return match ($this) {
            self::Comment => 'Comentario',
            self::Opinion => 'Opinión',
        };
    }
}
