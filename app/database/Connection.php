<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

/**
 * Conexión PDO a MySQL. Recibe la configuración desde fuera (index.php elige
 * la base local o la de producción) y abre la conexión solo cuando se necesita.
 */
final class Connection
{
    private ?PDO $pdo = null;

    /**
     * @param array{host: string, port: int, name: string, user: string, password: string, charset: string, ssl_ca: string} $config
     */
    public function __construct(#[\SensitiveParameter] private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    /** Sin credenciales en var_dump()/print_r(). */
    public function __debugInfo(): array
    {
        return ['host' => $this->config['host'], 'name' => $this->config['name'], 'connected' => $this->pdo !== null];
    }

    private function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['name'],
            $this->config['charset'],
        );

        // Las fechas se guardan en UTC: CURRENT_TIMESTAMP(6) usa el time_zone de la
        // sesión, y el modo estricto es el mismo con el que se creó el esquema.
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,"
                . "ONLY_FULL_GROUP_BY,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
        ];
        if ($this->config['ssl_ca'] !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $this->config['ssl_ca'];
        }

        return new PDO($dsn, $this->config['user'], $this->config['password'], $options);
    }
}
