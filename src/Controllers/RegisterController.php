<?php
declare(strict_types=1);

namespace Nordictech\Api\Controllers;

use DateTimeImmutable;
use JsonException;
use Nordictech\Api\Data\Database;
use Nordictech\Api\Http\Response;
use Nordictech\Api\Security\Jwt;
use PDO;
use PDOException;
use Throwable;

final class RegisterController
{
    public function register(): never
    {
        $body = $this->requestBody();

        $nombres = trim((string) ($body['nombres'] ?? ''));
        $apellidos = trim((string) ($body['apellidos'] ?? ''));
        $dui = trim((string) ($body['dui'] ?? ''));
        $telefono = trim((string) ($body['telefono'] ?? ''));
        $fechaNacimientoText = trim(
            (string) ($body['fechaNacimiento'] ?? '')
        );
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        $fechaNacimiento = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $fechaNacimientoText
        );
        $dateErrors = DateTimeImmutable::getLastErrors();
        $invalidDate = $fechaNacimiento === false
            || ($dateErrors !== false
                && ($dateErrors['warning_count'] > 0
                    || $dateErrors['error_count'] > 0));

        if ($nombres === ''
            || strlen($nombres) > 100
            || preg_match("/^[\p{L}\p{M} .'-]+$/u", $nombres) !== 1) {
            Response::error(
                422,
                'invalid_first_name',
                'Ingrese nombres válidos de hasta 100 caracteres.'
            );
        }

        if ($apellidos === ''
            || strlen($apellidos) > 100
            || preg_match("/^[\p{L}\p{M} .'-]+$/u", $apellidos) !== 1) {
            Response::error(
                422,
                'invalid_last_name',
                'Ingrese apellidos válidos de hasta 100 caracteres.'
            );
        }

        if (preg_match('/^\d{8}-\d$/', $dui) !== 1) {
            Response::error(
                422,
                'invalid_dui',
                'El DUI debe tener el formato 00000000-0.'
            );
        }

        if (preg_match('/^[267]\d{3}-?\d{4}$/', $telefono) !== 1) {
            Response::error(
                422,
                'invalid_phone',
                'Ingrese un teléfono salvadoreño válido de 8 dígitos.'
            );
        }

        if ($invalidDate
            || $fechaNacimiento > new DateTimeImmutable('-18 years')) {
            Response::error(
                422,
                'invalid_birth_date',
                'Debe ingresar una fecha válida y ser mayor de 18 años.'
            );
        }

        if (strlen($email) > 100
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Response::error(
                422,
                'invalid_email',
                'Ingrese un correo electrónico válido.'
            );
        }

        if (preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username) !== 1) {
            Response::error(
                422,
                'invalid_username',
                'El usuario debe tener entre 3 y 50 caracteres y solo puede usar letras, números, punto, guion y guion bajo.'
            );
        }

        if (strlen($password) < 8
            || strlen($password) > 200
            || preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/\d/', $password) !== 1) {
            Response::error(
                422,
                'weak_password',
                'La contraseña debe tener al menos 8 caracteres, una mayúscula, una minúscula y un número.'
            );
        }

        $connection = Database::connection();

        try {
            $connection->beginTransaction();

            $duplicateStatement = $connection->prepare(
                'SELECT username, email
                 FROM Usuarios
                 WHERE username = :username OR email = :email
                 LIMIT 1'
            );
            $duplicateStatement->execute([
                'username' => $username,
                'email' => $email,
            ]);
            $duplicateUser = $duplicateStatement->fetch();

            if (is_array($duplicateUser)) {
                $connection->rollBack();
                $message = strcasecmp(
                    (string) $duplicateUser['username'],
                    $username
                ) === 0
                    ? 'El nombre de usuario ya está registrado.'
                    : 'El correo electrónico ya está registrado.';
                Response::error(409, 'duplicate_user', $message);
            }

            $duiStatement = $connection->prepare(
                'SELECT id_datos_personales
                 FROM Datos_Personales
                 WHERE dui = :dui
                 LIMIT 1'
            );
            $duiStatement->execute(['dui' => $dui]);
            if ($duiStatement->fetch() !== false) {
                $connection->rollBack();
                Response::error(
                    409,
                    'duplicate_dui',
                    'El DUI ya está registrado.'
                );
            }

            $roleStatement = $connection->query(
                "SELECT id_rol, nombre_rol
                 FROM Roles
                 WHERE LOWER(nombre_rol) IN (
                    'tecnico', 'técnico', 'empleado', 'usuario'
                 )
                 ORDER BY CASE LOWER(nombre_rol)
                    WHEN 'tecnico' THEN 1
                    WHEN 'técnico' THEN 1
                    WHEN 'empleado' THEN 2
                    ELSE 3
                 END
                 LIMIT 1"
            );
            $role = $roleStatement->fetch();
            if (!is_array($role)) {
                throw new \RuntimeException(
                    'No existe un rol operativo para nuevos usuarios.'
                );
            }

            $personalStatement = $connection->prepare(
                'INSERT INTO Datos_Personales
                    (nombres, apellidos, dui, telefono, fecha_nacimiento)
                 VALUES
                    (:nombres, :apellidos, :dui, :telefono, :fecha_nacimiento)'
            );
            $personalStatement->execute([
                'nombres' => $nombres,
                'apellidos' => $apellidos,
                'dui' => $dui,
                'telefono' => $telefono,
                'fecha_nacimiento' => $fechaNacimiento->format('Y-m-d'),
            ]);
            $personalId = (int) $connection->lastInsertId();

            $userStatement = $connection->prepare(
                'INSERT INTO Usuarios
                    (username, password_hash, email, id_datos_personales,
                     id_rol, estado_activo)
                 VALUES
                    (:username, :password_hash, :email, :personal_id,
                     :role_id, b\'1\')'
            );
            $userStatement->execute([
                'username' => $username,
                'password_hash' => password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                'email' => $email,
                'personal_id' => $personalId,
                'role_id' => (int) $role['id_rol'],
            ]);
            $userId = (int) $connection->lastInsertId();

            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            error_log(
                '[API Asistencia][register] ' . $exception->getMessage()
            );

            if ($exception instanceof PDOException
                && $exception->getCode() === '23000') {
                Response::error(
                    409,
                    'duplicate_registration',
                    'El usuario, correo o DUI ya está registrado.'
                );
            }

            Response::error(
                500,
                'registration_error',
                'No fue posible crear la cuenta.'
            );
        }

        $claims = [
            'sub' => (string) $userId,
            'unique_name' => $username,
            'email' => $email,
            'role' => (string) $role['nombre_rol'],
            'role_id' => (int) $role['id_rol'],
        ];
        $jwt = Jwt::create($claims);

        Response::json([
            'accessToken' => $jwt['token'],
            'tokenType' => 'Bearer',
            'expiresAtUtc' => gmdate(
                'Y-m-d\TH:i:s\Z',
                $jwt['expiresAt']
            ),
            'user' => [
                'id' => $userId,
                'username' => $username,
                'email' => $email,
                'fullName' => trim($nombres . ' ' . $apellidos),
                'roleId' => (int) $role['id_rol'],
                'role' => (string) $role['nombre_rol'],
            ],
        ], 201);
    }

    /** @return array<string, mixed> */
    private function requestBody(): array
    {
        $contentType = strtolower(
            trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])
        );

        if ($contentType === 'application/x-www-form-urlencoded') {
            return $_POST;
        }

        try {
            $body = json_decode(
                file_get_contents('php://input') ?: '',
                true,
                16,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            Response::error(
                400,
                'invalid_json',
                'El cuerpo JSON es inválido.'
            );
        }

        if (!is_array($body)) {
            Response::error(
                400,
                'invalid_request',
                'La solicitud de registro es inválida.'
            );
        }

        return $body;
    }
}
