<?php
declare(strict_types=1);

namespace Nordictech\Api\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Nordictech\Api\Data\Database;
use Nordictech\Api\Http\Response;
use PDO;
use PDOException;
use Throwable;

final class NonWorkingDayController
{
    /** @param array<string, mixed> $claims */
    public function today(array $claims): never
    {
        $userId = (int) ($claims['sub'] ?? 0);
        if ($userId <= 0) {
            Response::error(
                401,
                'invalid_token',
                'La sesión no identifica al usuario.'
            );
        }

        $today = new DateTimeImmutable(
            'now',
            new DateTimeZone('America/El_Salvador')
        );

        try {
            $connection = Database::connection();
            $userStatement = $connection->prepare(
                'SELECT r.nombre_rol
                 FROM Usuarios u
                 INNER JOIN Roles r ON r.id_rol = u.id_rol
                 WHERE u.id_usuario = :id_usuario
                   AND u.estado_activo = 1
                 LIMIT 1'
            );
            $userStatement->execute(['id_usuario' => $userId]);
            $role = $userStatement->fetchColumn();

            if (!is_string($role)) {
                Response::error(
                    403,
                    'inactive_user',
                    'La cuenta está inactiva o ya no existe.'
                );
            }

            $statement = $connection->prepare(
                'SELECT id_dia_no_laboral, fecha, motivo
                 FROM Dias_No_Laborales
                 WHERE fecha = :fecha
                 LIMIT 1'
            );
            $statement->execute(['fecha' => $today->format('Y-m-d')]);
            $day = $statement->fetch();
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][non_working_day_today] '
                . $exception->getMessage()
            );
            Response::error(
                503,
                'availability_unavailable',
                'No fue posible validar si hoy se permite marcar asistencia.'
            );
        }

        $normalizedRole = strtolower(trim($role));
        $isTechnician = in_array(
            $normalizedRole,
            ['tecnico', 'técnico'],
            true
        );
        $blocked = $isTechnician && is_array($day);

        Response::json([
            'date' => $today->format('Y-m-d'),
            'attendanceAllowed' => !$blocked,
            'isTechnician' => $isTechnician,
            'reason' => $blocked ? (string) $day['motivo'] : null,
        ]);
    }

    /** @param array<string, mixed> $claims */
    public function index(array $claims): never
    {
        $connection = $this->requireAdministrator($claims);

        try {
            $rows = $connection->query(
                'SELECT
                    d.id_dia_no_laboral,
                    d.fecha,
                    d.motivo,
                    d.fecha_creacion,
                    u.username
                 FROM Dias_No_Laborales d
                 INNER JOIN Usuarios u
                    ON u.id_usuario = d.id_usuario_crea
                 ORDER BY d.fecha DESC'
            )->fetchAll();
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][non_working_days_list] '
                . $exception->getMessage()
            );
            Response::error(
                503,
                'non_working_days_unavailable',
                'No fue posible consultar los días sin marcación.'
            );
        }

        Response::json([
            'days' => array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id_dia_no_laboral'],
                    'date' => (string) $row['fecha'],
                    'reason' => (string) $row['motivo'],
                    'createdAt' => (string) $row['fecha_creacion'],
                    'createdBy' => (string) $row['username'],
                ],
                $rows
            ),
        ]);
    }

    /** @param array<string, mixed> $claims */
    public function create(array $claims): never
    {
        $connection = $this->requireAdministrator($claims);
        $administratorId = (int) $claims['sub'];
        $body = $this->requestBody();
        $dateText = trim((string) ($body['date'] ?? ''));
        $reason = trim((string) ($body['reason'] ?? ''));

        $timeZone = new DateTimeZone('America/El_Salvador');
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $dateText,
            $timeZone
        );
        $errors = DateTimeImmutable::getLastErrors();
        $invalidDate = $date === false
            || ($errors !== false
                && ($errors['warning_count'] > 0
                    || $errors['error_count'] > 0));

        $today = new DateTimeImmutable('today', $timeZone);

        if ($invalidDate || $date < $today) {
            Response::error(
                422,
                'invalid_non_working_date',
                'Seleccione la fecha de hoy o una fecha futura.'
            );
        }

        if (strlen($reason) < 3 || strlen($reason) > 255) {
            Response::error(
                422,
                'invalid_non_working_reason',
                'El motivo debe contener entre 3 y 255 caracteres.'
            );
        }

        try {
            $statement = $connection->prepare(
                'INSERT INTO Dias_No_Laborales
                    (fecha, motivo, id_usuario_crea)
                 VALUES (:fecha, :motivo, :id_usuario_crea)
                 ON DUPLICATE KEY UPDATE
                    motivo = VALUES(motivo),
                    id_usuario_crea = VALUES(id_usuario_crea),
                    fecha_creacion = CURRENT_TIMESTAMP'
            );
            $statement->execute([
                'fecha' => $date->format('Y-m-d'),
                'motivo' => $reason,
                'id_usuario_crea' => $administratorId,
            ]);
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][non_working_day_create] '
                . $exception->getMessage()
            );
            Response::error(
                500,
                'non_working_day_create_failed',
                'No fue posible guardar el día sin marcación.'
            );
        }

        Response::json([
            'message' => 'Día sin marcación guardado correctamente.',
        ], 201);
    }

    /** @param array<string, mixed> $claims */
    public function delete(array $claims, int $dayId): never
    {
        $connection = $this->requireAdministrator($claims);

        if ($dayId <= 0) {
            Response::error(
                422,
                'invalid_non_working_day',
                'El día seleccionado no es válido.'
            );
        }

        try {
            $statement = $connection->prepare(
                'DELETE FROM Dias_No_Laborales
                 WHERE id_dia_no_laboral = :id'
            );
            $statement->execute(['id' => $dayId]);
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][non_working_day_delete] '
                . $exception->getMessage()
            );
            Response::error(
                500,
                'non_working_day_delete_failed',
                'No fue posible habilitar nuevamente el día.'
            );
        }

        if ($statement->rowCount() === 0) {
            Response::error(
                404,
                'non_working_day_not_found',
                'El día seleccionado ya no existe.'
            );
        }

        Response::json([
            'message' => 'El día volvió a quedar habilitado para asistencia.',
        ]);
    }

    /** @param array<string, mixed> $claims */
    private function requireAdministrator(array $claims): PDO
    {
        $userId = (int) ($claims['sub'] ?? 0);
        if ($userId <= 0) {
            Response::error(
                401,
                'invalid_token',
                'La sesión no identifica al usuario.'
            );
        }

        try {
            $connection = Database::connection();
            $statement = $connection->prepare(
                'SELECT r.nombre_rol
                 FROM Usuarios u
                 INNER JOIN Roles r ON r.id_rol = u.id_rol
                 WHERE u.id_usuario = :id_usuario
                   AND u.estado_activo = 1
                 LIMIT 1'
            );
            $statement->execute(['id_usuario' => $userId]);
            $role = $statement->fetchColumn();
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][non_working_day_authorization] '
                . $exception->getMessage()
            );
            Response::error(
                503,
                'authorization_unavailable',
                'No fue posible validar los permisos administrativos.'
            );
        }

        if (!is_string($role)
            || !in_array(
                strtolower(trim($role)),
                [
                    'admin',
                    'administrador',
                    'administración',
                    'administracion',
                ],
                true
            )) {
            Response::error(
                403,
                'administrator_required',
                'Esta operación requiere un rol administrativo.'
            );
        }

        return $connection;
    }

    /** @return array<string, mixed> */
    private function requestBody(): array
    {
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
                'La solicitud es inválida.'
            );
        }

        return $body;
    }
}
