<?php

declare(strict_types=1);

// Funciones para imprimir datos en las vistas. No tocan la base de datos ni la sesión.

use App\Models\Enums\UserRole;
use App\Services\PricingMode;

/** Zona horaria en la que se muestran las fechas (en la BD se guardan en UTC). */
const DISPLAY_TIME_ZONE = 'America/Monterrey';

/** Escapa texto para imprimirlo en HTML. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Fecha con el formato de la rúbrica: día, mes en tres letras y año de cuatro dígitos
 * ("18 oct 2026"). Acepta un DATETIME ya hidratado (UTC) o un DATE 'Y-m-d'.
 */
function format_date(DateTimeInterface|string|null $date): string
{
    if ($date === null) {
        return '—';
    }

    $zone = new DateTimeZone(DISPLAY_TIME_ZONE);
    $local = is_string($date)
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone)
        : DateTimeImmutable::createFromInterface($date)->setTimezone($zone);
    if ($local === false) {
        return '—';
    }

    $months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    return sprintf('%d %s %s', (int) $local->format('j'), $months[(int) $local->format('n') - 1], $local->format('Y'));
}

/**
 * Importe con el formato de la rúbrica: símbolo, separador de miles y dos decimales
 * ("$1,249.90 MXN"). Trabaja sobre la cadena DECIMAL exacta, sin pasar por float.
 */
function format_money(?string $amount, string $currency = 'MXN'): string
{
    if ($amount === null || preg_match('/^(-?)([0-9]+)\.([0-9]{2})$/D', $amount, $parts) !== 1) {
        return '—';
    }

    $integer = (string) preg_replace('/\B(?=([0-9]{3})+(?![0-9]))/', ',', $parts[2]);

    return sprintf('%s$%s.%s %s', $parts[1], $integer, $parts[3], $currency);
}

/**
 * Precio de un curso para el catálogo según su forma de cobro: "Gratis", el del curso
 * completo, o "Por niveles · $X" (la suma de sus niveles; "Gratis" si todos cuestan 0).
 */
function course_price_label(?string $fullCoursePrice, string $levelPriceTotal, string $currency = 'MXN'): string
{
    return match (PricingMode::fromFullCoursePrice($fullCoursePrice)) {
        PricingMode::Free => 'Gratis',
        PricingMode::Bundle => format_money($fullCoursePrice, $currency),
        PricingMode::Levels => $levelPriceTotal === '0.00' ? 'Gratis' : 'Por niveles · ' . format_money($levelPriceTotal, $currency),
    };
}

/** Tamaño legible de un BIGINT UNSIGNED en bytes ("2.4 MB"). */
function format_bytes(?string $bytes): string
{
    if ($bytes === null) {
        return '—';
    }

    $value = (float) $bytes;
    $units = ['B', 'KB', 'MB', 'GB'];
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return ($unit === 0 ? (string) (int) $value : number_format($value, 1)) . ' ' . $units[$unit];
}

/** Duración en segundos como "m:ss" o "h:mm:ss". */
function format_duration(?int $seconds): string
{
    if ($seconds === null) {
        return '—';
    }

    return $seconds >= 3600
        ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
        : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
}

function role_label(UserRole $role): string
{
    return match ($role) {
        UserRole::Student => 'Alumno',
        UserRole::Instructor => 'Instructor',
        UserRole::Admin => 'Administrador',
    };
}
