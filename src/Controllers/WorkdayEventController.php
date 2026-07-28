<?php
declare(strict_types=1);

namespace Nordictech\Api\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Nordictech\Api\Data\Database;
use Nordictech\Api\Http\Response;
use Nordictech\Api\Services\PushNotificationService;
use PDO;
use Throwable;

final class WorkdayEventController
{
    private const STATUS_PENDING = 'PENDIENTE';
    private const STATUS_IN_PROGRESS = 'EN_TRAYECTO';
    private const STATUS_COMPLETED = 'COMPLETADO';
    private const STATUS_CANCELLED = 'CANCELADO';

    private const EVENT_TYPES = [
        'VISITA_TECNICA',
        'ENTREGA',
        'COMPRA',
        'TRASLADO',
    ];

    /** @param array<string, mixed> $claims */
    public function supervisorIndex(array $claims): never
    {
        $connection = $this->requireRole($claims, 'supervisor');
        $supervisorId = (int) $claims['sub'];
        $date = $this->today();

        try {
            $technicianStatement = $connection->prepare(
                "SELECT
                    t.id_usuario AS id,
                    t.username,
                    t.email,
                    TRIM(CONCAT(dp.nombres, ' ', dp.apellidos)) AS full_name,
                    j.id_jornada
                 FROM Asignacion_Supervisores a
                 INNER JOIN Usuarios t ON t.id_usuario = a.id_tecnico
                 INNER JOIN Roles r ON r.id_rol = t.id_rol
                 INNER JOIN Datos_Personales dp
                    ON dp.id_datos_personales = t.id_datos_personales
                 INNER JOIN Jornadas j
                    ON j.id_usuario = t.id_usuario
                   AND j.fecha_jornada = :fecha_jornada
                 INNER JOIN Asistencia_Entrada ae
                    ON ae.id_jornada = j.id_jornada
                 LEFT JOIN Asistencia_Salida salida
                    ON salida.id_jornada = j.id_jornada
                 WHERE a.id_supervisor = :id_supervisor
                   AND a.estado_activo = 1
                   AND t.estado_activo = 1
                   AND salida.id_salida IS NULL
                   AND LOWER(r.nombre_rol) IN ('tecnico', 'técnico')
                 ORDER BY dp.nombres, dp.apellidos"
            );
            $technicianStatement->execute([
                'fecha_jornada' => $date,
                'id_supervisor' => $supervisorId,
            ]);

            $eventStatement = $connection->prepare(
                $this->eventSelectSql()
                . " WHERE ev.id_supervisor_autoriza = :id_supervisor
                      AND j.fecha_jornada = :fecha_jornada
                    ORDER BY
                      CASE ev.estado_evento
                        WHEN 'EN_TRAYECTO' THEN 1
                        WHEN 'PENDIENTE' THEN 2
                        WHEN 'COMPLETADO' THEN 3
                        ELSE 4
                      END,
                      ev.hora_asignacion DESC"
            );
            $eventStatement->execute([
                'id_supervisor' => $supervisorId,
                'fecha_jornada' => $date,
            ]);

            $activeTechnicianIds = [];
            $events = [];
            foreach ($eventStatement->fetchAll() as $row) {
                $event = $this->mapEvent($row);
                $events[] = $event;
                if (in_array(
                    $event['status'],
                    [self::STATUS_PENDING, self::STATUS_IN_PROGRESS],
                    true
                )) {
                    $activeTechnicianIds[] = $event['technicianId'];
                }
            }

            $technicians = array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'journeyId' => (int) $row['id_jornada'],
                    'username' => (string) $row['username'],
                    'email' => $row['email'],
                    'fullName' => (string) $row['full_name'],
                    'hasActiveMission' => in_array(
                        (int) $row['id'],
                        $activeTechnicianIds,
                        true
                    ),
                ],
                $technicianStatement->fetchAll()
            );
        } catch (Throwable $exception) {
            $this->databaseError(
                'supervisor_events',
                'No fue posible cargar las misiones de la jornada.',
                $exception
            );
        }

        Response::json([
            'date' => $date,
            'eventTypes' => [
                ['value' => 'VISITA_TECNICA', 'label' => 'Visita técnica'],
                ['value' => 'ENTREGA', 'label' => 'Entrega'],
                ['value' => 'COMPRA', 'label' => 'Compra'],
                ['value' => 'TRASLADO', 'label' => 'Traslado'],
                ['value' => 'OTRO', 'label' => 'Otro'],
            ],
            'technicians' => $technicians,
            'events' => $events,
        ]);
    }

    /** @param array<string, mixed> $claims */
    public function create(array $claims): never
    {
        $connection = $this->requireRole($claims, 'supervisor');
        $supervisorId = (int) $claims['sub'];
        $body = $this->requestBody();
        $technicianId = filter_var(
            $body['technicianId'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $type = strtoupper(trim((string) ($body['type'] ?? '')));
        $customType = trim((string) ($body['customType'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));

        if ($technicianId === false) {
            Response::error(
                422,
                'invalid_technician',
                'Debe seleccionar un técnico válido.'
            );
        }

        if ($type === 'OTRO') {
            $type = $customType;
        } elseif (!in_array($type, self::EVENT_TYPES, true)) {
            Response::error(
                422,
                'invalid_event_type',
                'Debe seleccionar un tipo de misión válido.'
            );
        }

        if ($type === '' || strlen($type) > 50) {
            Response::error(
                422,
                'invalid_event_type',
                'El tipo personalizado es obligatorio y admite hasta 50 caracteres.'
            );
        }

        if ($description === '' || strlen($description) > 500) {
            Response::error(
                422,
                'invalid_description',
                'La misión es obligatoria y admite hasta 500 caracteres.'
            );
        }

        $now = $this->now();
        try {
            $connection->beginTransaction();

            $journeyStatement = $connection->prepare(
                "SELECT j.id_jornada
                 FROM Asignacion_Supervisores a
                 INNER JOIN Usuarios t ON t.id_usuario = a.id_tecnico
                 INNER JOIN Jornadas j
                    ON j.id_usuario = t.id_usuario
                   AND j.fecha_jornada = :fecha_jornada
                 INNER JOIN Asistencia_Entrada entrada
                    ON entrada.id_jornada = j.id_jornada
                 LEFT JOIN Asistencia_Salida salida
                    ON salida.id_jornada = j.id_jornada
                 WHERE a.id_supervisor = :id_supervisor
                   AND a.id_tecnico = :id_tecnico
                   AND a.estado_activo = 1
                   AND t.estado_activo = 1
                   AND salida.id_salida IS NULL
                 LIMIT 1
                 FOR UPDATE"
            );
            $journeyStatement->execute([
                'fecha_jornada' => $now->format('Y-m-d'),
                'id_supervisor' => $supervisorId,
                'id_tecnico' => $technicianId,
            ]);
            $journeyId = $journeyStatement->fetchColumn();

            if ($journeyId === false) {
                $connection->rollBack();
                Response::error(
                    409,
                    'technician_not_available',
                    'El técnico no está asignado, no ha marcado entrada o ya finalizó su jornada.'
                );
            }

            $activeStatement = $connection->prepare(
                "SELECT id_evento
                 FROM Eventos_Jornada
                 WHERE id_jornada = :id_jornada
                   AND estado_evento IN ('PENDIENTE', 'EN_TRAYECTO')
                 LIMIT 1
                 FOR UPDATE"
            );
            $activeStatement->execute(['id_jornada' => (int) $journeyId]);
            if ($activeStatement->fetch() !== false) {
                $connection->rollBack();
                Response::error(
                    409,
                    'active_mission_exists',
                    'El técnico ya tiene una misión activa.'
                );
            }

            $insertStatement = $connection->prepare(
                'INSERT INTO Eventos_Jornada (
                    id_jornada,
                    id_supervisor_autoriza,
                    tipo_evento,
                    descripcion_mision,
                    estado_evento,
                    hora_asignacion
                 ) VALUES (
                    :id_jornada,
                    :id_supervisor,
                    :tipo_evento,
                    :descripcion,
                    :estado,
                    :hora_asignacion
                 )'
            );
            $insertStatement->execute([
                'id_jornada' => (int) $journeyId,
                'id_supervisor' => $supervisorId,
                'tipo_evento' => $type,
                'descripcion' => $description,
                'estado' => self::STATUS_PENDING,
                'hora_asignacion' => $now->format('Y-m-d H:i:s'),
            ]);
            $eventId = (int) $connection->lastInsertId();
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $this->databaseError(
                'create_event',
                'No fue posible asignar la misión.',
                $exception
            );
        }

        PushNotificationService::sendToUser(
            $technicianId,
            'Nueva misión asignada',
            $description,
            ['eventId' => (string) $eventId, 'route' => '/misiones']
        );

        Response::json([
            'idEvento' => $eventId,
            'status' => self::STATUS_PENDING,
            'message' => 'La misión fue asignada correctamente.',
        ], 201);
    }

    /** @param array<string, mixed> $claims */
    public function cancelPending(array $claims, int $eventId): never
    {
        $connection = $this->requireRole($claims, 'supervisor');
        $supervisorId = (int) $claims['sub'];

        try {
            $connection->beginTransaction();
            $eventStatement = $connection->prepare(
                "SELECT j.id_usuario
                 FROM Eventos_Jornada ev
                 INNER JOIN Jornadas j ON j.id_jornada = ev.id_jornada
                 WHERE ev.id_evento = :id_evento
                   AND ev.id_supervisor_autoriza = :id_supervisor
                   AND ev.estado_evento = 'PENDIENTE'
                 LIMIT 1
                 FOR UPDATE"
            );
            $eventStatement->execute([
                'id_evento' => $eventId,
                'id_supervisor' => $supervisorId,
            ]);
            $technicianId = $eventStatement->fetchColumn();
            if ($technicianId === false) {
                $connection->rollBack();
                Response::error(
                    409,
                    'event_cannot_be_cancelled',
                    'Solo puede cancelar una misión pendiente que usted haya asignado.'
                );
            }

            $statement = $connection->prepare(
                "UPDATE Eventos_Jornada
                 SET estado_evento = 'CANCELADO'
                 WHERE id_evento = :id_evento"
            );
            $statement->execute(['id_evento' => $eventId]);
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $this->databaseError(
                'cancel_event',
                'No fue posible cancelar la misión.',
                $exception
            );
        }

        PushNotificationService::sendToUser(
            (int) $technicianId,
            'Misión cancelada',
            'El supervisor canceló una misión que aún estaba pendiente.',
            ['eventId' => (string) $eventId, 'route' => '/misiones']
        );

        Response::json(['message' => 'La misión fue cancelada.']);
    }

    /** @param array<string, mixed> $claims */
    public function requestCancellation(array $claims, int $eventId): never
    {
        $connection = $this->requireRole($claims, 'supervisor');
        $supervisorId = (int) $claims['sub'];
        $body = $this->requestBody();
        $message = trim((string) ($body['message'] ?? ''));

        if ($message === '' || strlen($message) > 500) {
            Response::error(
                422,
                'invalid_message',
                'Escriba un mensaje de hasta 500 caracteres para el técnico.'
            );
        }

        try {
            $connection->beginTransaction();
            $statement = $connection->prepare(
                "SELECT j.id_usuario
                 FROM Eventos_Jornada ev
                 INNER JOIN Jornadas j ON j.id_jornada = ev.id_jornada
                 WHERE ev.id_evento = :id_evento
                   AND ev.id_supervisor_autoriza = :id_supervisor
                   AND ev.estado_evento = 'EN_TRAYECTO'
                 LIMIT 1
                 FOR UPDATE"
            );
            $statement->execute([
                'id_evento' => $eventId,
                'id_supervisor' => $supervisorId,
            ]);
            $technicianId = $statement->fetchColumn();
            if ($technicianId === false) {
                $connection->rollBack();
                Response::error(
                    409,
                    'cancellation_request_not_allowed',
                    'Solo puede solicitar la cancelación de una misión en trayecto.'
                );
            }

            $updateStatement = $connection->prepare(
                'UPDATE Eventos_Jornada
                 SET mensaje_supervisor = :mensaje,
                     hora_mensaje_supervisor = :hora_mensaje
                 WHERE id_evento = :id_evento'
            );
            $updateStatement->execute([
                'mensaje' => $message,
                'hora_mensaje' => $this->now()->format('Y-m-d H:i:s'),
                'id_evento' => $eventId,
            ]);
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $this->databaseError(
                'request_event_cancellation',
                'No fue posible enviar la solicitud de cancelación.',
                $exception
            );
        }

        PushNotificationService::sendToUser(
            (int) $technicianId,
            'Solicitud sobre misión',
            $message,
            ['eventId' => (string) $eventId, 'route' => '/misiones']
        );

        Response::json([
            'message' => 'La solicitud fue enviada al técnico.',
        ]);
    }

    /** @param array<string, mixed> $claims */
    public function technicianIndex(array $claims): never
    {
        $connection = $this->requireActiveUser($claims);
        $technicianId = (int) $claims['sub'];

        try {
            $statement = $connection->prepare(
                $this->eventSelectSql()
                . " WHERE j.id_usuario = :id_tecnico
                      AND j.fecha_jornada = :fecha_jornada
                    ORDER BY
                      CASE ev.estado_evento
                        WHEN 'EN_TRAYECTO' THEN 1
                        WHEN 'PENDIENTE' THEN 2
                        WHEN 'COMPLETADO' THEN 3
                        ELSE 4
                      END,
                      ev.hora_asignacion DESC"
            );
            $statement->execute([
                'id_tecnico' => $technicianId,
                'fecha_jornada' => $this->today(),
            ]);
            $events = array_map(
                fn (array $row): array => $this->mapEvent($row),
                $statement->fetchAll()
            );
        } catch (Throwable $exception) {
            $this->databaseError(
                'technician_events',
                'No fue posible cargar sus misiones.',
                $exception
            );
        }

        $active = array_values(array_filter(
            $events,
            static fn (array $event): bool => in_array(
                $event['status'],
                [self::STATUS_PENDING, self::STATUS_IN_PROGRESS],
                true
            )
        ));

        Response::json([
            'date' => $this->today(),
            'hasMissionInProgress' => count(array_filter(
                $active,
                static fn (array $event): bool =>
                    $event['status'] === self::STATUS_IN_PROGRESS
            )) > 0,
            'activeEvents' => $active,
            'events' => $events,
        ]);
    }

    /** @param array<string, mixed> $claims */
    public function updateComment(array $claims, int $eventId): never
    {
        $connection = $this->requireActiveUser($claims);
        $technicianId = (int) $claims['sub'];
        $body = $this->requestBody();
        $comment = trim((string) ($body['comment'] ?? ''));

        if (strlen($comment) > 500) {
            Response::error(
                422,
                'comment_too_long',
                'El comentario admite hasta 500 caracteres.'
            );
        }

        try {
            $statement = $connection->prepare(
                "UPDATE Eventos_Jornada ev
                 INNER JOIN Jornadas j ON j.id_jornada = ev.id_jornada
                 SET ev.comentario_tecnico = :comentario
                 WHERE ev.id_evento = :id_evento
                   AND j.id_usuario = :id_tecnico
                   AND ev.estado_evento IN ('PENDIENTE', 'EN_TRAYECTO')"
            );
            $statement->execute([
                'comentario' => $comment === '' ? null : $comment,
                'id_evento' => $eventId,
                'id_tecnico' => $technicianId,
            ]);
        } catch (Throwable $exception) {
            $this->databaseError(
                'update_event_comment',
                'No fue posible guardar el comentario.',
                $exception
            );
        }

        if ($statement->rowCount() !== 1) {
            $activeStatement = $connection->prepare(
                "SELECT 1
                 FROM Eventos_Jornada ev
                 INNER JOIN Jornadas j ON j.id_jornada = ev.id_jornada
                 WHERE ev.id_evento = :id_evento
                   AND j.id_usuario = :id_tecnico
                   AND ev.estado_evento IN ('PENDIENTE', 'EN_TRAYECTO')
                 LIMIT 1"
            );
            $activeStatement->execute([
                'id_evento' => $eventId,
                'id_tecnico' => $technicianId,
            ]);
            if ($activeStatement->fetchColumn() === false) {
                Response::error(
                    409,
                    'comment_not_allowed',
                    'El comentario solo puede modificarse mientras la misión esté activa.'
                );
            }
        }

        Response::json(['message' => 'Comentario guardado.']);
    }

    /** @param array<string, mixed> $claims */
    public function start(array $claims, int $eventId): never
    {
        $connection = $this->requireActiveUser($claims);
        $technicianId = (int) $claims['sub'];
        [$latitude, $longitude] = $this->coordinates($this->requestBody());

        try {
            $statement = $connection->prepare(
                "UPDATE Eventos_Jornada ev
                 INNER JOIN Jornadas j ON j.id_jornada = ev.id_jornada
                 SET ev.estado_evento = 'EN_TRAYECTO',
                     ev.hora_inicio_trayecto = :hora_inicio,
                     ev.latitud_inicio = :latitud,
                     ev.longitud_inicio = :longitud
                 WHERE ev.id_evento = :id_evento
                   AND j.id_usuario = :id_tecnico
                   AND ev.estado_evento = 'PENDIENTE'"
            );
            $statement->execute([
                'hora_inicio' => $this->now()->format('Y-m-d H:i:s'),
                'latitud' => $latitude,
                'longitud' => $longitude,
                'id_evento' => $eventId,
                'id_tecnico' => $technicianId,
            ]);
        } catch (Throwable $exception) {
            $this->databaseError(
                'start_event',
                'No fue posible iniciar el trayecto.',
                $exception
            );
        }

        if ($statement->rowCount() !== 1) {
            Response::error(
                409,
                'event_cannot_start',
                'La misión ya no está pendiente o no le pertenece.'
            );
        }

        Response::json([
            'status' => self::STATUS_IN_PROGRESS,
            'message' => 'Trayecto iniciado.',
        ]);
    }

    /** @param array<string, mixed> $claims */
    public function complete(array $claims, int $eventId): never
    {
        $connection = $this->requireActiveUser($claims);
        $technicianId = (int) $claims['sub'];
        $body = $this->requestBody();
        [$latitude, $longitude] = $this->coordinates($body);
        $comment = trim((string) ($body['comment'] ?? ''));

        if ($comment === '' || strlen($comment) > 500) {
            Response::error(
                422,
                'completion_comment_required',
                'Debe escribir un comentario final de hasta 500 caracteres.'
            );
        }

        try {
            $statement = $connection->prepare(
                "UPDATE Eventos_Jornada ev
                 INNER JOIN Jornadas j ON j.id_jornada = ev.id_jornada
                 SET ev.estado_evento = 'COMPLETADO',
                     ev.hora_fin_trayecto = :hora_fin,
                     ev.latitud_fin = :latitud,
                     ev.longitud_fin = :longitud,
                     ev.comentario_tecnico = :comentario
                 WHERE ev.id_evento = :id_evento
                   AND j.id_usuario = :id_tecnico
                   AND ev.estado_evento = 'EN_TRAYECTO'"
            );
            $statement->execute([
                'hora_fin' => $this->now()->format('Y-m-d H:i:s'),
                'latitud' => $latitude,
                'longitud' => $longitude,
                'comentario' => $comment,
                'id_evento' => $eventId,
                'id_tecnico' => $technicianId,
            ]);
        } catch (Throwable $exception) {
            $this->databaseError(
                'complete_event',
                'No fue posible finalizar la misión.',
                $exception
            );
        }

        if ($statement->rowCount() !== 1) {
            Response::error(
                409,
                'event_cannot_complete',
                'La misión no está en trayecto o no le pertenece.'
            );
        }

        Response::json([
            'status' => self::STATUS_COMPLETED,
            'message' => 'Misión completada.',
        ]);
    }

    /** @param array<string, mixed> $claims */
    public function technicianCancel(array $claims, int $eventId): never
    {
        $connection = $this->requireActiveUser($claims);
        $technicianId = (int) $claims['sub'];
        $body = $this->requestBody();
        $comment = trim((string) ($body['comment'] ?? ''));

        if ($comment === '' || strlen($comment) > 500) {
            Response::error(
                422,
                'cancellation_comment_required',
                'Debe indicar el motivo de la cancelación.'
            );
        }

        try {
            $statement = $connection->prepare(
                "UPDATE Eventos_Jornada ev
                 INNER JOIN Jornadas j ON j.id_jornada = ev.id_jornada
                 SET ev.estado_evento = 'CANCELADO',
                     ev.hora_fin_trayecto = :hora_fin,
                     ev.comentario_tecnico = :comentario
                 WHERE ev.id_evento = :id_evento
                   AND j.id_usuario = :id_tecnico
                   AND ev.estado_evento = 'EN_TRAYECTO'
                   AND ev.mensaje_supervisor IS NOT NULL"
            );
            $statement->execute([
                'hora_fin' => $this->now()->format('Y-m-d H:i:s'),
                'comentario' => $comment,
                'id_evento' => $eventId,
                'id_tecnico' => $technicianId,
            ]);
        } catch (Throwable $exception) {
            $this->databaseError(
                'technician_cancel_event',
                'No fue posible cancelar la misión.',
                $exception
            );
        }

        if ($statement->rowCount() !== 1) {
            Response::error(
                409,
                'technician_cancellation_not_allowed',
                'Solo puede cancelar una misión en trayecto cuando el supervisor lo haya solicitado.'
            );
        }

        Response::json([
            'status' => self::STATUS_CANCELLED,
            'message' => 'Misión cancelada.',
        ]);
    }

    private function eventSelectSql(): string
    {
        return "SELECT
                    ev.id_evento,
                    ev.id_jornada,
                    ev.id_supervisor_autoriza,
                    ev.tipo_evento,
                    ev.descripcion_mision,
                    ev.comentario_tecnico,
                    ev.mensaje_supervisor,
                    ev.hora_mensaje_supervisor,
                    ev.estado_evento,
                    ev.hora_asignacion,
                    ev.hora_inicio_trayecto,
                    ev.hora_fin_trayecto,
                    ev.latitud_inicio,
                    ev.longitud_inicio,
                    ev.latitud_fin,
                    ev.longitud_fin,
                    j.id_usuario AS tecnico_id,
                    t.username AS tecnico_username,
                    TRIM(CONCAT(dp.nombres, ' ', dp.apellidos)) AS tecnico_nombre,
                    s.username AS supervisor_username,
                    TRIM(CONCAT(dps.nombres, ' ', dps.apellidos)) AS supervisor_nombre
                FROM Eventos_Jornada ev
                INNER JOIN Jornadas j ON j.id_jornada = ev.id_jornada
                INNER JOIN Usuarios t ON t.id_usuario = j.id_usuario
                INNER JOIN Datos_Personales dp
                    ON dp.id_datos_personales = t.id_datos_personales
                INNER JOIN Usuarios s
                    ON s.id_usuario = ev.id_supervisor_autoriza
                INNER JOIN Datos_Personales dps
                    ON dps.id_datos_personales = s.id_datos_personales";
    }

    /** @return array<string, mixed> */
    private function mapEvent(array $row): array
    {
        return [
            'id' => (int) $row['id_evento'],
            'journeyId' => (int) $row['id_jornada'],
            'technicianId' => (int) $row['tecnico_id'],
            'technicianUsername' => (string) $row['tecnico_username'],
            'technicianName' => (string) $row['tecnico_nombre'],
            'supervisorId' => (int) $row['id_supervisor_autoriza'],
            'supervisorUsername' => (string) $row['supervisor_username'],
            'supervisorName' => (string) $row['supervisor_nombre'],
            'type' => (string) $row['tipo_evento'],
            'description' => (string) $row['descripcion_mision'],
            'technicianComment' => $row['comentario_tecnico'],
            'supervisorMessage' => $row['mensaje_supervisor'],
            'supervisorMessageAt' => $this->dateAtom(
                $row['hora_mensaje_supervisor']
            ),
            'status' => (string) $row['estado_evento'],
            'assignedAt' => $this->dateAtom($row['hora_asignacion']),
            'startedAt' => $this->dateAtom($row['hora_inicio_trayecto']),
            'finishedAt' => $this->dateAtom($row['hora_fin_trayecto']),
            'startLatitude' => $this->decimalValue($row['latitud_inicio']),
            'startLongitude' => $this->decimalValue($row['longitud_inicio']),
            'endLatitude' => $this->decimalValue($row['latitud_fin']),
            'endLongitude' => $this->decimalValue($row['longitud_fin']),
        ];
    }

    /** @param array<string, mixed> $claims */
    private function requireRole(array $claims, string $requiredRole): PDO
    {
        $connection = $this->requireActiveUser($claims);
        try {
            $statement = $connection->prepare(
                'SELECT LOWER(r.nombre_rol)
                 FROM Usuarios u
                 INNER JOIN Roles r ON r.id_rol = u.id_rol
                 WHERE u.id_usuario = :id_usuario
                   AND u.estado_activo = 1
                 LIMIT 1'
            );
            $statement->execute([
                'id_usuario' => (int) $claims['sub'],
            ]);
            $role = strtolower(trim((string) $statement->fetchColumn()));
        } catch (Throwable $exception) {
            $this->databaseError(
                'event_role_authorization',
                'No fue posible validar los permisos.',
                $exception
            );
        }

        if ($role !== $requiredRole) {
            Response::error(
                403,
                'role_required',
                'No tiene permisos para realizar esta acción.'
            );
        }

        return $connection;
    }

    /** @param array<string, mixed> $claims */
    private function requireActiveUser(array $claims): PDO
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
                'SELECT id_usuario
                 FROM Usuarios
                 WHERE id_usuario = :id_usuario
                   AND estado_activo = 1
                 LIMIT 1'
            );
            $statement->execute(['id_usuario' => $userId]);
            if ($statement->fetchColumn() === false) {
                Response::error(
                    403,
                    'inactive_user',
                    'La cuenta está inactiva o ya no existe.'
                );
            }
        } catch (Throwable $exception) {
            $this->databaseError(
                'event_authorization',
                'No fue posible validar la sesión.',
                $exception
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
                'El cuerpo JSON no es válido.'
            );
        }

        if (!is_array($body)) {
            Response::error(
                400,
                'invalid_request',
                'La solicitud no es válida.'
            );
        }

        return $body;
    }

    /** @param array<string, mixed> $body
     *  @return array{0: float, 1: float}
     */
    private function coordinates(array $body): array
    {
        $latitude = filter_var(
            $body['latitude'] ?? null,
            FILTER_VALIDATE_FLOAT
        );
        $longitude = filter_var(
            $body['longitude'] ?? null,
            FILTER_VALIDATE_FLOAT
        );

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
                'No fue posible obtener coordenadas GPS válidas.'
            );
        }

        return [(float) $latitude, (float) $longitude];
    }

    private function today(): string
    {
        return $this->now()->format('Y-m-d');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            'now',
            new DateTimeZone('America/El_Salvador')
        );
    }

    private function dateAtom(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return (new DateTimeImmutable(
            $value,
            new DateTimeZone('America/El_Salvador')
        ))->format(DATE_ATOM);
    }

    private function decimalValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function databaseError(
        string $operation,
        string $message,
        Throwable $exception
    ): never {
        error_log(sprintf(
            '[API Asistencia][%s] %s',
            $operation,
            $exception->getMessage()
        ));
        Response::error(503, 'database_unavailable', $message);
    }
}
