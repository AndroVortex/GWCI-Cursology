<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\PayPalClient;
use App\Models\Entities\AppUser;
use App\Models\Entities\PaymentMethod;
use App\Repositories\PaymentMethodRepository;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;

/**
 * Formas de pago: el admin activa o desactiva las que la app sabe procesar
 * (PaymentProvider). PayPal solo se ofrece si además hay credenciales de Sandbox.
 */
final class PaymentMethodService
{
    public function __construct(
        private readonly PaymentMethodRepository $methods,
        private readonly PayPalClient $paypal,
    ) {
    }

    /**
     * Las formas que la app conoce, con su estado en la base (null = nunca configurada).
     *
     * @return list<array{provider: PaymentProvider, method: ?PaymentMethod, active: bool, ready: bool, note: ?string}>
     */
    public function overview(): array
    {
        $byCode = [];
        foreach ($this->methods->list(false) as $method) {
            $byCode[$method->code()] = $method;
        }

        return array_map(function (PaymentProvider $provider) use ($byCode): array {
            $method = $byCode[$provider->value] ?? null;
            $ready = $provider !== PaymentProvider::PayPal || $this->paypal->isConfigured();

            return [
                'provider' => $provider,
                'method' => $method,
                'active' => $method?->active() ?? false,
                'ready' => $ready,
                'note' => $ready ? null : 'Faltan PAYPAL_SANDBOX_CLIENT_ID y PAYPAL_SANDBOX_CLIENT_SECRET en el .env: aunque esté activa, no se ofrece.',
            ];
        }, PaymentProvider::cases());
    }

    /** @return list<PaymentProvider> las que se ofrecen en el pago: activas y listas para usarse */
    public function available(): array
    {
        return array_values(array_map(
            static fn (array $row): PaymentProvider => $row['provider'],
            array_filter($this->overview(), static fn (array $row): bool => $row['active'] && $row['ready']),
        ));
    }

    /**
     * @param array<string, mixed> $input {codigo, activa: bool}
     * @throws ValidationException|BusinessRuleException
     */
    public function setActive(AppUser $admin, array $input): PaymentMethod
    {
        $provider = PaymentProvider::tryFrom(is_string($input['codigo'] ?? null) ? $input['codigo'] : '');
        $errors = array_filter([
            'codigo' => $provider === null ? 'Forma de pago desconocida.' : null,
            'activa' => is_bool($input['activa'] ?? null) ? null : 'Indica si queda activa (true o false).',
        ]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->methods->setActive($admin->id(), $provider->value, $provider->label(), $input['activa']);
    }
}
