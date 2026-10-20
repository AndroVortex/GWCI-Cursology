<?php

declare(strict_types=1);

/**
 * Verificación del mapeo de modelos de Cursology.
 *
 * No usa base de datos, red ni dependencias: compara los metadatos de las
 * entidades contra el DDL y prueba la hidratación con filas sintéticas.
 *
 *   php tests/verify_model_mapping.php [ruta/al/Cursology_modelo_fisico_mysql.sql]
 *
 * Termina con código 0 si todas las comprobaciones pasan y 1 si alguna falla.
 */

use App\Models\Entities\AppUser;
use App\Models\Entities\Course;
use App\Models\Entities\CourseChapterContent;
use App\Models\Entities\TestAttempt;
use App\Models\Entities\TestAttemptAnswer;
use App\Models\Entities\TestQuestionAnswer;
use App\Models\Enums\AccountEventType;
use App\Models\Enums\AccountStatus;
use App\Models\Enums\CertificateStatus;
use App\Models\Enums\ContentType;
use App\Models\Enums\LoginOutcome;
use App\Models\Enums\PurchaseScope;
use App\Models\Enums\PurchaseStatus;
use App\Models\Enums\SelectionMode;
use App\Models\Enums\UserRole;
use App\Models\Mapping\Column;
use App\Models\Mapping\Entity;
use App\Models\Mapping\EntityMap;
use App\Models\Mapping\HydrationException;

/** Columnas generadas que el DDL deja nullable pero cuya expresión nunca produce NULL. */
const GENERATED_NOT_NULL = ['app_user.active'];

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$root = dirname(__DIR__);
require $root . '/app/autoload.php';

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

// ---------------------------------------------------------------
// Lectura del DDL
// ---------------------------------------------------------------

/** @return list<string> */
function splitColumns(string $list): array
{
    return array_map('trim', explode(',', $list));
}

/**
 * @return array<string, array{
 *     columns: array<string, array{type: string, nullable: bool, autoIncrement: bool, generated: bool, default: ?string, onUpdate: ?string}>,
 *     primaryKey: list<string>,
 *     uniqueKeys: list<array{0: string, 1: list<string>}>,
 *     foreignKeys: list<array{0: string, 1: list<string>, 2: string, 3: list<string>}>,
 *     checkValues: array<string, list<string>>
 * }>
 */
function parseDdl(string $sql): array
{
    preg_match_all('/CREATE TABLE `([a-z_]+)` \((.*?)\R\) ENGINE=InnoDB;/s', $sql, $blocks, PREG_SET_ORDER);

    $columnPattern = '/^([a-z_][a-z0-9_]*) (BIGINT|INT|TINYINT|BOOLEAN|DECIMAL|VARCHAR|CHAR|LONGTEXT|TEXT|DATETIME|DATE)\b'
        . '(\([0-9, ]+\))?( UNSIGNED)?(.*)$/';

    $tables = [];
    foreach ($blocks as [, $table, $body]) {
        $info = ['columns' => [], 'primaryKey' => [], 'uniqueKeys' => [], 'foreignKeys' => [], 'checkValues' => []];

        foreach (preg_split('/\R/', $body) as $line) {
            $line = trim($line);
            if (preg_match('/^PRIMARY KEY \(([^)]+)\)/', $line, $m)) {
                $info['primaryKey'] = splitColumns($m[1]);
            } elseif (preg_match('/^UNIQUE KEY ([a-z_]+) \(([^)]+)\)/', $line, $m)) {
                $info['uniqueKeys'][] = [$m[1], splitColumns($m[2])];
            } elseif (preg_match('/^CONSTRAINT ([a-z_]+) FOREIGN KEY \(([^)]+)\) REFERENCES ([a-z_]+) \(([^)]+)\)/', $line, $m)) {
                $info['foreignKeys'][] = [$m[1], splitColumns($m[2]), $m[3], splitColumns($m[4])];
            } elseif (preg_match($columnPattern, $line, $m)) {
                $rest = $m[5];
                $info['columns'][$m[1]] = [
                    'type' => $m[2] . str_replace(' ', '', $m[3]) . $m[4],
                    'nullable' => !str_contains($rest, 'NOT NULL'),
                    'autoIncrement' => str_contains($rest, 'AUTO_INCREMENT'),
                    'generated' => str_contains($rest, 'GENERATED ALWAYS AS'),
                    'default' => preg_match("/ DEFAULT ('[^']*'|[A-Z_]+\([0-9]+\)|[A-Z]+|[0-9.]+)/", $rest, $d) === 1 ? $d[1] : null,
                    'onUpdate' => preg_match('/ ON UPDATE ([A-Z_]+\([0-9]+\))/', $rest, $u) === 1 ? $u[1] : null,
                ];
            }
        }

        foreach (array_keys($info['columns']) as $column) {
            $values = [];
            if (preg_match_all('/\b' . $column . ' IN \(([^)]*)\)/', $body, $lists) > 0) {
                foreach ($lists[1] as $list) {
                    preg_match_all("/'([^']*)'/", $list, $quoted);
                    $values = array_merge($values, $quoted[1]);
                }
            }
            if (preg_match_all('/\b' . $column . " = '([^']*)'/", $body, $equals) > 0) {
                $values = array_merge($values, $equals[1]);
            }
            if ($values !== []) {
                $info['checkValues'][$column] = array_values(array_unique($values));
            }
        }

        $tables[$table] = $info;
    }

    return $tables;
}

// ---------------------------------------------------------------
// Utilidades de hidratación
// ---------------------------------------------------------------

/** @return array{base: string, length: ?int, scale: ?int, unsigned: bool} */
function sqlTypeInfo(string $type): array
{
    preg_match('/^([A-Z]+)(?:\(([0-9]+)(?:,([0-9]+))?\))?( UNSIGNED)?$/', $type, $m);

    return [
        'base' => $m[1],
        'length' => isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null,
        'scale' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null,
        'unsigned' => ($m[4] ?? '') !== '',
    ];
}

function normalize(mixed $value): mixed
{
    return match (true) {
        $value instanceof DateTimeImmutable => 'datetime:' . $value->format('Y-m-d H:i:s.u') . '@' . $value->getTimezone()->getName(),
        $value instanceof BackedEnum => 'enum:' . $value::class . ':' . $value->value,
        default => $value,
    };
}

function describe(mixed $value): string
{
    if (is_string($value)) {
        return strlen($value) > 32 ? sprintf('string(%d bytes)', strlen($value)) : var_export($value, true);
    }

    return get_debug_type($value) . '(' . var_export($value, true) . ')';
}

function failsOn(string $column, callable $callback): bool
{
    try {
        $callback();
    } catch (HydrationException $e) {
        return $e->column === $column;
    }

    return false;
}

/** @param class-string<Entity> $class */
function expectValue(string $class, array $row, string $column, string $property, mixed $input, mixed $expected, string $label): void
{
    $row[$column] = $input;
    try {
        check(normalize($class::fromRow($row)->{$property}()) === normalize($expected), $label);
    } catch (HydrationException $e) {
        check(false, $label . ' -> ' . $e->getMessage());
    }
}

/** @param class-string<Entity> $class */
function expectRejected(string $class, array $row, string $column, mixed $input, string $label): void
{
    $row[$column] = $input;
    check(failsOn($column, static fn () => $class::fromRow($row)), $label);
}

function sampleValue(array $type, ?array $allowed): mixed
{
    return match ($type['base']) {
        'BIGINT' => '1',
        'INT', 'TINYINT', 'BOOLEAN' => 1,
        'DECIMAL' => '1.' . str_repeat('0', (int) $type['scale']),
        'VARCHAR', 'CHAR' => $allowed[0] ?? 'abc',
        'TEXT', 'LONGTEXT' => 'texto de prueba',
        'DATE' => '2026-10-18',
        'DATETIME' => '2026-10-18 12:34:56.123456',
    };
}

function expectedSample(Column $column, array $type, mixed $sample): mixed
{
    if ($column->enum !== null) {
        $enum = $column->enum;

        return $enum::from($sample);
    }

    return match ($type['base']) {
        'BOOLEAN' => true,
        'DATETIME' => 'datetime:2026-10-18 12:34:56.123456@UTC',
        default => $sample,
    };
}

/**
 * Casos de conversión por tipo: [aceptado, entrada, valor esperado].
 *
 * @return list<array{0: bool, 1: mixed, 2: mixed}>
 */
function typeCases(Column $column, array $type, bool $isKey, ?array $allowed): array
{
    $cases = [];
    $ok = static function (mixed $input, mixed $expected) use (&$cases): void {
        $cases[] = [true, $input, $expected];
    };
    $no = static function (mixed $input) use (&$cases): void {
        $cases[] = [false, $input, null];
    };

    switch ($type['base']) {
        case 'BIGINT':
            $ok('18446744073709551615', '18446744073709551615');
            $ok(7, '7');
            $no('18446744073709551616');
            $no('99999999999999999999');
            $no('-1');
            $no(-1);
            $no(1.0);
            $no('01');
            $no(' 1');
            $no('1e3');
            $no('NULL');
            $no(true);
            if ($isKey) {
                $no('0');
                $no(0);
            } else {
                $ok('0', '0');
                $ok(0, '0');
            }
            break;

        case 'INT':
            $ok(4294967295, 4294967295);
            $ok('42', 42);
            $ok(0, 0);
            $no(4294967296);
            $no('4294967296');
            $no(-1);
            $no('4.2');
            $no(1.0);
            $no('abc');
            $no(true);
            break;

        case 'TINYINT':
            if ($column->generated) {
                $ok(1, 1);
                $ok('1', 1);
                $no(0);
                $no(2);
            } else {
                $ok(255, 255);
                $ok('0', 0);
                $no(256);
                $no(-1);
                $no(1.0);
            }
            break;

        case 'BOOLEAN':
            $ok('0', false);
            $ok('1', true);
            $ok(0, false);
            $ok(1, true);
            $ok(true, true);
            $ok(false, false);
            $no('false');
            $no('true');
            $no(2);
            $no('');
            $no('yes');
            $no(1.0);
            $no('01');
            break;

        case 'DECIMAL':
            $scale = (int) $type['scale'];
            $integerDigits = (int) $type['length'] - $scale;
            $max = str_repeat('9', $integerDigits) . '.' . str_repeat('9', $scale);
            $ok($max, $max);
            $ok('-' . $max, '-' . $max);
            $ok(7, '7.' . str_repeat('0', $scale));
            $ok('1.5', '1.5' . str_repeat('0', $scale - 1));
            $ok('-0.00', '0.' . str_repeat('0', $scale));
            $ok('0005.10', '5.1' . str_repeat('0', $scale - 1));
            $no(str_repeat('9', $integerDigits + 1) . '.00');
            $no('1.' . str_repeat('0', $scale + 1));
            $no(1.5);
            $no('1,50');
            $no('.5');
            $no('5.');
            $no('1e2');
            $no('abc');
            $no(true);
            break;

        case 'VARCHAR':
        case 'CHAR':
            if ($allowed !== null) {
                $enum = (string) $column->enum;
                $first = $allowed[0];
                $ok($first, $enum::from($first));
                $no(strtoupper($first));
                $no(ucfirst($first));
                $no($first . ' ');
                $no('valor_inexistente');
                $no(1);
            } else {
                $length = (int) $type['length'];
                $ok(str_repeat('á', $length), str_repeat('á', $length));
                $ok('', '');
                $no(str_repeat('a', $length + 1));
                $no(5);
                $no(true);
                $no("\xC3\x28");
                if ($length >= 4) {
                    $ok('NULL', 'NULL');
                }
                if ($length >= 16) {
                    $ok('  con espacios  ', '  con espacios  ');
                }
            }
            break;

        case 'TEXT':
            $ok(str_repeat('a', 65535), str_repeat('a', 65535));
            $ok("  línea 1\nlínea 2  ", "  línea 1\nlínea 2  ");
            $no(str_repeat('a', 65536));
            $no(5);
            $no("\xC3\x28");
            break;

        case 'LONGTEXT':
            $ok(str_repeat('a', 70000), str_repeat('a', 70000));
            $no(5);
            break;

        case 'DATE':
            $ok('2024-02-29', '2024-02-29');
            $no('2026-02-30');
            $no('2026-13-01');
            $no('0000-00-00');
            $no('2026-10-18 00:00:00');
            $no('18/10/2026');
            $no('2026-1-5');
            $no(20261018);
            break;

        case 'DATETIME':
            $ok('2026-10-18 12:34:56', 'datetime:2026-10-18 12:34:56.000000@UTC');
            $ok('2026-10-18 23:59:59.999999', 'datetime:2026-10-18 23:59:59.999999@UTC');
            $ok('2026-10-18 12:34:56.5', 'datetime:2026-10-18 12:34:56.500000@UTC');
            $no('2026-02-30 10:00:00');
            $no('2026-10-18 24:00:00');
            $no('2026-10-18 12:60:00');
            $no('2026-10-18 12:34:56.1234567');
            $no('2026-10-18T12:34:56');
            $no('0000-00-00 00:00:00');
            $no('2026-10-18');
            $no(1729254896);
            break;
    }

    return $cases;
}

// ---------------------------------------------------------------
// Verificación
// ---------------------------------------------------------------

try {
    $ddlPath = $argv[1] ?? $root . '/app/database/schema/Cursology_modelo_fisico_mysql.sql';
    if (!is_file($ddlPath)) {
        fwrite(STDERR, "No se encontró el DDL en: $ddlPath" . PHP_EOL);
        exit(1);
    }
    $ddl = parseDdl((string) file_get_contents($ddlPath));
    $entities = EntityMap::all();

    // -----------------------------------------------------------
    Report::section('Autoload y archivos');
    foreach ($entities as $class) {
        check(class_exists($class), "$class se carga con app/autoload.php");
        check(is_subclass_of($class, Entity::class), "$class extiende Entity");
    }
    $enums = [
        UserRole::class, AccountStatus::class, LoginOutcome::class, AccountEventType::class, ContentType::class,
        SelectionMode::class, PurchaseScope::class, PurchaseStatus::class, CertificateStatus::class,
    ];
    foreach ($enums as $enum) {
        check(enum_exists($enum), "$enum se carga con app/autoload.php");
    }
    $entityFiles = array_values(array_filter(
        scandir($root . '/app/models/entities'),
        static fn (string $file): bool => str_ends_with($file, '.php'),
    ));
    $expectedFiles = array_map(
        static fn (string $class): string => substr((string) strrchr($class, '\\'), 1) . '.php',
        array_values($entities),
    );
    sort($entityFiles);
    sort($expectedFiles);
    check($entityFiles === $expectedFiles, 'app/models/entities contiene exactamente las 29 entidades');
    $modelDirs = scandir($root . '/app/models');
    foreach (['entities', 'enums', 'mapping'] as $dir) {
        check(in_array($dir, $modelDirs, true), "app/models/$dir existe en minúsculas, como espera el autoload");
    }

    // -----------------------------------------------------------
    Report::section('Inventario del DDL');
    $sum = static fn (callable $count): int => array_sum(array_map($count, $ddl));
    $ddlColumns = $sum(static fn (array $t): int => count($t['columns']));
    $ddlFks = $sum(static fn (array $t): int => count($t['foreignKeys']));
    $ddlFkPairs = $sum(static fn (array $t): int => array_sum(array_map(static fn (array $fk): int => count($fk[1]), $t['foreignKeys'])));
    $ddlUniques = $sum(static fn (array $t): int => count($t['uniqueKeys']));
    $ddlCompositePks = $sum(static fn (array $t): int => count($t['primaryKey']) > 1 ? 1 : 0);
    check(count($ddl) === 29, sprintf('29 tablas en el DDL (encontradas: %d)', count($ddl)));
    check($ddlColumns === 254, "254 columnas en el DDL (encontradas: $ddlColumns)");
    check($ddlFks === 61, "61 FK en el DDL (encontradas: $ddlFks)");
    check($ddlFkPairs === 79, "79 pares de columnas en las FK (encontrados: $ddlFkPairs)");
    check($ddlUniques === 28, "28 índices UNIQUE en el DDL (encontrados: $ddlUniques)");
    check($ddlCompositePks === 6, "6 PK compuestas en el DDL (encontradas: $ddlCompositePks)");

    $mappedColumns = array_sum(array_map(static fn (string $c): int => count($c::metadata()->columns), $entities));
    $mappedFks = array_sum(array_map(static fn (string $c): int => count($c::metadata()->foreignKeys), $entities));
    check(array_keys($entities) === array_keys($ddl), 'EntityMap cubre las 29 tablas en el orden del DDL');
    check($mappedColumns === 254, "254 columnas mapeadas (mapeadas: $mappedColumns)");
    check($mappedFks === 61, "61 FK registradas como restricciones (registradas: $mappedFks)");

    // -----------------------------------------------------------
    Report::section('Metadatos contra el DDL');
    foreach ($entities as $table => $class) {
        $meta = $class::metadata();
        $source = $ddl[$table] ?? null;
        check($meta->table === $table, "$class::metadata() apunta a $table");
        if ($source === null) {
            check(false, "$table no existe en el DDL");
            continue;
        }

        check($meta->columnNames() === array_keys($source['columns']), "$table: columnas y orden");
        foreach ($meta->columns as $column) {
            $expected = $source['columns'][$column->name] ?? null;
            $label = "$table.{$column->name}";
            if ($expected === null) {
                check(false, "$label no existe en el DDL");
                continue;
            }
            check($column->sqlType === $expected['type'], "$label: tipo {$expected['type']}");
            check($column->nullable === $expected['nullable'], "$label: nulabilidad");
            check($column->autoIncrement === $expected['autoIncrement'], "$label: AUTO_INCREMENT");
            check($column->generated === $expected['generated'], "$label: columna generada");
            check($column->default === $expected['default'], "$label: DEFAULT");
            check($column->onUpdate === $expected['onUpdate'], "$label: ON UPDATE");
        }

        check($meta->primaryKey === $source['primaryKey'], "$table: PRIMARY KEY");
        check(
            array_map(static fn ($u): array => [$u->name, $u->columns], $meta->uniqueKeys) === $source['uniqueKeys'],
            "$table: UNIQUE como grupos ordenados",
        );
        check(
            array_map(static fn ($f): array => [$f->name, $f->columns, $f->referencedTable, $f->referencedColumns], $meta->foreignKeys)
                === $source['foreignKeys'],
            "$table: FK como restricciones con columnas ordenadas",
        );
        check(
            $meta->autoIncrementColumns() === ($meta->hasCompositePrimaryKey() ? [] : ['id']),
            "$table: AUTO_INCREMENT solo en id de PK simple",
        );
    }

    // -----------------------------------------------------------
    Report::section('CHECK de valores contra enums');
    foreach ($entities as $table => $class) {
        foreach ($class::metadata()->columns as $column) {
            $allowed = $ddl[$table]['checkValues'][$column->name] ?? null;
            $label = "$table.{$column->name}";
            if ($column->enum === null) {
                check($allowed === null, "$label: el DDL restringe valores y no tiene enum");
                continue;
            }
            $enum = $column->enum;
            $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases());
            $ddlValues = $allowed ?? [];
            sort($values);
            sort($ddlValues);
            check($allowed !== null && $values === $ddlValues, "$label: $enum coincide exactamente con el CHECK");
        }
    }

    // -----------------------------------------------------------
    Report::section('FK: destino y tipos');
    foreach ($entities as $table => $class) {
        $meta = $class::metadata();
        foreach ($meta->foreignKeys as $fk) {
            $targetClass = $entities[$fk->referencedTable] ?? null;
            check($targetClass !== null, "{$fk->name}: la tabla destino está mapeada");
            if ($targetClass === null) {
                continue;
            }
            $target = $targetClass::metadata();
            $keys = array_merge([$target->primaryKey], array_map(static fn ($u): array => $u->columns, $target->uniqueKeys));
            check(in_array($fk->referencedColumns, $keys, true), "{$fk->name}: referencia la PK o un UNIQUE completo");
            foreach ($fk->columnPairs() as $local => $remote) {
                check(
                    $meta->column($local)->sqlType === $target->column($remote)->sqlType,
                    "{$fk->name}: $local y {$fk->referencedTable}.$remote tienen el mismo tipo",
                );
            }
        }
    }

    // -----------------------------------------------------------
    Report::section('Estructura y encapsulamiento');
    foreach ($entities as $table => $class) {
        $reflection = new ReflectionClass($class);
        $meta = $class::metadata();
        check($reflection->isFinal(), "$class es final");
        check($reflection->getConstructor()?->isPrivate() === true, "$class: constructor privado (solo fromRow)");
        check($reflection->getProperties(ReflectionProperty::IS_PUBLIC) === [], "$class: sin propiedades públicas");
        check(!$reflection->implementsInterface(JsonSerializable::class), "$class no implementa JsonSerializable");
        foreach (['jsonSerialize', 'toArray', '__toString', 'save', 'delete', 'insert', 'update', 'find'] as $method) {
            check(!$reflection->hasMethod($method), "$class no define $method()");
        }
        check(count($reflection->getProperties()) === count($meta->columns), "$class: una propiedad por columna");
        if ($meta->hasCompositePrimaryKey()) {
            check(!in_array('id', $meta->columnNames(), true), "$table: PK compuesta sin id artificial");
        }

        foreach ($meta->columns as $column) {
            $label = "$table.{$column->name}";
            $hasProperty = $reflection->hasProperty($column->property);
            $hasMethod = $reflection->hasMethod($column->property);
            check($hasProperty && $reflection->getProperty($column->property)->isReadOnly(), "$label: propiedad readonly {$column->property}");
            check($hasMethod && $reflection->getMethod($column->property)->isPublic(), "$label: método {$column->property}()");
            if ($hasMethod) {
                $allowsNull = $reflection->getMethod($column->property)->getReturnType()?->allowsNull() ?? true;
                $expectedNull = $column->nullable && !in_array($label, GENERATED_NOT_NULL, true);
                check($allowsNull === $expectedNull, "$label: nulabilidad PHP coherente con el DDL");
            }
        }
    }

    // -----------------------------------------------------------
    Report::section('Hidratación completa por entidad');
    $validRows = [];
    foreach ($entities as $table => $class) {
        $meta = $class::metadata();
        $row = [];
        foreach ($meta->columns as $column) {
            $row[$column->name] = sampleValue(sqlTypeInfo($column->sqlType), $ddl[$table]['checkValues'][$column->name] ?? null);
        }
        $validRows[$table] = $row;

        try {
            $entity = $class::fromRow($row);
            check($entity instanceof $class, "$table: fromRow() devuelve $class");
            foreach ($meta->columns as $column) {
                $expected = expectedSample($column, sqlTypeInfo($column->sqlType), $row[$column->name]);
                check(
                    normalize($entity->{$column->property}()) === normalize($expected),
                    "$table.{$column->name}: valor hidratado",
                );
            }
            $class::fromRow($row + ['columna_extra' => 'x']);
            check(true, "$table: las columnas adicionales se ignoran");
        } catch (HydrationException $e) {
            check(false, "$table: fila válida rechazada -> " . $e->getMessage());
        }
    }

    // -----------------------------------------------------------
    Report::section('Columna ausente y NULL por columna');
    foreach ($entities as $table => $class) {
        foreach ($class::metadata()->columns as $column) {
            $label = "$table.{$column->name}";

            $missing = $validRows[$table];
            unset($missing[$column->name]);
            check(failsOn($column->name, static fn () => $class::fromRow($missing)), "$label: clave ausente se rechaza");

            $nullable = $column->nullable && !in_array($label, GENERATED_NOT_NULL, true);
            if ($nullable) {
                expectValue($class, $validRows[$table], $column->name, $column->property, null, null, "$label: NULL real se conserva");
            } else {
                expectRejected($class, $validRows[$table], $column->name, null, "$label: NULL en NOT NULL se rechaza");
            }
        }
    }

    // -----------------------------------------------------------
    Report::section('Conversión de tipos por columna');
    foreach ($entities as $table => $class) {
        $keyColumns = $ddl[$table]['primaryKey'];
        foreach ($ddl[$table]['foreignKeys'] as $fk) {
            $keyColumns = array_merge($keyColumns, $fk[1]);
        }

        foreach ($class::metadata()->columns as $column) {
            $type = sqlTypeInfo($column->sqlType);
            $isKey = in_array($column->name, $keyColumns, true);
            $allowed = $ddl[$table]['checkValues'][$column->name] ?? null;
            foreach (typeCases($column, $type, $isKey, $allowed) as [$accepted, $input, $expected]) {
                $label = sprintf('%s.%s: %s %s', $table, $column->name, describe($input), $accepted ? 'se acepta' : 'se rechaza');
                if ($accepted) {
                    expectValue($class, $validRows[$table], $column->name, $column->property, $input, $expected, $label);
                } else {
                    expectRejected($class, $validRows[$table], $column->name, $input, $label);
                }
            }
        }
    }

    // -----------------------------------------------------------
    Report::section('Casos críticos');
    $user = $validRows['app_user'];
    $course = $validRows['course'];
    expectValue(AppUser::class, $user, 'ban', 'ban', '0', false, "app_user.ban: '0' -> false");
    expectValue(AppUser::class, $user, 'ban', 'ban', '1', true, "app_user.ban: '1' -> true");
    expectRejected(AppUser::class, $user, 'ban', 'false', "app_user.ban: 'false' se rechaza (no se convierte en true)");
    expectValue(AppUser::class, $user, 'mother_last_name', 'motherLastName', null, null, 'NULL real permanece null');
    expectValue(AppUser::class, $user, 'mother_last_name', 'motherLastName', 'NULL', 'NULL', "la cadena 'NULL' no es NULL");
    expectRejected(AppUser::class, $user, 'banned_by', 'NULL', "la cadena 'NULL' no es un ID");
    $withoutEmail = $user;
    unset($withoutEmail['email']);
    check(failsOn('email', static fn () => AppUser::fromRow($withoutEmail)), 'campo ausente (email) se rechaza');
    expectValue(AppUser::class, $user, 'id', 'id', '18446744073709551615', '18446744073709551615', 'ID 18446744073709551615 sin pérdida');
    expectRejected(AppUser::class, $user, 'id', '18446744073709551616', 'overflow de BIGINT UNSIGNED se rechaza');
    expectValue(Course::class, $course, 'full_course_price', 'fullCoursePrice', '123.45', '123.45', "DECIMAL exacto '123.45'");
    expectRejected(Course::class, $course, 'full_course_price', '123.456', 'exceso de escala se rechaza');
    expectRejected(Course::class, $course, 'full_course_price', '12345678901.00', 'exceso de rango de DECIMAL(12,2) se rechaza');
    expectRejected(Course::class, $course, 'full_course_price', 123.45, 'float en DECIMAL se rechaza');
    expectRejected(AppUser::class, $user, 'birthday', '2026-02-30', 'fecha inexistente se rechaza');
    expectValue(
        AppUser::class, $user, 'created_at', 'createdAt', '2026-10-18 12:34:56.654321',
        'datetime:2026-10-18 12:34:56.654321@UTC', 'DATETIME(6) conserva microsegundos en UTC',
    );

    $secretRow = $user;
    $secretRow['password_hash'] = 987654321;
    try {
        AppUser::fromRow($secretRow);
        check(false, 'password_hash numérico debería rechazarse');
    } catch (HydrationException $e) {
        check(
            str_contains($e->getMessage(), 'AppUser.password_hash') && !str_contains($e->getMessage(), '987654321'),
            'el error nombra entidad y campo sin incluir el valor',
        );
    }
    $secretRow = $user;
    $secretRow['email'] = str_repeat('x', 300) . '@dato-sensible.test';
    try {
        AppUser::fromRow($secretRow);
        check(false, 'email demasiado largo debería rechazarse');
    } catch (HydrationException $e) {
        check(!str_contains($e->getMessage(), 'dato-sensible'), 'el error no vuelca el contenido del campo');
    }

    // -----------------------------------------------------------
    Report::section('Columnas generadas y administradas por MySQL');
    check(AppUser::metadata()->generatedColumns() === ['active'], 'app_user: active es la única columna generada');
    check(CourseChapterContent::metadata()->generatedColumns() === ['main_slot'], 'course_chapter_content: main_slot es generada');
    check(TestAttempt::metadata()->generatedColumns() === ['passed'], 'test_attempt: passed es generada');
    $generatedTotal = array_sum(array_map(static fn (string $c): int => count($c::metadata()->generatedColumns()), $entities));
    check($generatedTotal === 3, "3 columnas generadas en total (encontradas: $generatedTotal)");
    expectRejected(AppUser::class, $user, 'active', null, 'app_user.active no admite NULL');
    expectValue(TestAttempt::class, $validRows['test_attempt'], 'passed', 'passed', null, null, 'test_attempt.passed NULL antes de entregar');
    expectValue(
        CourseChapterContent::class, $validRows['course_chapter_content'], 'main_slot', 'mainSlot', null, null,
        'course_chapter_content.main_slot NULL en recursos no principales',
    );
    check(
        AppUser::metadata()->onUpdateColumns() === ['updated_at']
            && isset(AppUser::metadata()->defaults()['created_at']),
        'app_user: defaults y ON UPDATE registrados como administrados por MySQL',
    );

    // -----------------------------------------------------------
    Report::section('Exposición de datos internos');
    foreach ($validRows as $table => $row) {
        $class = EntityMap::classFor($table);
        $entity = $class::fromRow($row);
        check(json_encode($entity) === '{}', "$table: json_encode() no expone propiedades");
        try {
            serialize($entity);
            check(false, "$table: serialize() debería rechazarse");
        } catch (LogicException) {
            check(true, "$table: serialize() se rechaza");
        }
    }
    $hash = '$2y$10$EjemploDeHashSintetico.SoloParaPruebasXYZ0123456789ab';
    $hashRow = $user;
    $hashRow['password_hash'] = $hash;
    $hydratedUser = AppUser::fromRow($hashRow);
    check($hydratedUser->passwordHash() === $hash, 'password_hash disponible para uso interno');
    check(!str_contains(print_r($hydratedUser, true), $hash), 'print_r() oculta password_hash');
    ob_start();
    var_dump($hydratedUser);
    $dump = (string) ob_get_clean();
    check(!str_contains($dump, $hash) && str_contains($dump, '[redacted]'), 'var_dump() oculta password_hash');
    $answerInfo = TestQuestionAnswer::fromRow($validRows['test_question_answer'])->__debugInfo();
    check($answerInfo['is_correct'] === '[redacted]' && $answerInfo['weight'] === '[redacted]', 'test_question_answer oculta is_correct y weight');
    $attemptInfo = TestAttemptAnswer::fromRow($validRows['test_attempt_answer'])->__debugInfo();
    check(
        $attemptInfo['is_correct_snapshot'] === '[redacted]' && $attemptInfo['weight_snapshot'] === '[redacted]',
        'test_attempt_answer oculta is_correct_snapshot y weight_snapshot',
    );

    // -----------------------------------------------------------
    Report::section('Sin acceso a base de datos');
    check(!function_exists('db') && !function_exists('call_sp') && !defined('DB_DEFAULT'), 'autoload e hidratación no cargan la conexión');
    $includedConn = array_filter(
        get_included_files(),
        static fn (string $file): bool => str_ends_with(str_replace('\\', '/', $file), 'app/database/conn.php'),
    );
    check($includedConn === [], 'app/database/conn.php no se incluyó');

    $sources = array_merge(
        glob($root . '/app/models/entities/*.php') ?: [],
        glob($root . '/app/models/mapping/*.php') ?: [],
        glob($root . '/app/models/enums/*.php') ?: [],
        [$root . '/app/autoload.php'],
    );
    foreach ($sources as $file) {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $name = basename($file);
        check(
            preg_match('/\bPDO\b|\bmysqli\b|\bdb\s*\(|\bcall_sp\s*\(|->(prepare|query|exec)\s*\(/i', $code) !== 1,
            "$name: no usa PDO ni la conexión",
        );
        check(
            preg_match('/\bSELECT\b[\s\S]*\bFROM\b|\bINSERT\s+INTO\b|\bUPDATE\s+`?\w+`?\s+SET\b|\bDELETE\s+FROM\b|\bREPLACE\s+INTO\b|\bCALL\s+\w+\s*\(/i', $code) !== 1,
            "$name: sin SQL CRUD incorporado",
        );
    }
} catch (Throwable $e) {
    fwrite(STDERR, sprintf('Error inesperado: %s: %s (%s:%d)%s', $e::class, $e->getMessage(), $e->getFile(), $e->getLine(), PHP_EOL));
    exit(1);
}

Report::finish();
