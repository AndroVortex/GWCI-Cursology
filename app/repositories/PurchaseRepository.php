<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\StoredProcedures;
use App\Models\Dto\CourseAccess;
use App\Models\Entities\Purchase;

/** Inscripciones y compras, exclusivamente mediante SP. El importe siempre lo pone la base. */
final class PurchaseRepository
{
    use CallsProcedures;

    public function __construct(private readonly StoredProcedures $procedures)
    {
    }

    /** @return int niveles nuevos con acceso */
    public function enrollFree(string $userId, string $courseId): int
    {
        return (int) $this->requiredRow('sp_enrollment_free', [$userId, $courseId])['levels_granted'];
    }

    public function start(string $userId, string $courseId, ?string $levelId, string $methodCode, string $idempotencyKey): Purchase
    {
        return Purchase::fromRow($this->requiredRow('sp_purchase_start', [$userId, $courseId, $levelId, $methodCode, $idempotencyKey]));
    }

    public function attachProvider(string $userId, string $purchaseId, string $transactionId): Purchase
    {
        return Purchase::fromRow($this->requiredRow('sp_purchase_attach_provider', [$userId, $purchaseId, $transactionId]));
    }

    public function get(string $userId, string $purchaseId): Purchase
    {
        return Purchase::fromRow($this->requiredRow('sp_purchase_get', [$userId, $purchaseId]));
    }

    /** @param string $amount importe cobrado por el proveedor (DECIMAL con dos decimales) */
    public function complete(string $userId, string $purchaseId, string $transactionId, string $amount, string $currency): Purchase
    {
        return Purchase::fromRow($this->requiredRow('sp_purchase_complete', [$userId, $purchaseId, $transactionId, $amount, $currency]));
    }

    /** @param 'failed'|'cancelled' $status */
    public function fail(string $userId, string $purchaseId, string $status): Purchase
    {
        return Purchase::fromRow($this->requiredRow('sp_purchase_fail', [$userId, $purchaseId, $status]));
    }

    public function access(string $userId, string $courseId): CourseAccess
    {
        return CourseAccess::fromResultSets($this->resultSets('sp_course_access', [$userId, $courseId]));
    }
}
