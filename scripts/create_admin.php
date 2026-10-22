<?php

declare(strict_types=1);

/**
 * Crea el PRIMER administrador de Cursology. Solo se ejecuta desde la línea de comandos:
 *
 *   php scripts/create_admin.php [--email=admin@cursology.local] [--nombre=Administrador]
 *                                [--apellido=Cursology] [--password=...] [--prod]
 *
 * Sin --password genera una contraseña aleatoria que cumple las reglas y la muestra
 * una sola vez. Con --prod usa la base de producción del .env (por defecto, la local).
 * Si ya existe un administrador, el SP lo rechaza (ADMIN_EXISTS).
 */

use App\Database\Connection;
use App\Database\StoredProcedures;
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

$options = getopt('', ['email:', 'nombre:', 'apellido:', 'password:', 'prod']);
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

$users = new UserRepository(new StoredProcedures(new Connection($DATABASES[isset($options['prod']) ? 'prod' : 'local'])));

try {
    // Género y fecha de nacimiento son obligatorios en app_user; el admin los corrige desde su perfil.
    $admin = $users->createFirstAdmin(
        $name,
        $lastName,
        null,
        'otro',
        '1990-01-01',
        AuthService::DEFAULT_AVATAR,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
    );
} catch (BusinessRuleException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Administrador creado (id ', $admin->id(), ').', PHP_EOL;
echo '  Email:      ', $email, PHP_EOL;
echo '  Contraseña: ', $password, PHP_EOL, PHP_EOL;
echo 'Guárdala ahora: no se vuelve a mostrar. Cámbiala desde Mi perfil > Contraseña.', PHP_EOL;
