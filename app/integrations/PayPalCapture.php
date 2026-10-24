<?php

declare(strict_types=1);

namespace App\Integrations;

/** Resultado de capturar (cobrar) una orden de PayPal Sandbox, ya leído de su respuesta. */
final class PayPalCapture
{
    /**
     * @param string $status estado de la captura: COMPLETED, PENDING, DECLINED...
     * @param string $amount importe con dos decimales, tal como lo regresa PayPal ("899.00")
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $status,
        public readonly ?string $captureId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $customId,
    ) {
    }

    public function isCompleted(): bool
    {
        return $this->status === 'COMPLETED';
    }
}
