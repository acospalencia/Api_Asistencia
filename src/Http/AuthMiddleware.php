<?php
declare(strict_types=1);

namespace Nordictech\Api\Http;

use Nordictech\Api\Security\Jwt;
use Throwable;

final class AuthMiddleware
{
    /** @return array<string, mixed> */
    public static function authenticate(): array
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches)) {
            Response::error(
                401,
                'missing_token',
                'Debes enviar un token Bearer.'
            );
        }

        try {
            return Jwt::validate($matches[1]);
        } catch (Throwable) {
            Response::error(
                401,
                'invalid_token',
                'El token es inválido o ha expirado.'
            );
        }
    }
}
