<?php

declare(strict_types=1);

/**
 * Reactiva una cuenta bloqueada por 3 contraseñas incorrectas, desde la línea de comandos.
 * Sirve cuando la cuenta bloqueada es la del único administrador y nadie puede hacerlo
 * desde el panel:
 *
 *   php scripts/unlock_account.php --email=admin@cursology.local [--prod]
 *
 * La contraseña no cambia. El evento queda en user_account_event sin actor y con el
 * motivo "Reactivación desde la consola". Con --prod usa la base de producción del .env.
 */

use App\Database\Connection;
use App\Database\StoredProcedures;
use App\Repositories\UserRepository;
use App\Services\Exceptions\BusinessRuleException;
use App\Services\Validation\UserRules;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/app/autoload.php';
require $root . '/app/database/conn.php';

$options = getopt('', ['email:', 'prod']);
$email = UserRules::normalizeEmail((string) ($options['email'] ?? ''));
$emailError = UserRules::emailError($email);
if ($emailError !== null) {
    fwrite(STDERR, '--email: ' . $emailError . PHP_EOL);
    exit(1);
}

$users = new UserRepository(new StoredProcedures(new Connection($DATABASES[isset($options['prod']) ? 'prod' : 'local'])));

$user = $users->findForLogin($email);
if ($user === null) {
    fwrite(STDERR, 'No existe una cuenta con ese email.' . PHP_EOL);
    exit(1);
}

try {
    $users->unlock(null, $user->id(), 'Reactivación desde la consola');
} catch (BusinessRuleException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Cuenta reactivada: ', $email, '. Ya puede iniciar sesión con su contraseña.', PHP_EOL;
