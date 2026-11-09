<?php

declare(strict_types=1);

/**
 * Carga los datos de demostración (scripts/support/DemoSeeder.php). Solo se ejecuta desde la
 * línea de comandos:
 *
 *   php scripts/seed_demo.php [--password=...] [--admin=admin@cursology.local] [--db=local|test] [--prod]
 *
 * Todas las cuentas de demostración (...@demo.cursology.local) usan la misma contraseña: sin
 * --password se genera una que cumple las reglas y se muestra una sola vez. --admin indica el
 * administrador que crea las categorías y activa la tarjeta simulada si estaba apagada.
 * Si ya hay datos de demostración no hace nada: bórralos antes con
 * php scripts/purge_test_data.php --demo --apply
 */

use App\Database\Connection;
use App\Database\StoredProcedures;
use App\Models\Enums\UserRole;
use App\Repositories\UserRepository;
use App\Services\Exceptions\ValidationException;
use App\Services\Validation\UserRules;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/app/autoload.php';
require $root . '/app/database/conn.php';
require __DIR__ . '/support/DemoSeeder.php';

$options = getopt('', ['password:', 'admin:', 'db:', 'prod']);
$database = isset($options['prod']) ? 'prod' : (string) ($options['db'] ?? 'local');
if (!in_array($database, ['local', 'test', 'prod'], true)) {
    fwrite(STDERR, '--db debe ser local o test (o usa --prod).' . PHP_EOL);
    exit(1);
}
$password = isset($options['password']) ? (string) $options['password'] : UserRules::generatePassword();
if (($error = UserRules::passwordError($password)) !== null) {
    fwrite(STDERR, '--password: ' . $error . PHP_EOL);
    exit(1);
}
$adminEmail = UserRules::normalizeEmail((string) ($options['admin'] ?? 'admin@cursology.local'));

$procedures = new StoredProcedures(new Connection($DATABASES[$database]));
$seeder = new DemoSeeder($procedures);
$purge = 'php scripts/purge_test_data.php --demo --apply' . ($database === 'local' ? '' : ' --' . $database);

try {
    $admin = (new UserRepository($procedures))->findForLogin($adminEmail);
    if ($admin === null || $admin->role() !== UserRole::Admin || !$admin->active() || $admin->ban()) {
        fwrite(STDERR, sprintf('No hay un administrador activo con el email %s. Indícalo con --admin=...%s', $adminEmail, PHP_EOL));
        exit(1);
    }
    if ($seeder->alreadySeeded()) {
        fwrite(STDERR, 'Ya hay datos de demostración en esta base. Para volver a cargarlos, bórralos antes con:' . PHP_EOL . '  ' . $purge . PHP_EOL);
        exit(1);
    }

    printf('Cargando los datos de demostración en %s (%s)...%s', $DATABASES[$database]['name'], $database, PHP_EOL);
    $notes = $seeder->run($admin, $password);
} catch (Throwable $exception) {
    $detail = $exception instanceof ValidationException
        ? json_encode($exception->fields, JSON_UNESCAPED_UNICODE)
        : ($exception->getPrevious()?->getMessage() ?? $exception->getMessage());
    fwrite(STDERR, 'No se pudieron cargar los datos de demostración. Quita lo que alcanzó a crearse con:' . PHP_EOL
        . '  ' . $purge . PHP_EOL . 'Detalle: ' . $detail . PHP_EOL);
    exit(1);
}

foreach ($notes as $note) {
    echo '  - ', $note, PHP_EOL;
}

echo PHP_EOL, 'Cuentas de demostración:', PHP_EOL;
foreach (DemoSeeder::ACCOUNTS as $key => $account) {
    printf('  %s %s%s      %s%s', mb_str_pad(DemoSeeder::email($key), 38), $account['rol'] === 'instructor' ? 'instructor' : 'alumno', PHP_EOL, $account['muestra'], PHP_EOL);
}
echo PHP_EOL, 'Contraseña de todas: ', $password, PHP_EOL;
echo 'Guárdala ahora: no se vuelve a mostrar. El admin sigue siendo ', $adminEmail, ' con su propia contraseña.', PHP_EOL;
