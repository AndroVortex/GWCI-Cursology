<?php

declare(strict_types=1);

/**
 * Crea desde cero la base de las pruebas automáticas ($DATABASES['test'], cursology_test por
 * defecto). Solo se ejecuta desde la línea de comandos:
 *
 *   php scripts/setup_test_db.php
 *
 * Borra la base de pruebas si ya existe, corre el esquema, instala todos los SP de install.sql
 * y crea admin@cursology.local con una contraseña aleatoria que no se muestra (las pruebas no
 * inician sesión con ella). Vuelve a correrlo cuando cambien el esquema o algún SP.
 * Se niega a correr si la base de pruebas tiene el mismo nombre que la local o la de producción.
 */

use App\Database\Connection;
use App\Database\StoredProcedures;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\Validation\UserRules;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/app/autoload.php';
require $root . '/app/database/conn.php';
require __DIR__ . '/support/SqlScript.php';

$config = $DATABASES['test'];
$name = $config['name'];

if (preg_match('/^[A-Za-z0-9_]+$/D', $name) !== 1) {
    fwrite(STDERR, 'DB_TEST_NAME solo puede tener letras, números y guion bajo.' . PHP_EOL);
    exit(1);
}
foreach (['local' => 'DB_LOCAL_NAME', 'prod' => 'DB_PROD_NAME'] as $other => $key) {
    if (strcasecmp($name, $DATABASES[$other]['name']) === 0) {
        fwrite(STDERR, sprintf('La base de pruebas no puede llamarse igual que %s (%s). Cambia DB_TEST_NAME en el .env.%s', $key, $name, PHP_EOL));
        exit(1);
    }
}

try {
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['host'], $config['port']), $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,"
            . "ONLY_FULL_GROUP_BY,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
    ]);
} catch (PDOException $exception) {
    fwrite(STDERR, 'No se pudo conectar a MySQL. Revisa que esté encendido y los datos DB_TEST_* o DB_LOCAL_* del .env.' . PHP_EOL
        . 'Detalle: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$run = static function (string $file) use ($pdo, $root): int {
    $statements = SqlScript::statements((string) file_get_contents($root . '/' . $file));
    foreach ($statements as $statement) {
        if (preg_match('/^(CREATE\s+DATABASE|USE)\s/i', $statement) === 1) {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (PDOException $exception) {
            throw new RuntimeException(sprintf('%s: %s', $file, $exception->getMessage()), 0, $exception);
        }
    }

    return count($statements);
};

try {
    echo 'Reiniciando la base de pruebas ', $name, '...', PHP_EOL;
    $pdo->exec("DROP DATABASE IF EXISTS `$name`");
    $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$name`");

    $run('app/database/schema/Cursology_modelo_fisico_mysql.sql');

    $procedures = SqlScript::sourcedFiles((string) file_get_contents($root . '/app/database/procedures/install.sql'));
    foreach ($procedures as $file) {
        $run($file);
    }

    $tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchColumn();
    $installed = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE'")->fetchColumn();
} catch (Throwable $exception) {
    fwrite(STDERR, 'No se pudo crear la base de pruebas.' . PHP_EOL . 'Detalle: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$users = new UserRepository(new StoredProcedures(new Connection($config)));
$users->createFirstAdmin(
    'Administrador',
    'Pruebas',
    null,
    'otro',
    '1990-01-01',
    AuthService::DEFAULT_AVATAR,
    'admin@cursology.local',
    password_hash(UserRules::generatePassword(), PASSWORD_DEFAULT),
);

printf('Base %s lista: %d tablas, %d de %d SP y admin@cursology.local.%s', $name, $tables, $installed, count($procedures), PHP_EOL);
exit($installed === count($procedures) ? 0 : 1);
