<?php

declare(strict_types=1);

namespace App\Integrations;

use Closure;

/**
 * Cliente mínimo de la API REST de PayPal (Orders v2), sin SDK.
 *
 * SOLO usa el entorno Sandbox de PayPal Developers: la dirección va fija en el código y
 * no hay ninguna configuración para apuntar a Live. Con credenciales de Sandbox, las
 * órdenes y capturas son reales en PayPal pero con cuentas y dinero de prueba.
 *
 * Flujo: el servidor crea la orden, el comprador la aprueba en la ventana de PayPal (SDK de
 * JS en el navegador) y el servidor la captura y verifica el resultado.
 */
final class PayPalClient
{
    public const API_BASE = 'https://api-m.sandbox.paypal.com';
    public const SDK_URL = 'https://www.paypal.com/sdk/js';

    private ?string $accessToken = null;

    /**
     * @param (Closure(string, string, list<string>, ?string): array{status: int, body: string})|null $transport
     *        reemplaza el envío HTTP en las pruebas: (método, URL, headers, cuerpo) => {status, body}
     */
    public function __construct(
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
        private readonly ?Closure $transport = null,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    /** Client ID público, para cargar el SDK de JS en la página de pago. */
    public function clientId(): string
    {
        return $this->clientId;
    }

    /**
     * Crea una orden por $amount (cadena DECIMAL con dos decimales) y devuelve su id.
     * PayPal-Request-Id hace la llamada idempotente: repetirla devuelve la misma orden.
     *
     * @throws PaymentProviderException
     */
    public function createOrder(string $purchaseId, string $amount, string $currency, string $description): string
    {
        $response = $this->call('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'purchase-' . $purchaseId,
                'custom_id' => $purchaseId,
                'description' => mb_substr($description, 0, 127),
                'amount' => ['currency_code' => $currency, 'value' => $amount],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'brand_name' => 'Cursology',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
            ]]],
        ], 'cursology-order-' . $purchaseId);

        $orderId = $response['id'] ?? null;
        if (!is_string($orderId) || $orderId === '') {
            throw PaymentProviderException::failed('la respuesta de la orden no trae id.');
        }

        return $orderId;
    }

    /**
     * Captura (cobra) una orden ya aprobada por el comprador. Si ya se había capturado,
     * lee la orden y devuelve esa captura.
     *
     * @throws PaymentProviderException
     */
    public function captureOrder(string $orderId): PayPalCapture
    {
        $path = '/v2/checkout/orders/' . rawurlencode($orderId);
        try {
            $response = $this->call('POST', $path . '/capture', null, 'cursology-capture-' . $orderId);
        } catch (PaymentProviderException $exception) {
            if (!str_contains($exception->getMessage(), 'ORDER_ALREADY_CAPTURED')) {
                throw $exception;
            }
            $response = $this->call('GET', $path);
        }

        return self::capture($orderId, $response);
    }

    /**
     * Estado de una orden (CREATED, APPROVED, COMPLETED...), para diagnosticar pagos pendientes.
     *
     * @throws PaymentProviderException
     */
    public function orderStatus(string $orderId): string
    {
        $status = $this->call('GET', '/v2/checkout/orders/' . rawurlencode($orderId))['status'] ?? null;

        return is_string($status) ? $status : 'UNKNOWN';
    }

    /** @param array<string, mixed> $order */
    private static function capture(string $orderId, array $order): PayPalCapture
    {
        $unit = $order['purchase_units'][0] ?? [];
        $capture = is_array($unit) ? ($unit['payments']['captures'][0] ?? null) : null;
        if (!is_array($capture)) {
            return new PayPalCapture($orderId, is_string($order['status'] ?? null) ? $order['status'] : 'UNKNOWN', null, '0.00', '', null);
        }

        return new PayPalCapture(
            orderId: $orderId,
            status: is_string($capture['status'] ?? null) ? $capture['status'] : 'UNKNOWN',
            captureId: is_string($capture['id'] ?? null) ? $capture['id'] : null,
            amount: is_string($capture['amount']['value'] ?? null) ? $capture['amount']['value'] : '0.00',
            currency: is_string($capture['amount']['currency_code'] ?? null) ? $capture['amount']['currency_code'] : '',
            customId: is_string($capture['custom_id'] ?? null) ? $capture['custom_id'] : (is_string($unit['custom_id'] ?? null) ? $unit['custom_id'] : null),
        );
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws PaymentProviderException
     */
    private function call(string $method, string $path, ?array $body = null, ?string $requestId = null): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->token(),
            'Content-Type: application/json',
            'Prefer: return=representation',
        ];
        if ($requestId !== null) {
            $headers[] = 'PayPal-Request-Id: ' . $requestId;
        }

        $payload = $method === 'GET' ? null : json_encode($body ?? new \stdClass(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $response = $this->send($method, self::API_BASE . $path, $headers, $payload);
        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw PaymentProviderException::failed(sprintf('respuesta no JSON (HTTP %d).', $response['status']));
        }
        if ($response['status'] >= 400) {
            $issue = $data['details'][0]['issue'] ?? $data['name'] ?? 'sin detalle';
            throw PaymentProviderException::failed(sprintf('HTTP %d: %s', $response['status'], is_string($issue) ? $issue : 'sin detalle'));
        }

        return $data;
    }

    /** @throws PaymentProviderException */
    private function token(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }
        if (!$this->isConfigured()) {
            throw PaymentProviderException::missingConfiguration();
        }

        $response = $this->send('POST', self::API_BASE . '/v1/oauth2/token', [
            'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
        ], 'grant_type=client_credentials');
        $data = json_decode($response['body'], true);
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;
        if ($response['status'] >= 400 || !is_string($token) || $token === '') {
            throw PaymentProviderException::failed(sprintf('no se obtuvo el token (HTTP %d). Revisa que sean credenciales de Sandbox.', $response['status']));
        }

        return $this->accessToken = $token;
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string}
     * @throws PaymentProviderException
     */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body);
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, Tls::curlOptions() + [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ] + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []));
        $responseBody = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if (!is_string($responseBody)) {
            throw PaymentProviderException::failed('no hubo respuesta (' . $curlError . ').' . Tls::hint($curlError));
        }

        return ['status' => $status, 'body' => $responseBody];
    }

    /** Sin credenciales en var_dump()/print_r(). */
    public function __debugInfo(): array
    {
        return ['environment' => 'sandbox', 'configured' => $this->isConfigured()];
    }
}
