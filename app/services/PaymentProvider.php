<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Formas de pago que la app sabe procesar. Su valor es payment_method.code; el admin
 * las activa o desactiva. PayPal usa solo el Sandbox de PayPal Developers y la tarjeta
 * es simulada: ninguna cobra dinero real.
 */
enum PaymentProvider: string
{
    case PayPal = 'paypal';
    case SimulatedCard = 'simulated_card';

    public function label(): string
    {
        return match ($this) {
            self::PayPal => 'PayPal (Sandbox)',
            self::SimulatedCard => 'Tarjeta simulada',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PayPal => 'Pago con la API Sandbox de PayPal Developers y cuentas de comprador de prueba. No mueve dinero real.',
            self::SimulatedCard => 'Formulario de tarjeta que valida número, vencimiento y CVV sin cobrar. La 4000 0000 0000 0002 siempre se rechaza.',
        };
    }
}
