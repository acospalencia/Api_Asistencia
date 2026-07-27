<?php
declare(strict_types=1);

namespace Nordictech\Api\Http;

final class Response
{
    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        exit;
    }

    public static function error(
        int $status,
        string $code,
        string $message
    ): never {
        self::json([
            'code' => $code,
            'message' => $message,
        ], $status);
    }

    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }
}
