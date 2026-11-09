<?php

declare(strict_types=1);

/**
 * Verificación sin base de datos de la base de pruebas, la limpieza y los datos de demostración:
 * el divisor de scripts SQL, que install.sql liste todos los SP, que sp_maintenance_purge solo
 * pueda borrar cuentas de prueba o de demostración, y que el seeder use datos y medios de demo.
 *
 *   php tests/verify_maintenance_unit.php
 */

use App\Models\Dto\PurgeResult;
use App\Services\Exceptions\BusinessRuleException;

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/app/database/conn.php';
require dirname(__DIR__) . '/scripts/support/DemoSeeder.php';

$root = dirname(__DIR__);

// -----------------------------------------------------------
Report::section('Divisor de scripts SQL');
check(SqlScript::statements("SELECT 1;\nSELECT 2;") === ['SELECT 1', 'SELECT 2'], 'separa por ;');
check(SqlScript::statements("-- comentario; con punto y coma\nSELECT 1; # otro; comentario\n/* bloque; */SELECT 2;") === ['SELECT 1', 'SELECT 2'], 'ignora los comentarios --, # y /* */ aunque tengan ;');
check(SqlScript::statements('SELECT 1--1;') === ['SELECT 1--1'], '-- sin espacio no es comentario');
check(SqlScript::statements("SELECT 'a;b', \"c;d\", `e;f`;") === ["SELECT 'a;b', \"c;d\", `e;f`"], 'no corta dentro de comillas ni backticks');
check(SqlScript::statements("SELECT 'it\\'s; ok', 'it''s; ok';") === ["SELECT 'it\\'s; ok', 'it''s; ok'"], 'respeta \\\' y \'\' dentro de un texto');
$procedure = "DROP PROCEDURE IF EXISTS p;\n\nDELIMITER \$\$\n\nCREATE PROCEDURE p()\nBEGIN\n    SELECT 1;\n    SELECT 2;\nEND\$\$\n\nDELIMITER ;\nSELECT 3;";
$parts = SqlScript::statements($procedure);
check(count($parts) === 3 && $parts[0] === 'DROP PROCEDURE IF EXISTS p' && $parts[2] === 'SELECT 3', 'DELIMITER $$ cambia el separador y DELIMITER ; lo regresa');
check(str_starts_with($parts[1] ?? '', 'CREATE PROCEDURE p()') && str_ends_with($parts[1] ?? '', 'END') && substr_count($parts[1] ?? '', ';') === 2, 'el cuerpo del SP conserva sus ;');
check(SqlScript::statements("\xEF\xBB\xBFSELECT 1;") === ['SELECT 1'], 'quita el BOM');
check(thrown(RuntimeException::class, static fn () => SqlScript::statements("SELECT 'sin cerrar;")) !== null, 'un texto sin cerrar es un error');
check(SqlScript::sourcedFiles("USE cursology;\nSOURCE app/a.sql;\n  source app/b.sql\r\n-- SOURCE app/c.sql;\n") === ['app/a.sql', 'app/b.sql'], 'lee las rutas de SOURCE (no las comentadas)');

// -----------------------------------------------------------
Report::section('install.sql y el esquema');
$install = SqlScript::sourcedFiles((string) file_get_contents("$root/app/database/procedures/install.sql"));
check(count($install) === 104 && count(array_unique($install)) === 104, 'install.sql instala 104 SP, sin repetir');
check(array_filter($install, static fn (string $file): bool => !is_file("$root/$file")) === [], 'todos los archivos de install.sql existen');
$onDisk = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/app/database/procedures", FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'sql' && $file->getFilename() !== 'install.sql') {
        $onDisk[] = 'app/database/procedures/' . str_replace('\\', '/', substr($file->getPathname(), strlen("$root/app/database/procedures/")));
    }
}
check(array_diff($onDisk, $install) === [], 'no hay archivos de SP fuera de install.sql');
$malformed = [];
foreach ($install as $file) {
    $name = basename($file, '.sql');
    $parts = SqlScript::statements((string) file_get_contents("$root/$file"));
    if (count($parts) !== 2 || $parts[0] !== "DROP PROCEDURE IF EXISTS $name" || !str_starts_with($parts[1], "CREATE PROCEDURE $name(")) {
        $malformed[] = $name;
    }
}
check($malformed === [], 'cada archivo es DROP + CREATE del SP con su mismo nombre' . ($malformed === [] ? '' : ': ' . implode(', ', $malformed)));
$schema = SqlScript::statements((string) file_get_contents("$root/app/database/schema/Cursology_modelo_fisico_mysql.sql"));
$tables = [];
foreach ($schema as $statement) {
    if (preg_match('/^CREATE TABLE `(\w+)`/', $statement, $match) === 1) {
        $tables[] = $match[1];
    }
}
check(count($tables) === 30, 'el esquema se divide en sus 30 CREATE TABLE');
check(count(array_filter($schema, static fn (string $s): bool => preg_match('/^(CREATE\s+DATABASE|USE)\s/i', $s) === 1)) === 2, 'y en el CREATE DATABASE y el USE que setup_test_db.php salta');

// -----------------------------------------------------------
Report::section('sp_maintenance_purge');
$purge = (string) file_get_contents("$root/app/database/procedures/maintenance/sp_maintenance_purge.sql");
check(preg_match('/CREATE PROCEDURE sp_maintenance_purge\(\s*IN p_scope VARCHAR\(10\),\s*IN p_apply TINYINT\s*\)/', $purge) === 1, 'solo recibe el alcance y si se aplica: ningún patrón libre');
check(str_contains($purge, "WHEN 'test' THEN '%@cursology.test'") && str_contains($purge, "WHEN 'demo' THEN '%@demo.cursology.local'"), 'los patrones de email están fijos en el SP');
check(str_contains($purge, "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVALID_SCOPE'") && BusinessRuleException::of('INVALID_SCOPE')->status === 422, 'otro alcance -> INVALID_SCOPE (422)');
$likes = preg_match_all('/email LIKE/', $purge);
check($likes > 20 && $likes === preg_match_all("/role <> 'admin' AND (?:u\\.)?email LIKE v_pattern/", $purge), 'cada filtro por email excluye a los admin y usa el patrón fijo');
check(preg_match('/IF p_apply = 1 THEN\s+COMMIT;\s+ELSE\s+ROLLBACK;\s+END IF;/', $purge) === 1, 'sin p_apply = 1 termina con ROLLBACK (ensayo)');
check(preg_match('/EXIT HANDLER FOR SQLEXCEPTION\s+BEGIN\s+ROLLBACK;\s+RESIGNAL;/', $purge) === 1, 'si algo falla, ROLLBACK y el error sigue');
preg_match_all('/DELETE\s+(?:\w+\s+)?FROM\s+(\w+)/', $purge, $deletes);
$missing = array_diff($tables, array_unique($deletes[1]), ['payment_method']);
check($missing === [], 'borra de todas las tablas salvo payment_method' . ($missing === [] ? '' : ': faltan ' . implode(', ', $missing)));
preg_match("/DECLARE v_marker VARCHAR\(60\) DEFAULT '([^']+)'/", $purge, $marker);
$isTestName = static fn (string $name): bool => preg_match('/' . ($marker[1] ?? '^$') . '/', $name) === 1;
check($isTestName('Prueba 20261108191603-fcbcb9 publicar') && $isTestName('Prueba 20261107050738-0b42cb manos') && $isTestName('Prueba 20261023030414-5451e7'), 'reconoce las categorías y reacciones "Prueba <marca>" de las pruebas');
check(!$isTestName('Prueba de software') && !$isTestName('Pruebas unitarias') && !$isTestName('Desarrollo web') && !$isTestName('Prueba 2026-abc'), 'y no toca otros nombres que empiecen con "Prueba"');
preg_match_all('/ AS (\w+)/', substr($purge, (int) strrpos($purge, 'SELECT')), $aliases);
check(array_values(array_diff($aliases[1], ['remaining_students', 'remaining_instructors', 'remaining_admins', 'applied'])) === array_keys(PurgeResult::LABELS), 'PurgeResult::LABELS sigue las columnas del SP, en orden');
$row = array_fill_keys(array_keys(PurgeResult::LABELS), 0);
$result = PurgeResult::fromRow(['users' => 3, 'comments' => 4] + $row + ['remaining_students' => 5, 'remaining_instructors' => 2, 'remaining_admins' => 1, 'applied' => 0]);
check($result->total() === 7 && $result->deleted['users'] === 3 && !$result->applied, 'PurgeResult suma lo borrado y marca el ensayo');
check($result->remaining === ['student' => 5, 'instructor' => 2, 'admin' => 1], 'y las cuentas que quedan por rol');
check(thrown(Throwable::class, static fn () => PurgeResult::fromRow($row)) !== null, 'una fila incompleta del SP es un error');

// -----------------------------------------------------------
Report::section('Base de pruebas');
check($DATABASES['test']['name'] !== $DATABASES['local']['name'], 'la base de pruebas no es la local');
check(($ENV['DB_TEST_NAME'] ?? '') !== '' || $DATABASES['test']['name'] === 'cursology_test', 'sin DB_TEST_NAME se llama cursology_test');
check(($ENV['DB_TEST_HOST'] ?? '') !== '' || $DATABASES['test']['host'] === $DATABASES['local']['host'], 'sin DB_TEST_HOST usa el servidor de la local');
check(($ENV['DB_TEST_USER'] ?? '') !== '' || ($DATABASES['test']['user'] === $DATABASES['local']['user'] && $DATABASES['test']['password'] === $DATABASES['local']['password']), 'sin DB_TEST_USER usa el usuario y la contraseña de la local');
$example = (string) file_get_contents("$root/.env.example");
check(preg_match_all('/^DB_TEST_(HOST|PORT|NAME|USER|PASSWORD)=\r?$/m', $example) === 5, '.env.example trae las 5 claves DB_TEST_* vacías');
check(str_contains((string) file_get_contents("$root/public/index.php"), "getenv('CURSOLOGY_DB') === 'test' ? 'test'"), 'index.php usa la base de pruebas con CURSOLOGY_DB=test');
$setup = (string) file_get_contents("$root/scripts/setup_test_db.php");
check(str_contains($setup, "\$config = \$DATABASES['test'];") && preg_match_all('/DROP DATABASE/', $setup) === 1 && str_contains($setup, 'DROP DATABASE IF EXISTS `$name`'), 'setup_test_db.php solo borra la base de pruebas');
check(str_contains($setup, "strcasecmp(\$name, \$DATABASES[\$other]['name'])"), 'y se niega si se llama igual que la local o la de producción');
foreach (['setup_test_db.php', 'purge_test_data.php', 'seed_demo.php', 'create_admin.php', 'unlock_account.php'] as $script) {
    check(str_contains((string) file_get_contents("$root/scripts/$script"), "if (PHP_SAPI !== 'cli') {"), "scripts/$script solo corre desde la línea de comandos");
}
$suites = glob("$root/tests/verify_*_db.php");
$usesLocal = static fn (string $file): bool => !str_contains($code = (string) file_get_contents($file), 'testDatabase()') || str_contains($code, "\$DATABASES['local']");
check(count($suites) === 9 && array_filter($suites, $usesLocal) === [], 'las 9 suites con base de datos usan testDatabase() y nunca la base local');

// -----------------------------------------------------------
Report::section('Datos de demostración');
$constants = (new ReflectionClass(DemoSeeder::class))->getConstants();
$emails = array_map(DemoSeeder::email(...), array_keys(DemoSeeder::ACCOUNTS));
check(array_filter($emails, static fn (string $e): bool => !str_ends_with($e, '@demo.cursology.local') || str_starts_with($e, 'prueba+')) === [], 'todas las cuentas son ...@demo.cursology.local (y ninguna prueba+)');
$roles = array_count_values(array_column(DemoSeeder::ACCOUNTS, 'rol'));
check($roles === ['instructor' => 3, 'student' => 6], '3 instructores y 6 alumnos');
check(array_filter(DemoSeeder::ACCOUNTS, static fn (array $a): bool => trim($a['muestra'] ?? '') === '') === [], 'cada cuenta dice qué enseñar con ella');
check($constants['MEDIA'] === 'https://res.cloudinary.com/demo/', 'los medios son de la cuenta pública "demo" de Cloudinary');
$videos = [];
$chapterKeys = [];
$published = 0;
foreach ($constants['COURSES'] as $course) {
    $published += $course['publicado'] ? 1 : 0;
    $videos[] = $course['video'];
    foreach ($course['niveles'] as $level) {
        $videos[] = $level['video'];
        foreach ($level['capitulos'] as $chapter) {
            $chapterKeys[] = $chapter['clave'];
            if (isset($chapter['video'])) {
                $videos[] = $chapter['video'];
            }
        }
    }
}
check(count($constants['COURSES']) === 6 && $published === 5, '5 cursos publicados y 1 borrador');
check(array_diff($videos, array_keys($constants['VIDEOS'])) === [], 'cada video usado tiene su duración y tamaño');
check(count($chapterKeys) === count(array_unique($chapterKeys)), 'las claves de los capítulos no se repiten');
$seeder = (string) file_get_contents("$root/scripts/support/DemoSeeder.php");
preg_match_all("/'([a-z]+\\.(?!jpg')[a-z]+)'/", $seeder, $literals);
$unknown = array_diff(array_unique($literals[1]), $chapterKeys, array_keys(DemoSeeder::ACCOUNTS));
check($unknown === [], 'la actividad solo usa capítulos y cuentas que existen' . ($unknown === [] ? '' : ': ' . implode(', ', $unknown)));
check(!str_contains($seeder, '@cursology.test') && preg_match("/'password'\\s*=>\\s*'/", $seeder) !== 1, 'sin cuentas de prueba ni contraseñas escritas en el código');
check(str_contains((string) file_get_contents("$root/scripts/seed_demo.php"), 'UserRules::generatePassword()'), 'seed_demo.php genera la contraseña si no se le da');

Report::finish();
