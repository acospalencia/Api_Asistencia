<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

use Nordictech\Api\Controllers\AuthController;
use Nordictech\Api\Controllers\AttendanceController;
use Nordictech\Api\Controllers\AdminController;
use Nordictech\Api\Controllers\RegisterController;
use Nordictech\Api\Controllers\SupervisorController;
use Nordictech\Api\Controllers\WorkdayEventController;
use Nordictech\Api\Controllers\DeviceController;
use Nordictech\Api\Controllers\AttendanceReminderController;
use Nordictech\Api\Controllers\NonWorkingDayController;
use Nordictech\Api\Http\AuthMiddleware;
use Nordictech\Api\Http\RateLimiter;
use Nordictech\Api\Http\Response;
use Nordictech\Api\Config\ApiConfig;

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = api_request_path();

    RateLimiter::enforce('global', 300, 60);

    if ($method !== 'OPTIONS') {
        if ($method === 'POST' && $path === '/api/v1/auth/login') {
            RateLimiter::enforce('auth_login', 20, 300);
        } elseif ($method === 'POST'
            && $path === '/api/v1/auth/forgot-password') {
            RateLimiter::enforce('auth_forgot_password_v2', 5, 300);
        } elseif ($method === 'POST'
            && $path === '/api/v1/auth/reset-password') {
            RateLimiter::enforce('auth_reset_password', 10, 900);
        } elseif ($method === 'POST'
            && $path === '/api/v1/auth/register') {
            RateLimiter::enforce('auth_register', 10, 3600);
        } elseif ($method === 'POST'
            && $path === '/api/v1/cron/attendance-reminders') {
            RateLimiter::enforce('cron_http', 30, 60);
        } elseif (in_array(
            $method,
            ['POST', 'PUT', 'PATCH', 'DELETE'],
            true
        )) {
            RateLimiter::enforce('api_writes', 60, 60);
        } else {
            RateLimiter::enforce('api_reads', 180, 60);
        }
    }

    if ($method === 'GET' && $path === '/api/v1/health') {
        Response::json([
            'status' => 'ok',
            'apiVersion' => '2026.08.04.2',
            'appVersion' => ApiConfig::appVersion(),
            'appDownloadUrl' => ApiConfig::appDownloadUrl(),
            'pdoMysql' => extension_loaded('pdo_mysql'),
        ]);
    }

    if ($method === 'OPTIONS') {
        Response::noContent();
    }

    if ($method === 'POST' && $path === '/api/v1/auth/login') {
        (new AuthController())->login();
    }

    if ($method === 'POST' && $path === '/api/v1/auth/forgot-password') {
        (new AuthController())->forgotPassword();
    }

    if ($method === 'POST' && $path === '/api/v1/auth/reset-password') {
        (new AuthController())->resetPassword();
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

    if ($method === 'GET'
        && $path === '/api/v1/attendance/availability/today') {
        $claims = AuthMiddleware::authenticate();
        (new NonWorkingDayController())->today($claims);
    }

    if ($method === 'GET'
        && $path === '/api/v1/attendance/overtime-report') {
        $claims = AuthMiddleware::authenticate();
        (new SupervisorController())->report($claims, true);
    }

    if ($method === 'GET'
        && $path === '/api/v1/admin/non-working-days') {
        $claims = AuthMiddleware::authenticate();
        (new NonWorkingDayController())->index($claims);
    }

    if ($method === 'POST'
        && $path === '/api/v1/admin/non-working-days') {
        $claims = AuthMiddleware::authenticate();
        (new NonWorkingDayController())->create($claims);
    }

    if ($method === 'DELETE'
        && preg_match(
            '#^/api/v1/admin/non-working-days/(\d+)$#',
            $path,
            $matches
        ) === 1) {
        $claims = AuthMiddleware::authenticate();
        (new NonWorkingDayController())->delete(
            $claims,
            (int) $matches[1]
        );
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

    if ($method === 'POST'
        && $path === '/api/v1/admin/locations') {
        $claims = AuthMiddleware::authenticate();
        (new AdminController())->createLocation($claims);
    }

    if ($method === 'PUT'
        && preg_match(
            '#^/api/v1/admin/locations/(\d+)$#',
            $path,
            $matches
        ) === 1) {
        $claims = AuthMiddleware::authenticate();
        (new AdminController())->updateLocation(
            $claims,
            (int) $matches[1]
        );
    }

    if ($method === 'DELETE'
        && preg_match(
            '#^/api/v1/admin/locations/(\d+)$#',
            $path,
            $matches
        ) === 1) {
        $claims = AuthMiddleware::authenticate();
        (new AdminController())->deleteLocation(
            $claims,
            (int) $matches[1]
        );
    }

    if ($method === 'GET'
        && $path === '/api/v1/supervisor/dashboard') {
        $claims = AuthMiddleware::authenticate();
        (new SupervisorController())->dashboard($claims);
    }

    if ($method === 'GET'
        && $path === '/api/v1/supervisor/report') {
        $claims = AuthMiddleware::authenticate();
        (new SupervisorController())->report($claims);
    }

    if ($method === 'GET'
        && $path === '/api/v1/supervisor/workday-events') {
        $claims = AuthMiddleware::authenticate();
        (new WorkdayEventController())->supervisorIndex($claims);
    }

    if ($method === 'POST'
        && $path === '/api/v1/supervisor/workday-events') {
        $claims = AuthMiddleware::authenticate();
        (new WorkdayEventController())->create($claims);
    }

    if ($method === 'PATCH'
        && preg_match(
            '#^/api/v1/supervisor/workday-events/(\d+)/cancel$#',
            $path,
            $matches
        ) === 1) {
        $claims = AuthMiddleware::authenticate();
        (new WorkdayEventController())->cancelPending(
            $claims,
            (int) $matches[1]
        );
    }

    if ($method === 'POST'
        && preg_match(
            '#^/api/v1/supervisor/workday-events/(\d+)/request-cancellation$#',
            $path,
            $matches
        ) === 1) {
        $claims = AuthMiddleware::authenticate();
        (new WorkdayEventController())->requestCancellation(
            $claims,
            (int) $matches[1]
        );
    }

    if ($method === 'GET'
        && $path === '/api/v1/workday-events/today') {
        $claims = AuthMiddleware::authenticate();
        (new WorkdayEventController())->technicianIndex($claims);
    }

    if ($method === 'PATCH'
        && preg_match(
            '#^/api/v1/workday-events/(\d+)/comment$#',
            $path,
            $matches
        ) === 1) {
        $claims = AuthMiddleware::authenticate();
        (new WorkdayEventController())->updateComment(
            $claims,
            (int) $matches[1]
        );
    }

    if ($method === 'POST'
        && preg_match(
            '#^/api/v1/workday-events/(\d+)/start$#',
            $path,
            $matches
        ) === 1) {
        $claims = AuthMiddleware::authenticate();
        (new WorkdayEventController())->start(
            $claims,
            (int) $matches[1]
        );
    }

    if ($method === 'POST'
        && preg_match(
            '#^/api/v1/workday-events/(\d+)/complete$#',
            $path,
            $matches
        ) === 1) {
        $claims = AuthMiddleware::authenticate();
        (new WorkdayEventController())->complete(
            $claims,
            (int) $matches[1]
        );
    }

    if ($method === 'POST'
        && preg_match(
            '#^/api/v1/workday-events/(\d+)/cancel$#',
            $path,
            $matches
        ) === 1) {
        $claims = AuthMiddleware::authenticate();
        (new WorkdayEventController())->technicianCancel(
            $claims,
            (int) $matches[1]
        );
    }

    if ($method === 'POST'
        && $path === '/api/v1/devices/register') {
        $claims = AuthMiddleware::authenticate();
        (new DeviceController())->register($claims);
    }

    if ($method === 'POST'
        && $path === '/api/v1/cron/attendance-reminders') {
        (new AttendanceReminderController())->run();
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
