<?php
declare(strict_types=1);

use Nordictech\Api\Config\ApiConfig;
use Nordictech\Api\Controllers\AttendanceReminderController;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/src/bootstrap.php';

try {
    $_SERVER['HTTP_X_CRON_SECRET'] = ApiConfig::cronSecret();
    (new AttendanceReminderController())->run();
} catch (Throwable $exception) {
    error_log(
        '[API Asistencia][attendance_reminders_cli] '
        . $exception->getMessage()
    );
    exit(1);
}
