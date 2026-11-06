<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Entities\Certificate;
use App\Services\Pdf\PdfDocument;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Diseño fijo del certificado de Cursology (plantilla "cursology-v1"), en A4 horizontal,
 * con los datos congelados del certificado: nada se lee del curso ni del alumno actuales.
 */
final class CertificateRenderer
{
    public const TEMPLATE = 'cursology-v1';

    private const LOCAL_TIME_ZONE = 'America/Monterrey';
    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    private const ACCENT = [37, 99, 235];
    private const INK = [24, 24, 27];
    private const MUTED = [113, 113, 122];

    /** @return string el PDF */
    public function render(Certificate $certificate, string $verifyUrl): string
    {
        $pdf = new PdfDocument();
        $pdf->setTitle('Certificado: ' . $certificate->courseTitleSnapshot());
        $pdf->addPage();
        $width = $pdf->width;
        $height = $pdf->height;
        $center = $width / 2;

        $pdf->strokeColor(...self::ACCENT);
        $pdf->rect(24, 24, $width - 48, $height - 48, false, true, 3);
        $pdf->rect(34, 34, $width - 68, $height - 68, false, true, 0.8);

        $pdf->fillColor(...self::ACCENT);
        $pdf->centeredText(515, 22, true, 'Cursology');
        $pdf->line($center - 40, 503, $center + 40, 503, 1.5);

        $pdf->fillColor(...self::INK);
        $pdf->centeredText(450, 28, true, 'CERTIFICADO DE FINALIZACIÓN');

        $pdf->fillColor(...self::MUTED);
        $pdf->centeredText(400, 14, false, 'Se otorga a');

        $pdf->fillColor(...self::INK);
        [$nameSize, $name] = PdfDocument::fit($certificate->studentNameSnapshot(), 34, true, 680, 18);
        $pdf->centeredText(352, $nameSize, true, $name);
        $pdf->strokeColor(...self::MUTED);
        $pdf->line($center - 220, 338, $center + 220, 338, 0.6);

        $pdf->fillColor(...self::MUTED);
        $pdf->centeredText(305, 14, false, 'por completar el curso');
        $pdf->fillColor(...self::INK);
        [$titleSize, $title] = PdfDocument::fit($certificate->courseTitleSnapshot(), 22, true, 700, 12);
        $pdf->centeredText(272, $titleSize, true, $title);

        $leftCenter = 230;
        $rightCenter = $width - 230;
        [$certifierSize, $certifier] = PdfDocument::fit($certificate->certifierNameSnapshot(), 13, true, 260, 9);
        $pdf->centeredText(176, $certifierSize, true, $certifier, $leftCenter);
        $pdf->centeredText(176, 13, true, self::longDate($certificate->completedAtSnapshot()), $rightCenter);
        $pdf->strokeColor(...self::INK);
        $pdf->line($leftCenter - 130, 166, $leftCenter + 130, 166, 0.6);
        $pdf->line($rightCenter - 130, 166, $rightCenter + 130, 166, 0.6);
        $pdf->fillColor(...self::MUTED);
        $pdf->centeredText(150, 10, false, 'Certifica', $leftCenter);
        $pdf->centeredText(150, 10, false, 'Fecha de finalización', $rightCenter);

        $pdf->centeredText(82, 9, false, 'Código de verificación: ' . $certificate->verificationCode());
        [$urlSize, $url] = PdfDocument::fit('Verifícalo en ' . $verifyUrl, 9, false, $width - 120, 6);
        $pdf->centeredText(68, $urlSize, false, $url);

        return $pdf->output();
    }

    /** "23 de octubre de 2026", en la hora de México. */
    public static function longDate(DateTimeImmutable $date): string
    {
        $local = $date->setTimezone(new DateTimeZone(self::LOCAL_TIME_ZONE));

        return sprintf('%d de %s de %s', (int) $local->format('j'), self::MONTHS[(int) $local->format('n') - 1], $local->format('Y'));
    }
}
