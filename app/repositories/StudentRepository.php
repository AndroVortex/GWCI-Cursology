<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\StoredProcedures;
use App\Models\Dto\KardexEntry;
use App\Models\Entities\Purchase;

/** Panel "Mi aprendizaje": kardex y compras, exclusivamente mediante SP. */
final class StudentRepository
{
    use CallsProcedures;

    public function __construct(private readonly StoredProcedures $procedures)
    {
    }

    /**
     * @param ?string $from Y-m-d
     * @param ?string $to Y-m-d
     * @param 'in_progress'|'completed'|'active'|null $status
     * @return list<KardexEntry>
     */
    public function courses(string $userId, ?string $from, ?string $to, ?string $categoryId, ?string $status): array
    {
        return array_map(
            KardexEntry::fromRow(...),
            $this->resultSets('sp_student_courses', [$userId, $from, $to, $categoryId, $status])[0] ?? [],
        );
    }

    /** @return list<Purchase> */
    public function purchases(string $userId): array
    {
        return array_map(Purchase::fromRow(...), $this->resultSets('sp_student_purchases', [$userId])[0] ?? []);
    }
}
