<?php

// Configuración (como el settings.py de Django): lee el .env y declara las bases
// disponibles. La conexión la abre App\Database\Connection con la que elija index.php.

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
        'name'     => ($ENV['DB_LOCAL_NAME'] ?? '') ?: 'cursology',
        'user'     => $ENV['DB_LOCAL_USER'] ?? '',
        'password' => $ENV['DB_LOCAL_PASSWORD'] ?? '',
        'charset'  => 'utf8mb4',
        'ssl_ca'   => '',
    ],
    'prod' => [
        'host'     => $ENV['DB_PROD_HOST'] ?? '',
        'port'     => (int) (($ENV['DB_PROD_PORT'] ?? '') ?: 3306),
        'name'     => ($ENV['DB_PROD_NAME'] ?? '') ?: 'cursology',
        'user'     => $ENV['DB_PROD_USER'] ?? '',
        'password' => $ENV['DB_PROD_PASSWORD'] ?? '',
        'charset'  => 'utf8mb4',
        'ssl_ca'   => $ENV['DB_PROD_SSL_CA'] ?? '',
    ],
];
