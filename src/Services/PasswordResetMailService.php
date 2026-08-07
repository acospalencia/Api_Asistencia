<?php
declare(strict_types=1);

namespace Nordictech\Api\Services;

use Nordictech\Api\Config\ApiConfig;

final class PasswordResetMailService
{
    public static function send(string $recipient, string $code): bool
    {
        $fromAddress = self::headerValue(ApiConfig::mailFromAddress());
        $fromName = self::headerValue(ApiConfig::mailFromName());
        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $subject = 'Código para restablecer tu contraseña';
        $body = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#0f172a">'
            . '<h2>Restablecer contraseña</h2>'
            . '<p>Recibimos una solicitud para restablecer tu contraseña de NordicTech Asistencia.</p>'
            . '<p>Tu código es:</p>'
            . '<p style="font-size:26px;font-weight:bold;letter-spacing:6px">'
            . $safeCode
            . '</p>'
            . '<p>Este código vence en 15 minutos.</p>'
            . '<p>Si no solicitaste el cambio, ignora este mensaje.</p>'
            . '</body></html>';
        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            "From: {$fromName} <{$fromAddress}>",
            "Reply-To: {$fromAddress}",
            "Return-Path: {$fromAddress}",
            'X-Mailer: PHP/' . PHP_VERSION,
        ]);

        return mail($recipient, $subject, $body, $headers);
    }

    private static function headerValue(string $value): string
    {
        return str_replace(["\r", "\n"], '', trim($value));
    }
}
