<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\StoredProcedures;
use App\Models\Entities\Certificate;

/** Certificados, exclusivamente mediante SP. Los datos se congelan en la base al crearlos. */
final class CertificateRepository
{
    use CallsProcedures;

    public function __construct(private readonly StoredProcedures $procedures)
    {
    }

    public function issue(string $userId, string $courseId): Certificate
    {
        return Certificate::fromRow($this->requiredRow('sp_certificate_issue', [$userId, $courseId]));
    }

    public function markIssued(string $userId, string $certificateId, string $documentReference): Certificate
    {
        return Certificate::fromRow($this->requiredRow('sp_certificate_mark_issued', [$userId, $certificateId, $documentReference]));
    }

    /** @return list<Certificate> */
    public function listByStudent(string $userId): array
    {
        return array_map(Certificate::fromRow(...), $this->resultSets('sp_certificate_list_by_student', [$userId])[0] ?? []);
    }

    public function verify(string $code): Certificate
    {
        return Certificate::fromRow($this->requiredRow('sp_certificate_verify', [$code]));
    }
}
