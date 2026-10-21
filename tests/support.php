<?php

declare(strict_types=1);

/**
 * Utilidades comunes de los scripts de verificación (sin dependencias).
 * Cualquier warning o notice se vuelve excepción, salvo los silenciados con @.
 */

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require dirname(__DIR__) . '/app/autoload.php';

final class Report
{
    private static int $total = 0;
    /** @var list<string> */
    private static array $failures = [];
    private static ?string $section = null;
    private static int $sectionTotal = 0;
    private static int $sectionFailures = 0;

    public static function section(string $title): void
    {
        self::closeSection();
        self::$section = $title;
        self::$sectionTotal = 0;
        self::$sectionFailures = 0;
    }

    public static function check(bool $ok, string $label): void
    {
        self::$total++;
        self::$sectionTotal++;
        if (!$ok) {
            self::$failures[] = '[' . self::$section . '] ' . $label;
            self::$sectionFailures++;
        }
    }

    public static function finish(): never
    {
        self::closeSection();

        if (self::$failures !== []) {
            echo PHP_EOL, 'Fallas:', PHP_EOL;
            foreach (self::$failures as $failure) {
                echo '  - ', $failure, PHP_EOL;
            }
            printf("%sRESULTADO: FALLÓ (%d de %d comprobaciones)%s", PHP_EOL, count(self::$failures), self::$total, PHP_EOL);
            exit(1);
        }

        printf("%sRESULTADO: OK (%d comprobaciones)%s", PHP_EOL, self::$total, PHP_EOL);
        exit(0);
    }

    private static function closeSection(): void
    {
        if (self::$section === null) {
            return;
        }
        $status = self::$sectionFailures > 0 ? sprintf('%d FALLAS', self::$sectionFailures) : 'ok';
        printf("%-48s %6d comprobaciones  %s%s", self::$section, self::$sectionTotal, $status, PHP_EOL);
    }
}

function check(bool $ok, string $label): void
{
    Report::check($ok, $label);
}

/**
 * Ejecuta $callback y devuelve la excepción lanzada si es de la clase esperada.
 *
 * @template T of Throwable
 * @param class-string<T> $class
 * @return T|null
 */
function thrown(string $class, callable $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $exception) {
        return $exception instanceof $class ? $exception : throw $exception;
    }

    return null;
}
