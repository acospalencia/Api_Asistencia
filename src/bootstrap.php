<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Nordictech\\Api\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

$allowedOrigin = getenv('API_ALLOWED_ORIGIN') ?: '*';
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Cron-Secret');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function api_request_path(): string
{
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));

    if ($scriptDirectory !== '/'
        && $scriptDirectory !== '.'
        && str_starts_with($requestPath, $scriptDirectory)) {
        $requestPath = substr($requestPath, strlen($scriptDirectory));
    }

    $normalized = '/' . ltrim($requestPath, '/');
    return $normalized === '/' ? '/' : rtrim($normalized, '/');
}
