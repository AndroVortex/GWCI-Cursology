<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Models\Entities\AppUser;
use App\Services\PaymentMethodService;

/** Endpoints /api/admin/payment-methods (solo admin). */
final class PaymentMethodController
{
    public function __construct(private readonly PaymentMethodService $methods)
    {
    }

    /** GET /api/admin/payment-methods */
    public function list(Request $request): JsonResponse
    {
        return JsonResponse::data(['methods' => array_map(static fn (array $row): array => [
            'code' => $row['provider']->value,
            'name' => $row['provider']->label(),
            'description' => $row['provider']->description(),
            'active' => $row['active'],
            'ready' => $row['ready'],
            'note' => $row['note'],
        ], $this->methods->overview())]);
    }

    /** POST /api/admin/payment-methods ({codigo, activa}) */
    public function setActive(Request $request): JsonResponse
    {
        /** @var AppUser $admin */
        $admin = $request->attribute('user');
        $method = $this->methods->setActive($admin, $request->json());

        return JsonResponse::data(['method' => ['code' => $method->code(), 'name' => $method->methodName(), 'active' => $method->active()]]);
    }
}
