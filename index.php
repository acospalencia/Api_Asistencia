<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

use Nordictech\Api\Controllers\AuthController;
use Nordictech\Api\Controllers\AttendanceController;
use Nordictech\Api\Controllers\AdminController;
use Nordictech\Api\Controllers\RegisterController;
use Nordictech\Api\Http\AuthMiddleware;
use Nordictech\Api\Http\Response;

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = api_request_path();

    if ($method === 'GET' && $path === '/api/v1/health') {
        Response::json([
            'status' => 'ok',
            'apiVersion' => '2026.07.27.5',
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

    if ($method === 'GET' && $path === '/api/v1/locations') {
        $claims = AuthMiddleware::authenticate();
        (new AttendanceController())->locations($claims);
    }

    if ($method === 'POST' && $path === '/api/v1/attendance/check-in') {
        $claims = AuthMiddleware::authenticate();
        (new AttendanceController())->checkIn($claims);
    }

    if ($method === 'POST' && $path === '/api/v1/attendance/check-out') {
        $claims = AuthMiddleware::authenticate();
        (new AttendanceController())->checkOut($claims);
    }

    if ($method === 'GET' && $path === '/api/v1/admin/dashboard') {
        $claims = AuthMiddleware::authenticate();
        (new AdminController())->dashboard($claims);
    }

    if ($method === 'PUT'
        && preg_match(
            '#^/api/v1/admin/users/(\d+)/role$#',
            $path,
            $matches
        ) === 1) {
        $claims = AuthMiddleware::authenticate();
        (new AdminController())->updateUserRole(
            $claims,
            (int) $matches[1]
        );
    }

    if ($method === 'PATCH'
        && preg_match(
            '#^/api/v1/admin/users/(\d+)/status$#',
            $path,
            $matches
        ) === 1) {
        $claims = AuthMiddleware::authenticate();
        (new AdminController())->updateUserStatus(
            $claims,
            (int) $matches[1]
        );
    }

    if ($method === 'POST'
        && $path === '/api/v1/admin/supervisor-assignments') {
        $claims = AuthMiddleware::authenticate();
        (new AdminController())->assignTechnicians($claims);
    }

    if ($method === 'DELETE'
        && preg_match(
            '#^/api/v1/admin/supervisor-assignments/(\d+)$#',
            $path,
            $matches
        ) === 1) {
        $claims = AuthMiddleware::authenticate();
        (new AdminController())->removeTechnicianAssignment(
            $claims,
            (int) $matches[1]
        );
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
