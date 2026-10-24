<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\PaymentProviderException;
use App\Integrations\PayPalClient;
use App\Models\Dto\CourseAccess;
use App\Models\Entities\AppUser;
use App\Models\Entities\Purchase;
use App\Models\Enums\PurchaseStatus;
use App\Repositories\PurchaseRepository;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Exceptions\ValidationException;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Inscripción y compra de cursos y niveles. El importe siempre lo calcula la base
 * (sp_purchase_start); aquí solo se cobra con el proveedor y se confirma:
 * - PayPal: solo la API Sandbox de PayPal Developers (dinero de prueba).
 * - Tarjeta simulada: valida los datos y no cobra nada.
 */
final class CheckoutService
{
    private const LOCAL_TIME_ZONE = 'America/Monterrey';

    /** @param (Closure(): DateTimeImmutable)|null $clock */
    public function __construct(
        private readonly PurchaseRepository $purchases,
        private readonly PaymentMethodService $methods,
        private readonly PayPalClient $paypal,
        private readonly CardValidator $cards = new CardValidator(),
        private readonly ?Closure $clock = null,
    ) {
    }

    public function access(?AppUser $user, string $courseId): CourseAccess
    {
        return $user === null || !self::isId($courseId) ? CourseAccess::none() : $this->purchases->access($user->id(), $courseId);
    }

    /**
     * Inscripción gratis: el curso completo si es gratis, o sus niveles de $0.
     *
     * @param array<string, mixed> $input {curso}
     * @return int niveles nuevos con acceso
     */
    public function enrollFree(AppUser $student, array $input): int
    {
        [$courseId] = self::target($input, withLevel: false);

        return $this->purchases->enrollFree($student->id(), $courseId);
    }

    /**
     * Abre la compra y crea la orden en PayPal Sandbox. El navegador la aprueba con el SDK
     * de JS y después se llama a capturePayPal().
     *
     * @param array<string, mixed> $input {curso, nivel?}
     * @return array{purchase: Purchase, orderId: string}
     */
    public function startPayPal(AppUser $student, array $input): array
    {
        [$courseId, $levelId] = self::target($input, withLevel: true);
        $this->requireAvailable(PaymentProvider::PayPal);

        $purchase = $this->purchases->start($student->id(), $courseId, $levelId, PaymentProvider::PayPal->value, self::key('paypal'));
        if ($purchase->providerTransactionId() !== null) {
            return ['purchase' => $purchase, 'orderId' => $purchase->providerTransactionId()];
        }

        try {
            $orderId = $this->paypal->createOrder($purchase->id(), $purchase->amount(), $purchase->currency(), self::description($purchase));
        } catch (Throwable $exception) {
            $this->purchases->fail($student->id(), $purchase->id(), 'failed');
            throw $exception;
        }

        return ['purchase' => $this->purchases->attachProvider($student->id(), $purchase->id(), $orderId), 'orderId' => $orderId];
    }

    /**
     * Captura en PayPal Sandbox la orden ya aprobada y, si se cobró completo y coincide con
     * la compra, da el acceso. Si PayPal la deja PENDING, la compra sigue pendiente.
     *
     * @throws BusinessRuleException PAYMENT_DECLINED, PAYMENT_MISMATCH, PURCHASE_NOT_PENDING
     * @throws PaymentProviderException
     */
    public function capturePayPal(AppUser $student, string $purchaseId): Purchase
    {
        $purchase = $this->purchases->get($student->id(), self::requireId($purchaseId, 'PURCHASE_NOT_FOUND'));
        if ($purchase->status() === PurchaseStatus::Completed) {
            return $purchase;
        }
        $orderId = $purchase->providerTransactionId();
        if ($purchase->provider() !== PaymentProvider::PayPal->value || $orderId === null || $purchase->status() !== PurchaseStatus::Pending) {
            throw BusinessRuleException::of('PURCHASE_NOT_PENDING');
        }

        $capture = $this->paypal->captureOrder($orderId);
        if ($capture->status === 'PENDING') {
            return $purchase;
        }
        if (!$capture->isCompleted()) {
            $this->purchases->fail($student->id(), $purchase->id(), 'failed');
            throw BusinessRuleException::of('PAYMENT_DECLINED');
        }
        if ($capture->customId !== null && $capture->customId !== $purchase->id()) {
            throw BusinessRuleException::of('PAYMENT_MISMATCH');
        }

        return $this->purchases->complete($student->id(), $purchase->id(), $orderId, $capture->amount, $capture->currency);
    }

    /** El comprador cerró la ventana de PayPal sin pagar. */
    public function cancel(AppUser $student, string $purchaseId): Purchase
    {
        return $this->purchases->fail($student->id(), self::requireId($purchaseId, 'PURCHASE_NOT_FOUND'), 'cancelled');
    }

    /**
     * Paga con la tarjeta simulada: valida los datos (sin cobrar) y completa la compra.
     * La tarjeta 4000 0000 0000 0002 siempre se rechaza.
     *
     * @param array<string, mixed> $input {curso, nivel?, titular, numero, vencimiento, cvv}
     * @throws ValidationException|BusinessRuleException CARD_DECLINED
     */
    public function payWithCard(AppUser $student, array $input): Purchase
    {
        [$courseId, $levelId] = self::target($input, withLevel: true);
        $this->requireAvailable(PaymentProvider::SimulatedCard);
        $card = $this->cards->validate($input, $this->now());

        $purchase = $this->purchases->start($student->id(), $courseId, $levelId, PaymentProvider::SimulatedCard->value, self::key('card'));
        if ($card['declined']) {
            $this->purchases->fail($student->id(), $purchase->id(), 'failed');
            throw BusinessRuleException::of('CARD_DECLINED');
        }

        $transaction = sprintf('SIM-%s-%s', strtoupper(bin2hex(random_bytes(6))), $card['last4']);

        return $this->purchases->complete($student->id(), $purchase->id(), $transaction, $purchase->amount(), $purchase->currency());
    }

    private function requireAvailable(PaymentProvider $provider): void
    {
        if (!in_array($provider, $this->methods->available(), true)) {
            throw BusinessRuleException::of('PAYMENT_METHOD_UNAVAILABLE');
        }
    }

    /**
     * @param array<string, mixed> $input {curso, nivel?}
     * @return array{0: string, 1: ?string} [curso, nivel o null]
     */
    private static function target(array $input, bool $withLevel): array
    {
        $course = $input['curso'] ?? null;
        $level = $withLevel ? ($input['nivel'] ?? null) : null;
        $course = is_int($course) ? (string) $course : $course;
        $level = is_int($level) ? (string) $level : $level;

        $errors = array_filter([
            'curso' => is_string($course) && self::isId($course) ? null : 'Curso inválido.',
            'nivel' => $level === null || $level === '' || (is_string($level) && self::isId($level)) ? null : 'Nivel inválido.',
        ]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$course, $level === '' ? null : $level];
    }

    private static function description(Purchase $purchase): string
    {
        return $purchase->levelTitleSnapshot() === null
            ? 'Cursology: ' . $purchase->courseTitleSnapshot() . ' (curso completo)'
            : 'Cursology: ' . $purchase->courseTitleSnapshot() . ' - ' . $purchase->levelTitleSnapshot();
    }

    private static function key(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(16));
    }

    private static function isId(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,19}$/D', $value) === 1;
    }

    private static function requireId(string $value, string $code): string
    {
        return self::isId($value) ? $value : throw BusinessRuleException::of($code);
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TIME_ZONE));
    }
}
