<?php
declare(strict_types=1);

namespace Nordictech\Api\Controllers;

use JsonException;
use Nordictech\Api\Data\Database;
use Nordictech\Api\Http\Response;
use Nordictech\Api\Security\Jwt;
use Throwable;

final class AuthController
{
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
}
