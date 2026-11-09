<?php

declare(strict_types=1);

/**
 * Borra de verdad las cuentas de prueba (o las de demostración) y todo lo que depende de ellas,
 * con sp_maintenance_purge. Solo se ejecuta desde la línea de comandos:
 *
 *   php scripts/purge_test_data.php            ensayo: cuenta lo que se borraría y no borra nada
 *   php scripts/purge_test_data.php --apply    borra
 *
 * Opciones:
 *   --demo   borra las cuentas de demostración (...@demo.cursology.local) en lugar de las de
 *            prueba (...@cursology.test y las categorías y reacciones "Prueba <marca>")
 *   --prod   usa la base de producción del .env (por defecto, la local)
 *   --test   usa la base de las pruebas automáticas (cursology_test)
 *
 * Nunca borra administradores. Los archivos en Cloudinary no se tocan.
 */

use App\Database\Connection;
use App\Database\StoredProcedures;
use App\Models\Dto\PurgeResult;
use App\Repositories\MaintenanceRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/app/autoload.php';
require $root . '/app/database/conn.php';

$options = getopt('', ['apply', 'demo', 'prod', 'test']);
if (isset($options['prod'], $options['test'])) {
    fwrite(STDERR, 'Usa --prod o --test, no ambos.' . PHP_EOL);
    exit(1);
}
$database = isset($options['prod']) ? 'prod' : (isset($options['test']) ? 'test' : 'local');
$apply = isset($options['apply']);
$scope = isset($options['demo']) ? MaintenanceRepository::SCOPE_DEMO : MaintenanceRepository::SCOPE_TEST;

printf('Base: %s (%s)%s', $DATABASES[$database]['name'], $database, PHP_EOL);
echo $scope === MaintenanceRepository::SCOPE_DEMO
    ? 'Qué se borra: las cuentas de demostración (...@demo.cursology.local) y todo lo suyo.'
    : 'Qué se borra: las cuentas de prueba (...@cursology.test), todo lo suyo y las categorías y reacciones "Prueba <marca>" que nadie use.',
    PHP_EOL, PHP_EOL;

try {
    $result = (new MaintenanceRepository(new StoredProcedures(new Connection($DATABASES[$database]))))->purge($scope, $apply);
} catch (Throwable $exception) {
    fwrite(STDERR, 'No se pudo hacer la limpieza (no se borró nada). Revisa que MySQL esté encendido y que el SP' . PHP_EOL
        . 'esté instalado: SOURCE app/database/procedures/maintenance/sp_maintenance_purge.sql;' . PHP_EOL
        . 'Detalle: ' . ($exception->getPrevious()?->getMessage() ?? $exception->getMessage()) . PHP_EOL);
    exit(1);
}

echo $result->applied ? 'Filas borradas:' : 'Filas que se borrarían:', PHP_EOL;
foreach (PurgeResult::LABELS as $column => $label) {
    if ($result->deleted[$column] > 0) {
        printf('  %s %8d%s', mb_str_pad($label, 36), $result->deleted[$column], PHP_EOL);
    }
}
if ($result->total() === 0) {
    echo '  (nada)', PHP_EOL;
}

printf('%sCuentas que quedan: %d alumnos, %d instructores, %d administradores.%s',
    PHP_EOL, $result->remaining['student'], $result->remaining['instructor'], $result->remaining['admin'], PHP_EOL);

if (!$result->applied) {
    $flags = array_keys(array_intersect_key($options, ['demo' => true, 'prod' => true, 'test' => true]));
    printf('%sEnsayo: no se borró nada. Para borrarlo de verdad:%s  php scripts/purge_test_data.php --apply%s%s',
        PHP_EOL, PHP_EOL, implode('', array_map(static fn (string $flag): string => ' --' . $flag, $flags)), PHP_EOL);
} elseif ($result->total() > 0) {
    echo PHP_EOL, 'Listo. Los archivos que esas cuentas hayan subido a Cloudinary (avatares, portadas o videos de las', PHP_EOL,
        'pruebas de punta a punta) no se borran: están en la carpeta cursology/ del Media Library.', PHP_EOL;
}
