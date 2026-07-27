<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

use Nordictech\Api\Controllers\AuthController;
use Nordictech\Api\Controllers\RegisterController;
use Nordictech\Api\Http\AuthMiddleware;
use Nordictech\Api\Http\Response;

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = api_request_path();

    if ($method === 'GET' && $path === '/api/v1/health') {
        Response::json([
            'status' => 'ok',
            'apiVersion' => '2026.07.26.2',
            'pdoMysql' => extension_loaded('pdo_mysql'),
        ]);
    }

    if ($method === 'OPTIONS') {
        Response::noContent();
    }

    if ($method === 'POST' && $path === '/api/v1/auth/login') {
        (new AuthController())->login();
    }

    if ($method === 'POST' && $path === '/api/v1/auth/register') {
        (new RegisterController())->register();
    }

    if ($method === 'GET' && $path === '/api/v1/account/me') {
        $claims = AuthMiddleware::authenticate();
        Response::json([
            'id' => (int) $claims['sub'],
            'username' => $claims['unique_name'],
            'role' => $claims['role'],
            'roleId' => (int) $claims['role_id'],
        ]);
    }

    Response::error(404, 'not_found', 'El endpoint solicitado no existe.');
} catch (Throwable $exception) {
    error_log(sprintf(
        '[API Asistencia] %s in %s:%d',
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    Response::error(
        500,
        'internal_error',
        'Ocurrió un error interno al procesar la solicitud.'
    );
}
