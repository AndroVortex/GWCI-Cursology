<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Models\Entities\AppUser;
use App\Models\Entities\Purchase;
use App\Models\Enums\PurchaseStatus;
use App\Services\CheckoutService;

/**
 * Inscripción y compras del alumno. Las rutas pasan por RequireAuth + RequireRole(student)
 * y CSRF. PayPal es solo Sandbox (dinero de prueba); la tarjeta es simulada.
 */
final class CheckoutController
{
    public function __construct(private readonly CheckoutService $checkout)
    {
    }

    /** POST /api/student/enrollments ({curso}) */
    public function enrollFree(Request $request): JsonResponse
    {
        $input = $request->json();
        $granted = $this->checkout->enrollFree($this->user($request), $input);

        return JsonResponse::data(['courseId' => (string) $input['curso'], 'levelsGranted' => $granted], 201);
    }

    /** POST /api/checkout/paypal/orders ({curso, nivel?}) */
    public function startPayPal(Request $request): JsonResponse
    {
        ['purchase' => $purchase, 'orderId' => $orderId] = $this->checkout->startPayPal($this->user($request), $request->json());

        return JsonResponse::data(['purchase' => self::present($purchase), 'orderId' => $orderId], 201);
    }

    /** POST /api/checkout/paypal/purchases/{id}/capture */
    public function capturePayPal(Request $request): JsonResponse
    {
        $purchase = $this->checkout->capturePayPal($this->user($request), (string) $request->attribute('id'));

        return JsonResponse::data(['purchase' => self::present($purchase)], $purchase->status() === PurchaseStatus::Completed ? 200 : 202);
    }

    /** POST /api/checkout/purchases/{id}/cancel */
    public function cancel(Request $request): JsonResponse
    {
        return JsonResponse::data(['purchase' => self::present($this->checkout->cancel($this->user($request), (string) $request->attribute('id')))]);
    }

    /** POST /api/checkout/card ({curso, nivel?, titular, numero, vencimiento, cvv}) */
    public function payWithCard(Request $request): JsonResponse
    {
        return JsonResponse::data(['purchase' => self::present($this->checkout->payWithCard($this->user($request), $request->json()))], 201);
    }

    /** @return array<string, mixed> */
    public static function present(Purchase $purchase): array
    {
        return [
            'id' => $purchase->id(),
            'courseId' => $purchase->courseId(),
            'levelId' => $purchase->courseLevelId(),
            'scope' => $purchase->purchaseScope()->value,
            'courseTitle' => $purchase->courseTitleSnapshot(),
            'levelTitle' => $purchase->levelTitleSnapshot(),
            'amount' => $purchase->amount(),
            'currency' => $purchase->currency(),
            'method' => $purchase->paymentMethodNameSnapshot(),
            'status' => $purchase->status()->value,
            'createdAt' => $purchase->createdAt()->format(DATE_ATOM),
            'completedAt' => $purchase->completedAt()?->format(DATE_ATOM),
        ];
    }

    private function user(Request $request): AppUser
    {
        /** @var AppUser */
        return $request->attribute('user');
    }
}
