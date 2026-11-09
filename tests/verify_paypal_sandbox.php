<?php

declare(strict_types=1);

/**
 * Prueba OPCIONAL por red contra el Sandbox de PayPal Developers, con PAYPAL_SANDBOX_CLIENT_ID y
 * PAYPAL_SANDBOX_CLIENT_SECRET del .env (dinero de prueba; el cliente no tiene opción Live).
 *
 *   php tests/verify_paypal_sandbox.php
 *
 * Pide el token, crea una orden de $1.00 MXN y lee su estado: CREATED, o PAYER_ACTION_REQUIRED
 * porque la orden lleva payment_source.paypal. No la aprueba ni la captura: eso lo hace el
 * comprador de prueba en el navegador (ver docs/payments.md). La orden queda sin pagar y PayPal
 * la descarta sola. No toca la base de datos.
 */

use App\Integrations\PaymentProviderException;
use App\Integrations\PayPalClient;

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/app/database/conn.php';

$paypal = new PayPalClient($ENV['PAYPAL_SANDBOX_CLIENT_ID'] ?? '', $ENV['PAYPAL_SANDBOX_CLIENT_SECRET'] ?? '');
if (!$paypal->isConfigured()) {
    fwrite(STDERR, 'Faltan PAYPAL_SANDBOX_CLIENT_ID o PAYPAL_SANDBOX_CLIENT_SECRET en el .env (ver docs/payments.md).' . PHP_EOL);
    exit(1);
}

Report::section('PayPal Sandbox');
check(PayPalClient::API_BASE === 'https://api-m.sandbox.paypal.com', 'el cliente apunta a Sandbox');
try {
    $purchaseId = 'sandbox-' . date('YmdHis');
    $orderId = $paypal->createOrder($purchaseId, '1.00', 'MXN', 'Cursology: prueba de Sandbox');
    check(preg_match('/^[A-Z0-9]{10,}$/D', $orderId) === 1, "token obtenido y orden creada ($orderId)");
    $status = $paypal->orderStatus($orderId);
    check(in_array($status, ['CREATED', 'PAYER_ACTION_REQUIRED'], true), "la orden espera la aprobación del comprador ($status)");
    check($paypal->createOrder($purchaseId, '1.00', 'MXN', 'Cursology: prueba de Sandbox') === $orderId, 'repetir con el mismo PayPal-Request-Id devuelve la misma orden');
} catch (PaymentProviderException $exception) {
    check(false, 'PayPal respondió con error: ' . $exception->getMessage());
}

Report::finish();
