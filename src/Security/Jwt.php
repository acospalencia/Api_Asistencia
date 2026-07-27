<?php
declare(strict_types=1);

namespace Nordictech\Api\Security;

use Nordictech\Api\Config\ApiConfig;
use RuntimeException;

final class Jwt
{
    /** @param array<string, mixed> $claims */
    public static function create(array $claims): array
    {
        $now = time();
        $expiresAt = $now + ApiConfig::tokenLifetimeSeconds();
        $payload = array_merge($claims, [
            'iss' => ApiConfig::jwtIssuer(),
            'aud' => ApiConfig::jwtAudience(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $expiresAt,
            'jti' => bin2hex(random_bytes(16)),
        ]);

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $encodedHeader = self::encodePart($header);
        $encodedPayload = self::encodePart($payload);
        $signature = self::base64UrlEncode(hash_hmac(
            'sha256',
            "{$encodedHeader}.{$encodedPayload}",
            ApiConfig::jwtSecret(),
            true
        ));

        return [
            'token' => "{$encodedHeader}.{$encodedPayload}.{$signature}",
            'expiresAt' => $expiresAt,
        ];
    }

    /** @return array<string, mixed> */
    public static function validate(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Token inválido.');
        }

        [$encodedHeader, $encodedPayload, $signature] = $parts;
        $header = self::decodePart($encodedHeader);
        $payload = self::decodePart($encodedPayload);

        if (($header['alg'] ?? null) !== 'HS256') {
            throw new RuntimeException('Algoritmo de token no permitido.');
        }

        $expectedSignature = self::base64UrlEncode(hash_hmac(
            'sha256',
            "{$encodedHeader}.{$encodedPayload}",
            ApiConfig::jwtSecret(),
            true
        ));

        $now = time();
        if (!hash_equals($expectedSignature, $signature)
            || ($payload['iss'] ?? null) !== ApiConfig::jwtIssuer()
            || ($payload['aud'] ?? null) !== ApiConfig::jwtAudience()
            || !isset($payload['exp'], $payload['sub'])
            || (int) $payload['exp'] <= $now
            || (isset($payload['nbf']) && (int) $payload['nbf'] > $now + 30)) {
            throw new RuntimeException('Token inválido o expirado.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $value */
    private static function encodePart(array $value): string
    {
        return self::base64UrlEncode(json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /** @return array<string, mixed> */
    private static function decodePart(string $value): array
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Token inválido.');
        }

        $data = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Token inválido.');
        }

        return $data;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
