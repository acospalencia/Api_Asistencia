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

final class AttendanceController
{
    private const MAXIMUM_DISTANCE_METERS = 50.0;

    /** @param array<string, mixed> $claims */
    public function locations(array $claims): never
    {
        $userId = (int) ($claims['sub'] ?? 0);
        if ($userId <= 0) {
            Response::error(401, 'invalid_token', 'La sesión no identifica al usuario.');
        }

        try {
            $connection = Database::connection();
            $this->ensureActiveUser($connection, $userId);

            $statement = $connection->query(
                'SELECT
                    id_ubicacion,
                    nombre_lugar,
                    direccion,
                    ciudad,
                    latitud_esperada,
                    longitud_esperada
                 FROM Ubicaciones
                 WHERE estado_activo = 1
                 ORDER BY nombre_lugar'
            );

            $locations = array_map(
                static fn (array $location): array => [
                    'idUbicacion' => (int) $location['id_ubicacion'],
                    'nombreLugar' => (string) $location['nombre_lugar'],
                    'direccion' => $location['direccion'],
                    'ciudad' => $location['ciudad'],
                    'latitudEsperada' => (float) $location['latitud_esperada'],
                    'longitudEsperada' => (float) $location['longitud_esperada'],
                ],
                $statement->fetchAll()
            );
        } catch (InactiveUserException) {
            Response::error(
                403,
                'inactive_user',
                'La cuenta está inactiva o ya no existe.'
            );
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][locations_database] ' . $exception->getMessage()
            );
            Response::error(
                503,
                'database_unavailable',
                'No fue posible consultar las ubicaciones.'
            );
        }

        Response::json(['locations' => $locations]);
    }

    /** @param array<string, mixed> $claims */
    public function checkIn(array $claims): never
    {
        $body = $this->requestBody();
        $userId = (int) ($claims['sub'] ?? 0);
        $locationId = filter_var(
            $body['idUbicacion'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $latitude = filter_var($body['latitud'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($body['longitud'] ?? null, FILTER_VALIDATE_FLOAT);
        $comment = trim((string) ($body['comentario'] ?? ''));

        if ($userId <= 0) {
            Response::error(401, 'invalid_token', 'La sesión no identifica al usuario.');
        }

        if ($locationId === false) {
            Response::error(
                422,
                'invalid_location',
                'Debe seleccionar una ubicación válida.'
            );
        }

        if ($latitude === false
            || !is_finite($latitude)
            || $latitude < -90
            || $latitude > 90
            || $longitude === false
            || !is_finite($longitude)
            || $longitude < -180
            || $longitude > 180) {
            Response::error(
                422,
                'invalid_coordinates',
                'Las coordenadas GPS no son válidas.'
            );
        }

        if (strlen($comment) > 500) {
            Response::error(
                422,
                'comment_too_long',
                'El comentario no puede superar los 500 caracteres.'
            );
        }

        try {
            $connection = Database::connection();
            $this->ensureActiveUser($connection, $userId);

            $locationStatement = $connection->prepare(
                'SELECT
                    id_ubicacion,
                    nombre_lugar,
                    latitud_esperada,
                    longitud_esperada
                 FROM Ubicaciones
                 WHERE id_ubicacion = :id_ubicacion
                   AND estado_activo = 1
                 LIMIT 1'
            );
            $locationStatement->execute(['id_ubicacion' => $locationId]);
            $location = $locationStatement->fetch();
        } catch (InactiveUserException) {
            Response::error(
                403,
                'inactive_user',
                'La cuenta está inactiva o ya no existe.'
            );
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][check_in_validation] ' . $exception->getMessage()
            );
            Response::error(
                503,
                'database_unavailable',
                'No fue posible validar los datos de la entrada.'
            );
        }

        if (!is_array($location)) {
            Response::error(
                422,
                'invalid_location',
                'La ubicación seleccionada no existe o está inactiva.'
            );
        }

        $distanceMeters = $this->distanceMeters(
            $latitude,
            $longitude,
            (float) $location['latitud_esperada'],
            (float) $location['longitud_esperada']
        );

        if ($distanceMeters > self::MAXIMUM_DISTANCE_METERS) {
            Response::error(
                422,
                'outside_allowed_radius',
                sprintf(
                    'Está a %.0f metros de la ubicación seleccionada. El máximo permitido es %.0f metros.',
                    $distanceMeters,
                    self::MAXIMUM_DISTANCE_METERS
                )
            );
        }

        $now = new DateTimeImmutable(
            'now',
            new DateTimeZone('America/El_Salvador')
        );

        try {
            $connection->beginTransaction();

            $journeyStatement = $connection->prepare(
                'INSERT INTO Jornadas (id_usuario, fecha_jornada)
                 VALUES (:id_usuario, :fecha_jornada)
                 ON DUPLICATE KEY UPDATE
                    id_jornada = LAST_INSERT_ID(id_jornada)'
            );
            $journeyStatement->execute([
                'id_usuario' => $userId,
                'fecha_jornada' => $now->format('Y-m-d'),
            ]);
            $journeyId = (int) $connection->lastInsertId();

            $existingStatement = $connection->prepare(
                'SELECT id_entrada
                 FROM Asistencia_Entrada
                 WHERE id_jornada = :id_jornada
                 LIMIT 1
                 FOR UPDATE'
            );
            $existingStatement->execute(['id_jornada' => $journeyId]);

            if ($existingStatement->fetch() !== false) {
                $connection->rollBack();
                Response::error(
                    409,
                    'check_in_already_registered',
                    'La entrada de hoy ya fue registrada.'
                );
            }

            $checkInStatement = $connection->prepare(
                'INSERT INTO Asistencia_Entrada (
                    id_jornada,
                    id_ubicacion,
                    fecha_hora_entrada,
                    latitud_entrada,
                    longitud_entrada,
                    comentario_atraso
                 ) VALUES (
                    :id_jornada,
                    :id_ubicacion,
                    :fecha_hora_entrada,
                    :latitud_entrada,
                    :longitud_entrada,
                    :comentario_atraso
                 )'
            );
            $checkInStatement->execute([
                'id_jornada' => $journeyId,
                'id_ubicacion' => $locationId,
                'fecha_hora_entrada' => $now->format('Y-m-d H:i:s'),
                'latitud_entrada' => $latitude,
                'longitud_entrada' => $longitude,
                'comentario_atraso' => $comment === '' ? null : $comment,
            ]);
            $checkInId = (int) $connection->lastInsertId();

            $connection->commit();
        } catch (PDOException $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            if ($exception->getCode() === '23000') {
                Response::error(
                    409,
                    'check_in_already_registered',
                    'La entrada de hoy ya fue registrada.'
                );
            }

            error_log(
                '[API Asistencia][check_in_insert] ' . $exception->getMessage()
            );
            Response::error(
                503,
                'database_unavailable',
                'No fue posible registrar la entrada.'
            );
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            error_log(
                '[API Asistencia][check_in_insert] ' . $exception->getMessage()
            );
            Response::error(
                500,
                'check_in_failed',
                'Ocurrió un error al registrar la entrada.'
            );
        }

        Response::json([
            'idEntrada' => $checkInId,
            'idJornada' => $journeyId,
            'fechaHoraEntrada' => $now->format(DATE_ATOM),
            'ubicacion' => [
                'idUbicacion' => (int) $location['id_ubicacion'],
                'nombreLugar' => (string) $location['nombre_lugar'],
            ],
            'distanciaMetros' => round($distanceMeters, 2),
        ], 201);
    }

    /** @param array<string, mixed> $claims */
    public function checkOut(array $claims): never
    {
        $body = $this->requestBody();
        $userId = (int) ($claims['sub'] ?? 0);
        $locationId = filter_var(
            $body['idUbicacion'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $latitude = filter_var($body['latitud'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($body['longitud'] ?? null, FILTER_VALIDATE_FLOAT);
        $comment = trim((string) ($body['comentario'] ?? ''));

        if ($userId <= 0) {
            Response::error(401, 'invalid_token', 'La sesión no identifica al usuario.');
        }

        if ($locationId === false) {
            Response::error(
                422,
                'invalid_location',
                'Debe seleccionar una ubicación válida.'
            );
        }

        if ($latitude === false
            || !is_finite($latitude)
            || $latitude < -90
            || $latitude > 90
            || $longitude === false
            || !is_finite($longitude)
            || $longitude < -180
            || $longitude > 180) {
            Response::error(
                422,
                'invalid_coordinates',
                'Las coordenadas GPS no son válidas.'
            );
        }

        if (strlen($comment) > 500) {
            Response::error(
                422,
                'comment_too_long',
                'El comentario no puede superar los 500 caracteres.'
            );
        }

        $now = new DateTimeImmutable(
            'now',
            new DateTimeZone('America/El_Salvador')
        );

        if ($now->format('H:i:s') < '17:00:00' && $comment === '') {
            Response::error(
                422,
                'early_check_out_comment_required',
                'Debe justificar la salida realizada antes de las 5:00 p. m.'
            );
        }

        try {
            $connection = Database::connection();
            $this->ensureActiveUser($connection, $userId);

            $locationStatement = $connection->prepare(
                'SELECT
                    id_ubicacion,
                    nombre_lugar,
                    latitud_esperada,
                    longitud_esperada
                 FROM Ubicaciones
                 WHERE id_ubicacion = :id_ubicacion
                   AND estado_activo = 1
                 LIMIT 1'
            );
            $locationStatement->execute(['id_ubicacion' => $locationId]);
            $location = $locationStatement->fetch();
        } catch (InactiveUserException) {
            Response::error(
                403,
                'inactive_user',
                'La cuenta está inactiva o ya no existe.'
            );
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][check_out_validation] ' . $exception->getMessage()
            );
            Response::error(
                503,
                'database_unavailable',
                'No fue posible validar los datos de la salida.'
            );
        }

        if (!is_array($location)) {
            Response::error(
                422,
                'invalid_location',
                'La ubicación seleccionada no existe o está inactiva.'
            );
        }

        $distanceMeters = $this->distanceMeters(
            $latitude,
            $longitude,
            (float) $location['latitud_esperada'],
            (float) $location['longitud_esperada']
        );

        if ($distanceMeters > self::MAXIMUM_DISTANCE_METERS) {
            Response::error(
                422,
                'outside_allowed_radius',
                sprintf(
                    'Está a %.0f metros de la ubicación seleccionada. El máximo permitido es %.0f metros.',
                    $distanceMeters,
                    self::MAXIMUM_DISTANCE_METERS
                )
            );
        }

        try {
            $connection->beginTransaction();

            $journeyStatement = $connection->prepare(
                'SELECT id_jornada
                 FROM Jornadas
                 WHERE id_usuario = :id_usuario
                   AND fecha_jornada = :fecha_jornada
                 LIMIT 1
                 FOR UPDATE'
            );
            $journeyStatement->execute([
                'id_usuario' => $userId,
                'fecha_jornada' => $now->format('Y-m-d'),
            ]);
            $journey = $journeyStatement->fetch();

            if (!is_array($journey)) {
                $connection->rollBack();
                Response::error(
                    409,
                    'check_in_required',
                    'Debe registrar la entrada antes de registrar la salida.'
                );
            }

            $journeyId = (int) $journey['id_jornada'];
            $entryStatement = $connection->prepare(
                'SELECT id_entrada
                 FROM Asistencia_Entrada
                 WHERE id_jornada = :id_jornada
                 LIMIT 1'
            );
            $entryStatement->execute(['id_jornada' => $journeyId]);

            if ($entryStatement->fetch() === false) {
                $connection->rollBack();
                Response::error(
                    409,
                    'check_in_required',
                    'Debe registrar la entrada antes de registrar la salida.'
                );
            }

            $existingStatement = $connection->prepare(
                'SELECT id_salida
                 FROM Asistencia_Salida
                 WHERE id_jornada = :id_jornada
                 LIMIT 1
                 FOR UPDATE'
            );
            $existingStatement->execute(['id_jornada' => $journeyId]);

            if ($existingStatement->fetch() !== false) {
                $connection->rollBack();
                Response::error(
                    409,
                    'check_out_already_registered',
                    'La salida de hoy ya fue registrada.'
                );
            }

            $activeMissionStatement = $connection->prepare(
                "SELECT id_evento
                 FROM Eventos_Jornada
                 WHERE id_jornada = :id_jornada
                   AND estado_evento = 'EN_TRAYECTO'
                 LIMIT 1
                 FOR UPDATE"
            );
            $activeMissionStatement->execute([
                'id_jornada' => $journeyId,
            ]);

            if ($activeMissionStatement->fetch() !== false) {
                $connection->rollBack();
                Response::error(
                    409,
                    'mission_in_progress',
                    'Debe finalizar o cancelar la misión en trayecto antes de marcar su salida.'
                );
            }

            $checkOutStatement = $connection->prepare(
                'INSERT INTO Asistencia_Salida (
                    id_jornada,
                    id_ubicacion,
                    fecha_hora_salida,
                    latitud_salida,
                    longitud_salida,
                    comentario_retiro_anticipado
                 ) VALUES (
                    :id_jornada,
                    :id_ubicacion,
                    :fecha_hora_salida,
                    :latitud_salida,
                    :longitud_salida,
                    :comentario_retiro_anticipado
                 )'
            );
            $checkOutStatement->execute([
                'id_jornada' => $journeyId,
                'id_ubicacion' => $locationId,
                'fecha_hora_salida' => $now->format('Y-m-d H:i:s'),
                'latitud_salida' => $latitude,
                'longitud_salida' => $longitude,
                'comentario_retiro_anticipado' =>
                    $comment === '' ? null : $comment,
            ]);
            $checkOutId = (int) $connection->lastInsertId();

            $connection->commit();
        } catch (PDOException $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            if ($exception->getCode() === '23000') {
                Response::error(
                    409,
                    'check_out_already_registered',
                    'La salida de hoy ya fue registrada.'
                );
            }

            error_log(
                '[API Asistencia][check_out_insert] ' . $exception->getMessage()
            );
            Response::error(
                503,
                'database_unavailable',
                'No fue posible registrar la salida.'
            );
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            error_log(
                '[API Asistencia][check_out_insert] ' . $exception->getMessage()
            );
            Response::error(
                500,
                'check_out_failed',
                'Ocurrió un error al registrar la salida.'
            );
        }

        Response::json([
            'idSalida' => $checkOutId,
            'idJornada' => $journeyId,
            'fechaHoraSalida' => $now->format(DATE_ATOM),
            'ubicacion' => [
                'idUbicacion' => (int) $location['id_ubicacion'],
                'nombreLugar' => (string) $location['nombre_lugar'],
            ],
            'distanciaMetros' => round($distanceMeters, 2),
        ], 201);
    }

    private function ensureActiveUser(PDO $connection, int $userId): void
    {
        $statement = $connection->prepare(
            'SELECT id_usuario
             FROM Usuarios
             WHERE id_usuario = :id_usuario
               AND estado_activo = 1
             LIMIT 1'
        );
        $statement->execute(['id_usuario' => $userId]);

        if ($statement->fetch() === false) {
            throw new InactiveUserException();
        }
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
            Response::error(400, 'invalid_json', 'El cuerpo JSON es inválido.');
        }

        if (!is_array($body)) {
            Response::error(400, 'invalid_json', 'El cuerpo JSON es inválido.');
        }

        return $body;
    }

    private function distanceMeters(
        float $sourceLatitude,
        float $sourceLongitude,
        float $targetLatitude,
        float $targetLongitude
    ): float {
        $earthRadiusMeters = 6_371_000.0;
        $latitudeDifference = deg2rad($targetLatitude - $sourceLatitude);
        $longitudeDifference = deg2rad($targetLongitude - $sourceLongitude);

        $a = sin($latitudeDifference / 2) ** 2
            + cos(deg2rad($sourceLatitude))
            * cos(deg2rad($targetLatitude))
            * sin($longitudeDifference / 2) ** 2;

        return $earthRadiusMeters
            * 2
            * atan2(sqrt($a), sqrt(1 - $a));
    }
}

final class InactiveUserException extends \RuntimeException
{
}
