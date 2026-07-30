<?php
declare(strict_types=1);

namespace Nordictech\Api\Config;

use RuntimeException;

final class ApiConfig
{
    private const MINIMUM_SECRET_LENGTH = 32;

    /** @var array<string, mixed>|null */
    private static ?array $phpCredentials = null;

    public static function jwtSecret(): string
    {
        $secret = getenv('JWT_SECRET')
            ?: self::credential('JWT_SECRET')
            ?: self::credential('AUTH_KEY')
            ?: self::credential('SECURE_AUTH_KEY');

        if (!is_string($secret) || strlen($secret) < self::MINIMUM_SECRET_LENGTH) {
            throw new RuntimeException(
                'JWT_SECRET debe contener al menos 32 caracteres.'
            );
        }

        return $secret;
    }

    public static function jwtIssuer(): string
    {
        return getenv('JWT_ISSUER')
            ?: (string) self::credential('JWT_ISSUER', 'Nordictech.Asistencia.Api');
    }

    public static function jwtAudience(): string
    {
        return getenv('JWT_AUDIENCE')
            ?: (string) self::credential('JWT_AUDIENCE', 'Nordictech.Asistencia.Client');
    }

    public static function tokenLifetimeSeconds(): int
    {
        $minutes = (int) (
            getenv('JWT_EXPIRATION_MINUTES')
            ?: self::credential('JWT_EXPIRATION_MINUTES', 60)
        );

        return max(1, min($minutes, 1440)) * 60;
    }

    public static function cronSecret(): string
    {
        $secret = getenv('CRON_SECRET')
            ?: self::credential('CRON_SECRET', '');

        if (!is_string($secret) || strlen($secret) < self::MINIMUM_SECRET_LENGTH) {
            throw new RuntimeException(
                'CRON_SECRET debe contener al menos 32 caracteres.'
            );
        }

        return $secret;
    }

    public static function credential(string $key, mixed $default = null): mixed
    {
        $credentials = self::credentials();
        return array_key_exists($key, $credentials) && $credentials[$key] !== null
            ? $credentials[$key]
            : $default;
    }

    /** @return array<string, mixed> */
    public static function credentials(): array
    {
        if (self::$phpCredentials !== null) {
            return self::$phpCredentials;
        }

        $configuredPath = getenv('BD_CREDENTIALS_PATH') ?: '';
        $candidates = array_filter([
            $configuredPath,
            dirname(__DIR__, 2) . '/BD_credentials.php',
            dirname(__DIR__, 3) . '/BD_credentials.php',
        ]);

        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }

            self::$phpCredentials = self::parsePhpConstants($path);

            return self::$phpCredentials;
        }

        throw new RuntimeException(
            'No se encontró BD_credentials.php en la carpeta de la API ni en su carpeta padre.'
        );
    }

    /**
     * Lee las llamadas define() sin ejecutar el archivo ni iniciar WordPress.
     *
     * @return array<string, mixed>
     */
    private static function parsePhpConstants(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(
                'No fue posible leer el archivo de credenciales.'
            );
        }

        $constants = [];
        $quotedPattern = <<<'REGEX'
~define\s*\(\s*['"](?<name>[A-Z][A-Z0-9_]*)['"]\s*,\s*(?<quote>['"])(?<value>(?:\\.|(?!\k<quote>).)*)\k<quote>\s*\)\s*;~is
REGEX;

        if (preg_match_all(
            $quotedPattern,
            $contents,
            $quotedMatches,
            PREG_SET_ORDER
        ) !== false) {
            foreach ($quotedMatches as $match) {
                $constants[$match['name']] = self::decodePhpString(
                    $match['value'],
                    $match['quote']
                );
            }
        }

        $integerPattern = <<<'REGEX'
~define\s*\(\s*['"](?<name>[A-Z][A-Z0-9_]*)['"]\s*,\s*(?<value>\d+)\s*\)\s*;~i
REGEX;

        if (preg_match_all(
            $integerPattern,
            $contents,
            $integerMatches,
            PREG_SET_ORDER
        ) !== false) {
            foreach ($integerMatches as $match) {
                $constants[$match['name']] = (int) $match['value'];
            }
        }

        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $required) {
            if (!array_key_exists($required, $constants)) {
                throw new RuntimeException(
                    "BD_credentials.php no contiene la constante {$required}."
                );
            }
        }

        return $constants;
    }

    private static function decodePhpString(string $value, string $quote): string
    {
        if ($quote === "'") {
            return str_replace(
                ["\\\\", "\\'"],
                ["\\", "'"],
                $value
            );
        }

        return stripcslashes($value);
    }
}
