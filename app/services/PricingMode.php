<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Cómo se cobra un curso. No es una columna: se deriva de course.full_course_price
 * (NULL = por niveles, 0.00 = gratis, importe = curso completo + niveles).
 * Los valores son los del campo precio_modo del formulario.
 */
enum PricingMode: string
{
    case Free = 'gratis';
    case Levels = 'niveles';
    case Bundle = 'paquete';

    public static function fromFullCoursePrice(?string $price): self
    {
        return match (true) {
            $price === null => self::Levels,
            preg_match('/^0+(\.0+)?$/D', $price) === 1 => self::Free,
            default => self::Bundle,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Gratis',
            self::Levels => 'Por niveles',
            self::Bundle => 'Curso completo + niveles',
        };
    }
}
