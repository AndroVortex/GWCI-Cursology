<?php

declare(strict_types=1);

namespace App\Models\Dto;

use App\Models\Mapping\RowReader;

/** Lo cobrado con una forma de pago (sp_instructor_sales, segundo resultado). */
final class PaymentMethodTotal
{
    private function __construct(
        public readonly string $methodName,
        public readonly int $purchases,
        public readonly string $total,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $r = new RowReader(self::class, $row);

        return new self($r->string('method_name', 100), $r->intUnsigned('purchases'), $r->decimal('total', 14, 2));
    }
}
