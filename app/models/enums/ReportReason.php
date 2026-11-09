<?php

declare(strict_types=1);

namespace App\Models\Enums;

/** content_report.reason_code — ck_report_reason. "Otro" exige un detalle (ck_report_details). */
enum ReportReason: string
{
    case Spam = 'spam';
    case Offensive = 'offensive';
    case Answers = 'answers';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Spam => 'Spam o publicidad',
            self::Offensive => 'Ofensivo o acoso',
            self::Answers => 'Comparte respuestas del examen',
            self::Other => 'Otro',
        };
    }
}
