<?php
declare(strict_types=1);

namespace Nordictech\Api\Services;

use Nordictech\Api\Config\ApiConfig;
use Nordictech\Api\Data\Database;
use Throwable;

final class PushNotificationService
{
    private static ?string $lastErrorCode = null;
    private static ?string $lastErrorMessage = null;

    /**
     * El envío push es complementario: una configuración ausente o un fallo de
     * Firebase nunca revierte la asignación guardada en la base de datos.
     *
     * @param array<string, string> $data
     */
    public static function sendToUser(
        int $userId,
        string $title,
        string $body,
        array $data = []
    ): int {
        self::$lastErrorCode = null;
        self::$lastErrorMessage = null;

        try {
            $credentials = self::serviceAccount();
            if ($credentials === null) {
                self::setLastError(
                    'firebase_not_configured',
                    'FCM_SERVICE_ACCOUNT_PATH no está configurado.'
                );
                error_log(
                    '[API Asistencia][push] Firebase no está configurado; '
                    . 'se conserva la notificación dentro de la aplicación.'
                );
                return 0;
            }

            $connection = Database::connection();
            $statement = $connection->prepare(
                'SELECT token_dispositivo
                 FROM Dispositivos_Notificacion
                 WHERE id_usuario = :id_usuario
                   AND estado_activo = 1'
            );
            $statement->execute(['id_usuario' => $userId]);
            $tokens = $statement->fetchAll(\PDO::FETCH_COLUMN);
            if ($tokens === []) {
                self::setLastError(
                    'no_active_device_tokens',
                    'El usuario no tiene dispositivos activos registrados.'
                );
                return 0;
            }

            $accessToken = self::accessToken($credentials);
            $sentCount = 0;
            foreach ($tokens as $token) {
                if (!is_string($token) || $token === '') {
                    continue;
                }

                try {
                    self::sendMessage(
                        $credentials['project_id'],
                        $accessToken,
                        $token,
                        $title,
                        $body,
                        $data
                    );
                    $sentCount++;
                } catch (Throwable $exception) {
                    self::setLastError(
                        'firebase_delivery_failed',
                        $exception->getMessage()
                    );
                    error_log(
                        '[API Asistencia][push] '
                        . $exception->getMessage()
                    );
                    self::deactivateInvalidToken(
                        $connection,
                        $token,
                        $exception->getMessage()
                    );
                }
            }

            if ($sentCount === 0 && self::$lastErrorCode === null) {
                self::setLastError(
                    'no_valid_device_tokens',
                    'No se encontraron tokens válidos para enviar.'
                );
            }

            return $sentCount;
        } catch (Throwable $exception) {
            self::setLastError(
                self::$lastErrorCode ?? 'firebase_internal_error',
                $exception->getMessage()
            );
            error_log(
                '[API Asistencia][push] ' . $exception->getMessage()
            );
            return 0;
        }
    }

    public static function lastErrorCode(): ?string
    {
        return self::$lastErrorCode;
    }

    public static function lastErrorMessage(): ?string
    {
        return self::$lastErrorMessage;
    }

    /** @return array{project_id: string, client_email: string, private_key: string}|null */
    private static function serviceAccount(): ?array
    {
        $configuredPath = getenv('FCM_SERVICE_ACCOUNT_PATH')
            ?: (string) ApiConfig::credential(
                'FCM_SERVICE_ACCOUNT_PATH',
                ''
            );
        if ($configuredPath === '') {
            return null;
        }

        if (!str_starts_with($configuredPath, '/')
            && preg_match('/^[A-Za-z]:[\\\\\\/]/', $configuredPath) !== 1) {
            $configuredPath = dirname(__DIR__, 2)
                . '/'
                . ltrim($configuredPath, '/\\');
        }

        if (!is_file($configuredPath)) {
            throw new \RuntimeException(
                'No se encontró el archivo de cuenta de servicio de Firebase.'
            );
        }

        $contents = file_get_contents($configuredPath);
        $credentials = is_string($contents)
            ? json_decode($contents, true)
            : null;
        if (!is_array($credentials)
            || !isset(
                $credentials['project_id'],
                $credentials['client_email'],
                $credentials['private_key']
            )) {
            throw new \RuntimeException(
                'La cuenta de servicio de Firebase no es válida.'
            );
        }

        return [
            'project_id' => (string) $credentials['project_id'],
            'client_email' => (string) $credentials['client_email'],
            'private_key' => (string) $credentials['private_key'],
        ];
    }

    /** @param array{project_id: string, client_email: string, private_key: string} $credentials */
    private static function accessToken(array $credentials): string
    {
        $now = time();
        $header = self::base64Url((string) json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));
        $claims = self::base64Url((string) json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsignedToken = $header . '.' . $claims;

        $signed = openssl_sign(
            $unsignedToken,
            $signature,
            $credentials['private_key'],
            OPENSSL_ALGO_SHA256
        );
        if (!$signed) {
            throw new \RuntimeException(
                'No fue posible firmar la autorización de Firebase.'
            );
        }

        $assertion = $unsignedToken
            . '.'
            . self::base64Url($signature);
        $response = self::httpRequest(
            'https://oauth2.googleapis.com/token',
            http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]),
            ['Content-Type: application/x-www-form-urlencoded']
        );
        $payload = json_decode($response, true);
        if (!is_array($payload)
            || !is_string($payload['access_token'] ?? null)) {
            throw new \RuntimeException(
                'Firebase no devolvió un token de acceso válido.'
            );
        }

        return $payload['access_token'];
    }

    /**
     * @param array<string, string> $data
     */
    private static function sendMessage(
        string $projectId,
        string $accessToken,
        string $deviceToken,
        string $title,
        string $body,
        array $data
    ): void {
        $silent = strtolower((string) ($data['silent'] ?? 'false')) === 'true';
        $androidNotification = [
            'channel_id' => $silent
                ? 'attendance_updates_silent'
                : 'workday_events',
        ];
        if (!$silent) {
            $androidNotification['sound'] = 'default';
        }

        $payload = json_encode([
            'message' => [
                'token' => $deviceToken,
                'notification' => [
                    'title' => $title,
                    'body' => substr($body, 0, 220),
                ],
                'data' => $data,
                'android' => [
                    'priority' => $silent ? 'normal' : 'high',
                    'notification' => $androidNotification,
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        self::httpRequest(
            sprintf(
                'https://fcm.googleapis.com/v1/projects/%s/messages:send',
                rawurlencode($projectId)
            ),
            $payload,
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]
        );

    }

    /** @param list<string> $headers */
    private static function httpRequest(
        string $url,
        string $body,
        array $headers
    ): string {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException(
                'La extensión cURL de PHP es necesaria para Firebase.'
            );
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($response) || $status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf(
                'Solicitud Firebase fallida (HTTP %d): %s',
                $status,
                $error !== '' ? $error : (string) $response
            ));
        }

        return $response;
    }

    private static function base64Url(string $value): string
    {
        return rtrim(
            strtr(base64_encode($value), '+/', '-_'),
            '='
        );
    }

    private static function setLastError(
        string $code,
        string $message
    ): void {
        self::$lastErrorCode = $code;
        self::$lastErrorMessage = $message;
    }

    private static function deactivateInvalidToken(
        \PDO $connection,
        string $token,
        string $errorMessage
    ): void {
        $normalized = strtoupper($errorMessage);
        if (!str_contains($normalized, 'UNREGISTERED')
            && !str_contains(
                $normalized,
                'REGISTRATION-TOKEN-NOT-REGISTERED'
            )) {
            return;
        }

        try {
            $statement = $connection->prepare(
                'UPDATE Dispositivos_Notificacion
                 SET estado_activo = 0,
                     fecha_actualizacion = CURRENT_TIMESTAMP
                 WHERE token_dispositivo = :token_dispositivo'
            );
            $statement->execute(['token_dispositivo' => $token]);
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][push_token_cleanup] '
                . $exception->getMessage()
            );
        }
    }
}
