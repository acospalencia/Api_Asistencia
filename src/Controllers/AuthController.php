<?php
declare(strict_types=1);

namespace Nordictech\Api\Controllers;

use JsonException;
use Nordictech\Api\Data\Database;
use Nordictech\Api\Http\Response;
use Nordictech\Api\Security\Jwt;
use Nordictech\Api\Config\ApiConfig;
use Nordictech\Api\Services\PasswordResetMailService;
use Throwable;

final class AuthController
{
    /** @return array<string, mixed> */
    private function requestBody(): array
    {
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType === 'application/x-www-form-urlencoded') {
            return $_POST;
        }

        try {
            $body = json_decode(file_get_contents('php://input') ?: '', true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            Response::error(400, 'invalid_json', 'El cuerpo JSON es inválido.');
        }
        if (!is_array($body)) {
            Response::error(400, 'invalid_json', 'El cuerpo JSON es inválido.');
        }
        return $body;
    }

    public function login(): never
    {
        $contentType = strtolower(
            trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])
        );

        if ($contentType === 'application/x-www-form-urlencoded') {
            $body = $_POST;
        } else {
            try {
                $body = json_decode(
                    file_get_contents('php://input') ?: '',
                    true,
                    16,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                Response::error(400, 'invalid_json', 'El cuerpo JSON es inválido.');
            }

            if (!is_array($body)) {
                Response::error(400, 'invalid_json', 'El cuerpo JSON es inválido.');
            }
        }

        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === ''
            || strlen($username) > 50
            || strlen($password) > 200) {
            Response::error(
                422,
                'validation_error',
                'Username y password son obligatorios.'
            );
        }

        try {
            $statement = Database::connection()->prepare(
                'SELECT
                    u.id_usuario,
                    u.username,
                    u.password_hash,
                    u.email,
                    u.id_rol,
                    r.nombre_rol,
                    dp.nombres,
                    dp.apellidos
                 FROM Usuarios u
                 INNER JOIN Roles r ON r.id_rol = u.id_rol
                 INNER JOIN Datos_Personales dp
                    ON dp.id_datos_personales = u.id_datos_personales
                 WHERE u.username = :username
                   AND u.estado_activo = b\'1\'
                 LIMIT 1'
            );
            $statement->execute(['username' => $username]);
            $user = $statement->fetch();
        } catch (Throwable $exception) {
            error_log('[API Asistencia][login_database] ' . $exception->getMessage());
            Response::error(
                503,
                'database_unavailable',
                'No fue posible consultar la base de datos.'
            );
        }

        if (!is_array($user)) {
            Response::error(
                401,
                'invalid_credentials',
                'Usuario o contraseña incorrectos, o la cuenta está inactiva.'
            );
        }

        try {
            $storedPassword = (string) $user['password_hash'];
            $passwordInfo = password_get_info($storedPassword);
            $usesPasswordHash =
                ($passwordInfo['algoName'] ?? 'unknown') !== 'unknown'
                || preg_match('/^\$2[abxy]\$\d{2}\$/', $storedPassword) === 1;
            $validPassword = $usesPasswordHash
                ? password_verify($password, $storedPassword)
                : hash_equals($storedPassword, $password);

            if (!$validPassword) {
                Response::error(
                    401,
                    'invalid_credentials',
                    'Usuario o contraseña incorrectos, o la cuenta está inactiva.'
                );
            }

            if (!$usesPasswordHash
                || password_needs_rehash($storedPassword, PASSWORD_DEFAULT)) {
                $newPasswordHash = password_hash($password, PASSWORD_DEFAULT);
                $updatePassword = Database::connection()->prepare(
                    'UPDATE Usuarios
                     SET password_hash = :password_hash
                     WHERE id_usuario = :id_usuario'
                );
                $updatePassword->execute([
                    'password_hash' => $newPasswordHash,
                    'id_usuario' => (int) $user['id_usuario'],
                ]);
            }

            $fullName = trim(
                (string) $user['nombres'] . ' ' . (string) $user['apellidos']
            );
            $claims = [
                'sub' => (string) $user['id_usuario'],
                'unique_name' => (string) $user['username'],
                'email' => $user['email'],
                'role' => (string) $user['nombre_rol'],
                'role_id' => (int) $user['id_rol'],
            ];
            $jwt = Jwt::create($claims);
        } catch (Throwable $exception) {
            error_log('[API Asistencia][login_session] ' . $exception->getMessage());
            Response::error(
                500,
                'session_creation_error',
                'No fue posible crear la sesión de acceso.'
            );
        }

        Response::json([
            'accessToken' => $jwt['token'],
            'tokenType' => 'Bearer',
            'expiresAtUtc' => gmdate('Y-m-d\TH:i:s\Z', $jwt['expiresAt']),
            'user' => [
                'id' => (int) $user['id_usuario'],
                'username' => (string) $user['username'],
                'email' => $user['email'],
                'fullName' => $fullName,
                'roleId' => (int) $user['id_rol'],
                'role' => (string) $user['nombre_rol'],
            ],
        ]);
    }

    public function forgotPassword(): never
    {
        $identifier = strtolower(trim((string) ($this->requestBody()['identifier'] ?? '')));
        if ($identifier === '' || strlen($identifier) > 100) {
            Response::error(422, 'validation_error', 'Ingresa tu usuario o correo electrónico.');
        }

        $requestFailed = false;
        try {
            $pdo = Database::connection();
            $statement = $pdo->prepare(
                'SELECT id_usuario, email FROM Usuarios
                 WHERE (LOWER(username) = :username
                    OR LOWER(email) = :email)
                   AND estado_activo = b\'1\' LIMIT 1'
            );
            $statement->execute([
                'username' => $identifier,
                'email' => $identifier,
            ]);
            $user = $statement->fetch();
            if (is_array($user) && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $hash = hash_hmac('sha256', $code, ApiConfig::jwtSecret());
                $pdo->beginTransaction();
                $invalidate = $pdo->prepare(
                    'UPDATE Password_Reset_Tokens SET used_at = UTC_TIMESTAMP()
                     WHERE id_usuario = :id_usuario AND used_at IS NULL'
                );
                $invalidate->execute(['id_usuario' => (int) $user['id_usuario']]);
                $insert = $pdo->prepare(
                    'INSERT INTO Password_Reset_Tokens
                        (id_usuario, token_hash, expires_at)
                     VALUES (:id_usuario, :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))'
                );
                $insert->execute([
                    'id_usuario' => (int) $user['id_usuario'],
                    'token_hash' => $hash,
                ]);
                if (!PasswordResetMailService::send(
                    (string) $user['email'],
                    $code
                )) {
                    throw new \RuntimeException(
                        'La función mail() no aceptó el mensaje.'
                    );
                }
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[API Asistencia][forgot_password] ' . $exception->getMessage());
            $requestFailed = true;
        }

        if ($requestFailed) {
            Response::error(
                503,
                'password_reset_unavailable',
                'No fue posible enviar el código. Intenta nuevamente más tarde.'
            );
        }

        Response::json(['message' => 'Si la cuenta existe, enviaremos un código al correo registrado.']);
    }

    public function resetPassword(): never
    {
        $body = $this->requestBody();
        $identifier = strtolower(trim((string) ($body['identifier'] ?? '')));
        $code = trim((string) ($body['code'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($identifier === '' || preg_match('/^\d{6}$/', $code) !== 1) {
            Response::error(422, 'invalid_reset_code', 'El código no es válido.');
        }
        if (strlen($password) < 8 || strlen($password) > 200
            || preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/\d/', $password) !== 1) {
            Response::error(422, 'weak_password', 'La contraseña debe tener al menos 8 caracteres, una mayúscula, una minúscula y un número.');
        }

        try {
            $pdo = Database::connection();
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT pr.id_reset, pr.token_hash, pr.attempts, u.id_usuario
                 FROM Password_Reset_Tokens pr INNER JOIN Usuarios u ON u.id_usuario = pr.id_usuario
                 WHERE (LOWER(u.username) = :username
                    OR LOWER(u.email) = :email)
                   AND pr.used_at IS NULL AND pr.expires_at > UTC_TIMESTAMP()
                 ORDER BY pr.id_reset DESC LIMIT 1 FOR UPDATE'
            );
            $statement->execute([
                'username' => $identifier,
                'email' => $identifier,
            ]);
            $reset = $statement->fetch();
            $valid = is_array($reset) && (int) $reset['attempts'] < 5
                && hash_equals((string) $reset['token_hash'], hash_hmac('sha256', $code, ApiConfig::jwtSecret()));
            if (!$valid) {
                if (is_array($reset)) {
                    $pdo->prepare('UPDATE Password_Reset_Tokens SET attempts = attempts + 1 WHERE id_reset = :id_reset')
                        ->execute(['id_reset' => (int) $reset['id_reset']]);
                }
                $pdo->commit();
                Response::error(422, 'invalid_reset_code', 'El código es incorrecto o ya venció.');
            }
            $pdo->prepare('UPDATE Usuarios SET password_hash = :password_hash WHERE id_usuario = :id_usuario')
                ->execute(['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'id_usuario' => (int) $reset['id_usuario']]);
            $pdo->prepare('UPDATE Password_Reset_Tokens SET used_at = UTC_TIMESTAMP() WHERE id_usuario = :id_usuario AND used_at IS NULL')
                ->execute(['id_usuario' => (int) $reset['id_usuario']]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[API Asistencia][reset_password] ' . $exception->getMessage());
            Response::error(503, 'password_reset_unavailable', 'No fue posible restablecer la contraseña.');
        }
        Response::json(['message' => 'La contraseña se actualizó correctamente.']);
    }
}
