<?php

declare(strict_types=1);

namespace App\Integrations;

use RuntimeException;

/**
 * Falla al hablar con un proveedor de pagos (PayPal Sandbox). $notConfigured distingue
 * "faltan credenciales" de un error del servicio. El mensaje es para el log, no para el cliente.
 */
final class PaymentProviderException extends RuntimeException
{
    private function __construct(string $message, public readonly bool $notConfigured)
    {
        parent::__construct($message);
    }

    public static function missingConfiguration(): self
    {
        return new self('PayPal no está configurado: faltan PAYPAL_SANDBOX_CLIENT_ID / PAYPAL_SANDBOX_CLIENT_SECRET en .env.', true);
    }

    public static function failed(string $reason): self
    {
        return new self('PayPal Sandbox: ' . $reason, false);
    }
}
