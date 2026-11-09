<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use LogicException;

/**
 * Escritor mínimo de PDF 1.4, sin librerías: páginas con texto en las fuentes estándar
 * Helvetica y Helvetica-Bold (WinAnsiEncoding, así que acentos, ñ y ¿¡ se ven bien),
 * rectángulos, líneas y colores RGB. Las coordenadas son en puntos (1/72 pulgada) con el
 * origen abajo a la izquierda, como en PDF.
 */
final class PdfDocument
{
    public const A4_LANDSCAPE = [841.89, 595.28];

    /** Anchos de Helvetica (1/1000 del tamaño) para los códigos 32..126 de WinAnsi. */
    private const REGULAR_ASCII = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    /** Anchos de Helvetica para los códigos 128..255 de WinAnsi. */
    private const REGULAR_HIGH = [
        556, 350, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333, 1000, 350, 611, 350,
        350, 222, 222, 333, 333, 350, 556, 1000, 333, 1000, 500, 333, 944, 350, 500, 667,
        278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 333, 737, 333,
        400, 584, 333, 333, 333, 556, 537, 278, 333, 333, 365, 556, 834, 834, 834, 611,
        667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
        722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
        556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 584, 611, 556, 556, 556, 556, 500, 556, 500,
    ];

    /** Anchos de Helvetica-Bold para los códigos 32..126 de WinAnsi. */
    private const BOLD_ASCII = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
    ];

    /** Anchos de Helvetica-Bold para los códigos 128..255 de WinAnsi. */
    private const BOLD_HIGH = [
        556, 350, 278, 556, 500, 1000, 556, 556, 333, 1000, 667, 333, 1000, 350, 611, 350,
        350, 278, 278, 500, 500, 350, 556, 1000, 333, 1000, 556, 333, 944, 350, 500, 667,
        278, 333, 556, 556, 556, 556, 280, 556, 333, 737, 370, 556, 584, 333, 737, 333,
        400, 584, 333, 333, 333, 611, 556, 278, 333, 333, 365, 556, 834, 834, 834, 611,
        722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
        722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
        556, 556, 556, 556, 556, 556, 889, 556, 556, 556, 556, 556, 278, 278, 278, 278,
        611, 611, 611, 611, 611, 611, 611, 584, 611, 611, 611, 611, 611, 556, 611, 556,
    ];

    /** @var list<string> contenido de cada página */
    private array $pages = [];
    private string $title = '';

    public function __construct(
        public readonly float $width = self::A4_LANDSCAPE[0],
        public readonly float $height = self::A4_LANDSCAPE[1],
    ) {
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function addPage(): void
    {
        $this->pages[] = '';
    }

    public function fillColor(int $red, int $green, int $blue): void
    {
        $this->draw(sprintf('%s %s %s rg', self::color($red), self::color($green), self::color($blue)));
    }

    public function strokeColor(int $red, int $green, int $blue): void
    {
        $this->draw(sprintf('%s %s %s RG', self::color($red), self::color($green), self::color($blue)));
    }

    /** Rectángulo desde su esquina inferior izquierda; relleno con fillColor, borde con strokeColor. */
    public function rect(float $x, float $y, float $width, float $height, bool $fill, bool $stroke, float $lineWidth = 1.0): void
    {
        $operator = match (true) {
            $fill && $stroke => 'B',
            $fill => 'f',
            default => 'S',
        };
        $this->draw(sprintf('%s w %s %s %s %s re %s', self::number($lineWidth), self::number($x), self::number($y), self::number($width), self::number($height), $operator));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $lineWidth = 1.0): void
    {
        $this->draw(sprintf('%s w %s %s m %s %s l S', self::number($lineWidth), self::number($x1), self::number($y1), self::number($x2), self::number($y2)));
    }

    /** Texto UTF-8 con su línea base en (x, y), en el color de fillColor. */
    public function text(float $x, float $y, float $size, bool $bold, string $text): void
    {
        $this->draw(sprintf(
            'BT /%s %s Tf %s %s Td (%s) Tj ET',
            $bold ? 'F2' : 'F1',
            self::number($size),
            self::number($x),
            self::number($y),
            self::escape(self::winAnsi($text)),
        ));
    }

    /** Texto centrado en $centerX (por omisión, el centro de la página). */
    public function centeredText(float $y, float $size, bool $bold, string $text, ?float $centerX = null): void
    {
        $centerX ??= $this->width / 2;
        $this->text($centerX - self::textWidth($text, $size, $bold) / 2, $y, $size, $bold, $text);
    }

    /** Ancho en puntos del texto UTF-8 con la fuente y tamaño dados. */
    public static function textWidth(string $text, float $size, bool $bold): float
    {
        [$ascii, $high] = $bold ? [self::BOLD_ASCII, self::BOLD_HIGH] : [self::REGULAR_ASCII, self::REGULAR_HIGH];
        $units = 0;
        foreach (str_split(self::winAnsi($text)) as $byte) {
            $code = ord($byte);
            $units += match (true) {
                $code >= 32 && $code <= 126 => $ascii[$code - 32],
                $code >= 128 => $high[$code - 128],
                default => 0,
            };
        }

        return $units * $size / 1000;
    }

    /**
     * El tamaño más grande (de $size hacia abajo, hasta $minSize) con el que el texto cabe en
     * $maxWidth. Si ni con $minSize cabe, lo recorta y agrega "...".
     *
     * @return array{0: float, 1: string} [tamaño, texto]
     */
    public static function fit(string $text, float $size, bool $bold, float $maxWidth, float $minSize): array
    {
        for ($current = $size; $current >= $minSize; $current -= 0.5) {
            if (self::textWidth($text, $current, $bold) <= $maxWidth) {
                return [$current, $text];
            }
        }

        $chars = mb_str_split($text);
        while ($chars !== [] && self::textWidth(implode('', $chars) . '...', $minSize, $bold) > $maxWidth) {
            array_pop($chars);
        }

        return [$minSize, rtrim(implode('', $chars)) . '...'];
    }

    /** El documento completo (PDF 1.4 con su tabla xref). */
    public function output(): string
    {
        if ($this->pages === []) {
            throw new LogicException('El PDF no tiene páginas.');
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => sprintf('<< /Producer (Cursology) /Title (%s) >>', self::escape(self::winAnsi($this->title))),
        ];
        $kids = [];
        foreach ($this->pages as $content) {
            $pageId = count($objects) + 1;
            $contentId = $pageId + 1;
            $kids[] = $pageId . ' 0 R';
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::number($this->width),
                self::number($this->height),
                $contentId,
            );
            $objects[$contentId] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content);
        }
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), count($kids));

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $id, $body);
        }

        $xref = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", count($objects) + 1);
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . sprintf("trailer\n<< /Size %d /Root 1 0 R /Info 5 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $xref);
    }

    /** UTF-8 a Windows-1252 (WinAnsi); lo que no existe ahí queda como "?". */
    public static function winAnsi(string $text): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text);
        $previous = mb_substitute_character();
        mb_substitute_character(0x3F);
        $converted = mb_convert_encoding($clean, 'Windows-1252', 'UTF-8');
        mb_substitute_character($previous);

        return $converted;
    }

    /** Escapa una cadena literal de PDF: \, ( y ). */
    public static function escape(string $bytes): string
    {
        return strtr($bytes, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private function draw(string $operator): void
    {
        if ($this->pages === []) {
            $this->addPage();
        }
        $this->pages[array_key_last($this->pages)] .= $operator . "\n";
    }

    private static function number(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }

    private static function color(int $channel): string
    {
        return self::number(max(0, min(255, $channel)) / 255);
    }
}
