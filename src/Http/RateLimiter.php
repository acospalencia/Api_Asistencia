<?php
declare(strict_types=1);

namespace Nordictech\Api\Http;

use Throwable;

final class RateLimiter
{
    private const STORAGE_DIRECTORY = 'nordictech-api-rate-limit';
    private const MAX_FILE_AGE_SECONDS = 86400;

    public static function enforce(
        string $scope,
        int $limit,
        int $windowSeconds
    ): void {
        if (PHP_SAPI === 'cli' || $limit <= 0 || $windowSeconds <= 0) {
            return;
        }

        try {
            $directory = self::storageDirectory();
            if (!is_dir($directory)
                && !mkdir($directory, 0700, true)
                && !is_dir($directory)) {
                throw new \RuntimeException(
                    'No fue posible crear el almacenamiento del rate limit.'
                );
            }

            $identifier = self::clientIdentifier();
            $file = $directory
                . DIRECTORY_SEPARATOR
                . hash('sha256', $scope . '|' . $identifier)
                . '.json';
            $now = time();
            $handle = fopen($file, 'c+');
            if ($handle === false || !flock($handle, LOCK_EX)) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                throw new \RuntimeException(
                    'No fue posible bloquear el contador del rate limit.'
                );
            }

            $contents = stream_get_contents($handle);
            $state = is_string($contents)
                ? json_decode($contents, true)
                : null;
            $windowStart = is_array($state)
                ? (int) ($state['windowStart'] ?? 0)
                : 0;
            $count = is_array($state)
                ? (int) ($state['count'] ?? 0)
                : 0;

            if ($windowStart <= 0
                || $now >= $windowStart + $windowSeconds) {
                $windowStart = $now;
                $count = 0;
            }

            $count++;
            $resetAt = $windowStart + $windowSeconds;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite(
                $handle,
                (string) json_encode([
                    'windowStart' => $windowStart,
                    'count' => $count,
                ], JSON_THROW_ON_ERROR)
            );
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);

            self::sendHeaders(
                $limit,
                max(0, $limit - $count),
                $resetAt
            );
            self::occasionallyClean($directory, $now);

            if ($count > $limit) {
                $retryAfter = max(1, $resetAt - $now);
                header('Retry-After: ' . $retryAfter, true);
                Response::error(
                    429,
                    'rate_limit_exceeded',
                    'Se realizaron demasiadas solicitudes. Intente nuevamente en unos momentos.'
                );
            }
        } catch (Throwable $exception) {
            // Una falla del almacenamiento no debe dejar fuera de servicio
            // toda la API. El error queda disponible en el registro de PHP.
            error_log(
                '[API Asistencia][rate_limit] '
                . $exception->getMessage()
            );
        }
    }

    private static function clientIdentifier(): string
    {
        $address = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return filter_var($address, FILTER_VALIDATE_IP) !== false
            ? $address
            : 'unknown';
    }

    private static function storageDirectory(): string
    {
        $configured = trim(
            (string) (getenv('RATE_LIMIT_STORAGE_PATH') ?: '')
        );
        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }

        return rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR
            . self::STORAGE_DIRECTORY;
    }

    private static function sendHeaders(
        int $limit,
        int $remaining,
        int $resetAt
    ): void {
        header('X-RateLimit-Limit: ' . $limit, true);
        header('X-RateLimit-Remaining: ' . $remaining, true);
        header('X-RateLimit-Reset: ' . $resetAt, true);
    }

    private static function occasionallyClean(
        string $directory,
        int $now
    ): void {
        if (mt_rand(1, 200) !== 1) {
            return;
        }

        $files = glob($directory . DIRECTORY_SEPARATOR . '*.json');
        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            $modifiedAt = filemtime($file);
            if (is_int($modifiedAt)
                && $modifiedAt < $now - self::MAX_FILE_AGE_SECONDS) {
                @unlink($file);
            }
        }
    }
}
