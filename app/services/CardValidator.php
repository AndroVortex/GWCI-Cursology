<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Exceptions\ValidationException;
use App\Services\Validation\UserRules;
use DateTimeImmutable;

/**
 * Tarjeta simulada: valida titular, número (algoritmo de Luhn), vencimiento y CVV sin
 * cobrar nada. El número completo nunca se guarda: solo se usan los últimos 4 dígitos.
 */
final class CardValidator
{
    /** Número de prueba que siempre se rechaza, para probar el error de pago. */
    public const DECLINED_NUMBER = '4000000000000002';

    private const MAX_HOLDER_CHARS = 100;

    /**
     * @param array<string, mixed> $input {titular, numero, vencimiento: MM/AA, cvv}
     * @return array{last4: string, brand: string, declined: bool}
     * @throws ValidationException
     */
    public function validate(array $input, DateTimeImmutable $today): array
    {
        $holder = UserRules::text($input, 'titular');
        $number = (string) preg_replace('/[\s-]+/', '', UserRules::text($input, 'numero'));
        $expiry = UserRules::text($input, 'vencimiento');
        $cvv = UserRules::text($input, 'cvv');

        $errors = array_filter([
            'titular' => match (true) {
                $holder === '' => 'Escribe el nombre como aparece en la tarjeta.',
                mb_strlen($holder) > self::MAX_HOLDER_CHARS => sprintf('No puede exceder %d caracteres.', self::MAX_HOLDER_CHARS),
                default => null,
            },
            'numero' => preg_match('/^[0-9]{13,19}$/D', $number) === 1 && self::luhn($number) ? null : 'Revisa el número de la tarjeta.',
            'vencimiento' => self::expiryError($expiry, $today),
            'cvv' => preg_match('/^[0-9]{3,4}$/D', $cvv) === 1 ? null : 'El CVV son 3 o 4 dígitos.',
        ]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'last4' => substr($number, -4),
            'brand' => self::brand($number),
            'declined' => $number === self::DECLINED_NUMBER,
        ];
    }

    /** Algoritmo de Luhn sobre una cadena de dígitos. */
    public static function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = !$double;
        }

        return $digits !== '' && $sum % 10 === 0;
    }

    private static function brand(string $number): string
    {
        return match (true) {
            str_starts_with($number, '4') => 'Visa',
            preg_match('/^(5[1-5]|2[2-7])/', $number) === 1 => 'Mastercard',
            preg_match('/^3[47]/', $number) === 1 => 'American Express',
            default => 'Tarjeta',
        };
    }

    /** MM/AA; vence al terminar ese mes. */
    private static function expiryError(string $expiry, DateTimeImmutable $today): ?string
    {
        if (preg_match('#^(0[1-9]|1[0-2])\s*/?\s*([0-9]{2})$#D', $expiry, $parts) !== 1) {
            return 'Escribe el vencimiento como MM/AA.';
        }

        $lastDay = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('20%s-%s-01', $parts[2], $parts[1]), $today->getTimezone());

        return $lastDay === false || $lastDay->modify('last day of this month')->format('Y-m-d') < $today->format('Y-m-d')
            ? 'La tarjeta está vencida.'
            : null;
    }
}
