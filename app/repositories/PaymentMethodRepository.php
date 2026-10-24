<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\StoredProcedures;
use App\Models\Entities\PaymentMethod;

/** Catálogo de formas de pago, exclusivamente mediante SP. */
final class PaymentMethodRepository
{
    use CallsProcedures;

    public function __construct(private readonly StoredProcedures $procedures)
    {
    }

    /** @return list<PaymentMethod> */
    public function list(bool $onlyActive): array
    {
        return array_map(
            static fn (array $row): PaymentMethod => PaymentMethod::fromRow($row),
            $this->resultSets('sp_payment_method_list', [$onlyActive])[0] ?? [],
        );
    }

    /** @throws \App\Services\Exceptions\BusinessRuleException NOT_ADMIN, UNKNOWN_PAYMENT_METHOD */
    public function setActive(string $adminId, string $code, string $name, bool $active): PaymentMethod
    {
        return PaymentMethod::fromRow($this->requiredRow('sp_payment_method_set_active', [$adminId, $code, $name, $active]));
    }
}
