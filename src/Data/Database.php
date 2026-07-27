<?php
declare(strict_types=1);

namespace Nordictech\Api\Data;

use Nordictech\Api\Config\ApiConfig;
use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $environmentConnection = getenv('NORDICTECH_DB_CONNECTION')
            ?: getenv('ConnectionStrings__Nordictech');

        if (is_string($environmentConnection) && $environmentConnection !== '') {
            try {
                self::$connection = self::connectFromEnvironment($environmentConnection);
                return self::$connection;
            } catch (PDOException | RuntimeException $exception) {
                error_log(
                    '[API Asistencia] Falló la conexión de entorno; usando BD_credentials.php.'
                );
            }
        }

        $host = (string) ApiConfig::credential('DB_HOST');
        $database = (string) ApiConfig::credential('DB_NAME');
        $username = (string) ApiConfig::credential('DB_USER');
        $password = (string) ApiConfig::credential('DB_PASSWORD');
        $charset = (string) ApiConfig::credential('DB_CHARSET', 'utf8mb4');

        if ($host === '' || $database === '' || $username === '') {
            throw new RuntimeException(
                'BD_credentials.php no contiene todas las credenciales de MySQL.'
            );
        }

        if (preg_match('/^[a-zA-Z0-9_]+$/', $charset) !== 1) {
            throw new RuntimeException('DB_CHARSET contiene un valor inválido.');
        }

        [$hostname, $port] = self::splitHostAndPort($host);
        $dsn = sprintf(
            'mysql:host=%s;%sdbname=%s;charset=%s',
            $hostname,
            $port === null ? '' : "port={$port};",
            $database,
            $charset
        );

        self::$connection = self::createPdo($dsn, $username, $password);
        return self::$connection;
    }

    private static function connectFromEnvironment(string $connection): PDO
    {
        if (str_starts_with(strtolower($connection), 'mysql:')) {
            $username = getenv('NORDICTECH_DB_USER') ?: '';
            $password = getenv('NORDICTECH_DB_PASSWORD') ?: '';
            return self::createPdo($connection, $username, $password);
        }

        $parts = [];
        foreach (explode(';', $connection) as $segment) {
            if (!str_contains($segment, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $segment, 2));
            $parts[strtolower($key)] = $value;
        }

        $host = $parts['server'] ?? $parts['host'] ?? '';
        $database = $parts['database'] ?? $parts['initial catalog'] ?? '';
        $username = $parts['user'] ?? $parts['user id'] ?? $parts['uid'] ?? '';
        $password = $parts['password'] ?? $parts['pwd'] ?? '';
        $port = $parts['port'] ?? null;

        if ($host === '' || $database === '' || $username === '') {
            throw new RuntimeException('La cadena de conexión de entorno es inválida.');
        }

        $dsn = sprintf(
            'mysql:host=%s;%sdbname=%s;charset=utf8mb4',
            $host,
            $port === null ? '' : "port={$port};",
            $database
        );

        return self::createPdo($dsn, $username, $password);
    }

    private static function createPdo(
        string $dsn,
        string $username,
        string $password
    ): PDO {
        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
    }

    /** @return array{0: string, 1: int|null} */
    private static function splitHostAndPort(string $host): array
    {
        if (preg_match('/^(.+):(\d+)$/', $host, $matches) === 1) {
            return [$matches[1], (int) $matches[2]];
        }

        return [$host, null];
    }
}
