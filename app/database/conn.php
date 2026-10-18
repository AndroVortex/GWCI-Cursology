<?php

function load_env(string $path): array
{
    $env = [];
    if (!is_file($path)) {
        return $env;
    }

    $contents = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($path));

    foreach (preg_split('/\R/', $contents) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }

        $env[trim($key)] = $value;
    }

    return $env;
}

$ENV = load_env(dirname(__DIR__, 2) . '/.env');

$DATABASES = [
    'local' => [
        'host'     => $ENV['DB_LOCAL_HOST'] ?? '',
        'port'     => (int) (($ENV['DB_LOCAL_PORT'] ?? '') ?: 3306),
        'name'     => $ENV['DB_LOCAL_NAME'] ?? '',
        'user'     => $ENV['DB_LOCAL_USER'] ?? '',
        'password' => $ENV['DB_LOCAL_PASSWORD'] ?? '',
        'charset'  => 'utf8mb4',
        'ssl_ca'   => '',
    ],
    'prod' => [
        'host'     => $ENV['DB_PROD_HOST'] ?? '',
        'port'     => (int) (($ENV['DB_PROD_PORT'] ?? '') ?: 3306),
        'name'     => $ENV['DB_PROD_NAME'] ?? '',
        'user'     => $ENV['DB_PROD_USER'] ?? '',
        'password' => $ENV['DB_PROD_PASSWORD'] ?? '',
        'charset'  => 'utf8mb4',
        'ssl_ca'   => $ENV['DB_PROD_SSL_CA'] ?? '',
    ],
];

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        if (!defined('DB_DEFAULT')) {
            throw new RuntimeException('DB_DEFAULT no está definida. Defínela en public/index.php.');
        }

        $config = DB_DEFAULT;
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['name'],
            $config['charset']
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if ($config['ssl_ca'] !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $config['ssl_ca'];
        }

        $pdo = new PDO($dsn, $config['user'], $config['password'], $options);
    }

    return $pdo;
}

function call_sp(string $name, array $params = []): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new InvalidArgumentException("Nombre de procedimiento inválido: $name");
    }

    $placeholders = implode(', ', array_fill(0, count($params), '?'));
    $stmt = db()->prepare("CALL $name($placeholders)");
    $stmt->execute(array_values($params));

    // MySQL deja result sets pendientes tras un CALL; hay que drenarlos todos
    // o la siguiente consulta en la conexión falla con el error 2014.
    $rows = null;
    do {
        if ($stmt->columnCount() > 0) {
            $data = $stmt->fetchAll();
            $rows ??= $data;
        }
    } while ($stmt->nextRowset());
    $stmt->closeCursor();

    return $rows ?? [];
}
