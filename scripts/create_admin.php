<?php

declare(strict_types=1);

/**
 * Crea un administrador de Cursology. Solo se ejecuta desde la línea de comandos:
 *
 *   php scripts/create_admin.php [--email=admin@cursology.local] [--nombre=Administrador]
 *                                [--apellido=Cursology] [--password=...] [--adicional] [--prod | --test]
 *
 * Sin --adicional crea el PRIMER administrador; si ya existe uno, el SP lo rechaza (ADMIN_EXISTS).
 * Con --adicional crea otro administrador aunque ya haya uno; ahí --email es obligatorio.
 * Sin --password genera una contraseña aleatoria que cumple las reglas y la muestra
 * una sola vez. Por defecto usa la base local; --prod, la de producción, y --test, la de pruebas.
 */

use App\Database\Connection;
use App\Database\StoredProcedures;
use App\Models\Enums\UserRole;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Validation\UserRules;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/app/autoload.php';
require $root . '/app/database/conn.php';

$options = getopt('', ['email:', 'nombre:', 'apellido:', 'password:', 'adicional', 'prod', 'test']);
if (isset($options['prod'], $options['test'])) {
    fwrite(STDERR, 'Usa --prod o --test, no ambos.' . PHP_EOL);
    exit(1);
}
$database = isset($options['prod']) ? 'prod' : (isset($options['test']) ? 'test' : 'local');
$additional = isset($options['adicional']);
if ($additional && !isset($options['email'])) {
    fwrite(STDERR, 'Con --adicional indica el --email del nuevo administrador.' . PHP_EOL);
    exit(1);
}
$email = UserRules::normalizeEmail((string) ($options['email'] ?? 'admin@cursology.local'));
$name = trim((string) ($options['nombre'] ?? 'Administrador'));
$lastName = trim((string) ($options['apellido'] ?? 'Cursology'));
$password = isset($options['password']) ? (string) $options['password'] : UserRules::generatePassword();

$errors = array_filter([
    'email' => UserRules::emailError($email),
    'nombre' => UserRules::nameError($name, 'El nombre es obligatorio.'),
    'apellido' => UserRules::nameError($lastName, 'El apellido es obligatorio.'),
    'password' => UserRules::passwordError($password),
]);
if ($errors !== []) {
    fwrite(STDERR, 'Datos inválidos:' . PHP_EOL);
    foreach ($errors as $field => $message) {
        fwrite(STDERR, sprintf('  --%s: %s%s', $field, $message, PHP_EOL));
    }
    exit(1);
}

$users = new UserRepository(new StoredProcedures(new Connection($DATABASES[$database])));

try {
    // Género y fecha de nacimiento son obligatorios en app_user; el admin los corrige desde su perfil.
    $fields = [$name, $lastName, null, 'otro', '1990-01-01', AuthService::DEFAULT_AVATAR, $email, password_hash($password, PASSWORD_DEFAULT)];
    $admin = $additional ? $users->createAdditionalAdmin(...$fields) : $users->createFirstAdmin(...$fields);
    [$admins] = $users->listUsers($admin->id(), null, UserRole::Admin, null, 100, 0);
} catch (BusinessRuleException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    if ($exception->errorCode === 'ADMIN_EXISTS') {
        fwrite(STDERR, 'Para crear otro, agrega --adicional y el --email del nuevo administrador.' . PHP_EOL);
    }
    exit(1);
}

echo $additional ? 'Administrador adicional creado' : 'Administrador creado', ' (id ', $admin->id(), ').', PHP_EOL;
echo '  Email:      ', $email, PHP_EOL;
echo '  Contraseña: ', $password, PHP_EOL, PHP_EOL;
echo 'Guárdala ahora: no se vuelve a mostrar. Cámbiala desde Mi perfil > Contraseña.', PHP_EOL, PHP_EOL;
echo 'Administradores en la base (', count($admins), '):', PHP_EOL;
foreach ($admins as $row) {
    echo '  ', $row->email, $row->id === $admin->id() ? '  (nuevo)' : '', PHP_EOL;
}
