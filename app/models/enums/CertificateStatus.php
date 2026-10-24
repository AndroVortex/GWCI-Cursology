<?php

declare(strict_types=1);

namespace App\Models\Enums;

/** certificate.status — ck_certificate_status. */
enum CertificateStatus: string
{
    case Pending = 'pending';
    case Issued = 'issued';
    case Failed = 'failed';
}
