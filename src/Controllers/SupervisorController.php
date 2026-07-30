<?php
declare(strict_types=1);

namespace Nordictech\Api\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Nordictech\Api\Data\Database;
use Nordictech\Api\Http\Response;
use PDO;
use Throwable;

final class SupervisorController
{
    private const LATE_CHECK_IN_TIME = '08:00:00';
    private const EARLY_CHECK_OUT_TIME = '17:00:00';

    /** @param array<string, mixed> $claims */
    public function dashboard(array $claims): never
    {
        $connection = $this->requireSupervisor($claims);
        $supervisorId = (int) ($claims['sub'] ?? 0);
        $timezone = new DateTimeZone('America/El_Salvador');
        $date = $this->requestedDate($timezone);

        try {
            $statement = $connection->prepare(
                "SELECT
                    t.id_usuario AS tecnico_id,
                    t.username AS tecnico_username,
                    t.email AS tecnico_email,
                    dp.nombres,
                    dp.apellidos,
                    j.id_jornada,
                    e.id_entrada,
                    e.fecha_hora_entrada,
                    e.comentario_atraso,
                    ue.nombre_lugar AS ubicacion_entrada,
                    s.id_salida,
                    s.fecha_hora_salida,
                    s.comentario_retiro_anticipado,
                    us.nombre_lugar AS ubicacion_salida
                 FROM Asignacion_Supervisores a
                 INNER JOIN Usuarios t ON t.id_usuario = a.id_tecnico
                 INNER JOIN Roles r ON r.id_rol = t.id_rol
                 INNER JOIN Datos_Personales dp
                    ON dp.id_datos_personales = t.id_datos_personales
                 LEFT JOIN Jornadas j
                    ON j.id_usuario = t.id_usuario
                   AND j.fecha_jornada = :fecha_jornada
                 LEFT JOIN Asistencia_Entrada e
                    ON e.id_jornada = j.id_jornada
                 LEFT JOIN Ubicaciones ue
                    ON ue.id_ubicacion = e.id_ubicacion
                 LEFT JOIN Asistencia_Salida s
                    ON s.id_jornada = j.id_jornada
                 LEFT JOIN Ubicaciones us
                    ON us.id_ubicacion = s.id_ubicacion
                 WHERE a.id_supervisor = :id_supervisor
                   AND a.estado_activo = 1
                   AND t.estado_activo = 1
                   AND LOWER(r.nombre_rol) IN ('tecnico', 'técnico')
                 ORDER BY dp.nombres, dp.apellidos"
            );
            $statement->execute([
                'fecha_jornada' => $date,
                'id_supervisor' => $supervisorId,
            ]);
            $rows = $statement->fetchAll();
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][supervisor_dashboard] '
                . $exception->getMessage()
            );
            Response::error(
                503,
                'supervisor_dashboard_unavailable',
                'No fue posible cargar el panel del supervisor.'
            );
        }

        $technicians = [];
        $checkIns = 0;
        $lateCheckIns = 0;
        $checkOuts = 0;
        $earlyCheckOuts = 0;

        foreach ($rows as $row) {
            $checkInDate = $this->dateValue(
                $row['fecha_hora_entrada'],
                $timezone
            );
            $checkOutDate = $this->dateValue(
                $row['fecha_hora_salida'],
                $timezone
            );
            $isLate = $checkInDate !== null
                && $checkInDate->format('H:i:s')
                    > self::LATE_CHECK_IN_TIME;
            $isEarly = $checkOutDate !== null
                && $checkOutDate->format('H:i:s')
                    < self::EARLY_CHECK_OUT_TIME;

            if ($checkInDate !== null) {
                $checkIns++;
                if ($isLate) {
                    $lateCheckIns++;
                }
            }

            if ($checkOutDate !== null) {
                $checkOuts++;
                if ($isEarly) {
                    $earlyCheckOuts++;
                }
            }

            $technicians[] = [
                'id' => (int) $row['tecnico_id'],
                'username' => (string) $row['tecnico_username'],
                'email' => $row['tecnico_email'],
                'fullName' => trim(
                    (string) $row['nombres']
                    . ' '
                    . (string) $row['apellidos']
                ),
                'checkIn' => [
                    'registered' => $checkInDate !== null,
                    'registeredAt' => $checkInDate?->format(DATE_ATOM),
                    'late' => $isLate,
                    'locationName' => $row['ubicacion_entrada'],
                    'comment' => $row['comentario_atraso'],
                ],
                'checkOut' => [
                    'registered' => $checkOutDate !== null,
                    'registeredAt' => $checkOutDate?->format(DATE_ATOM),
                    'early' => $isEarly,
                    'locationName' => $row['ubicacion_salida'],
                    'comment' => $row['comentario_retiro_anticipado'],
                ],
            ];
        }

        $assignedTechnicians = count($technicians);

        Response::json([
            'date' => $date,
            'schedule' => [
                'checkInLimit' => self::LATE_CHECK_IN_TIME,
                'checkOutLimit' => self::EARLY_CHECK_OUT_TIME,
            ],
            'metrics' => [
                'assignedTechnicians' => $assignedTechnicians,
                'checkIns' => $checkIns,
                'missingCheckIns' =>
                    max(0, $assignedTechnicians - $checkIns),
                'lateCheckIns' => $lateCheckIns,
                'checkOuts' => $checkOuts,
                'missingCheckOuts' =>
                    max(0, $assignedTechnicians - $checkOuts),
                'earlyCheckOuts' => $earlyCheckOuts,
            ],
            'technicians' => $technicians,
        ]);
    }

    /** @param array<string, mixed> $claims */
    public function report(array $claims): never
    {
        $connection = $this->requireSupervisor($claims);
        $supervisorId = (int) ($claims['sub'] ?? 0);
        $timezone = new DateTimeZone('America/El_Salvador');
        $days = $this->requestedReportDays();
        $selectedTechnicianId = $this->requestedReportTechnicianId();
        $endDate = new DateTimeImmutable('today', $timezone);
        $startDate = $endDate->modify(
            sprintf('-%d days', $days - 1)
        );

        try {
            $availableStatement = $connection->prepare(
                "SELECT
                    t.id_usuario AS tecnico_id,
                    t.username AS tecnico_username,
                    TRIM(CONCAT(dp.nombres, ' ', dp.apellidos))
                        AS tecnico_nombre
                 FROM Asignacion_Supervisores a
                 INNER JOIN Usuarios t ON t.id_usuario = a.id_tecnico
                 INNER JOIN Roles r ON r.id_rol = t.id_rol
                 INNER JOIN Datos_Personales dp
                    ON dp.id_datos_personales = t.id_datos_personales
                 WHERE a.id_supervisor = :id_supervisor
                   AND a.estado_activo = 1
                   AND t.estado_activo = 1
                   AND LOWER(r.nombre_rol) IN ('tecnico', 'técnico')
                 ORDER BY dp.nombres, dp.apellidos"
            );
            $availableStatement->execute([
                'id_supervisor' => $supervisorId,
            ]);
            $availableRows = $availableStatement->fetchAll();

            $availableTechnicians = array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['tecnico_id'],
                    'username' => (string) $row['tecnico_username'],
                    'fullName' => (string) $row['tecnico_nombre'],
                ],
                $availableRows
            );

            if ($selectedTechnicianId > 0) {
                $isAssigned = false;
                foreach ($availableTechnicians as $availableTechnician) {
                    if ($availableTechnician['id']
                        === $selectedTechnicianId) {
                        $isAssigned = true;
                        break;
                    }
                }

                if (!$isAssigned) {
                    Response::error(
                        422,
                        'invalid_report_technician',
                        'El técnico seleccionado no está asignado a este supervisor.'
                    );
                }
            }

            $statement = $connection->prepare(
                "SELECT
                    t.id_usuario AS tecnico_id,
                    t.username AS tecnico_username,
                    t.email AS tecnico_email,
                    dp.nombres,
                    dp.apellidos,
                    j.fecha_jornada,
                    e.fecha_hora_entrada,
                    e.comentario_atraso,
                    ue.nombre_lugar AS ubicacion_entrada,
                    s.fecha_hora_salida,
                    s.comentario_retiro_anticipado,
                    us.nombre_lugar AS ubicacion_salida
                 FROM Asignacion_Supervisores a
                 INNER JOIN Usuarios t ON t.id_usuario = a.id_tecnico
                 INNER JOIN Roles r ON r.id_rol = t.id_rol
                 INNER JOIN Datos_Personales dp
                    ON dp.id_datos_personales = t.id_datos_personales
                 LEFT JOIN Jornadas j
                    ON j.id_usuario = t.id_usuario
                   AND j.fecha_jornada BETWEEN :start_date AND :end_date
                 LEFT JOIN Asistencia_Entrada e
                    ON e.id_jornada = j.id_jornada
                 LEFT JOIN Ubicaciones ue
                    ON ue.id_ubicacion = e.id_ubicacion
                 LEFT JOIN Asistencia_Salida s
                    ON s.id_jornada = j.id_jornada
                 LEFT JOIN Ubicaciones us
                    ON us.id_ubicacion = s.id_ubicacion
                 WHERE a.id_supervisor = :id_supervisor
                   AND a.estado_activo = 1
                   AND t.estado_activo = 1
                   AND LOWER(r.nombre_rol) IN ('tecnico', 'técnico')
                   AND (
                        :selected_technician = 0
                        OR t.id_usuario = :selected_technician_filter
                   )
                 ORDER BY
                    dp.nombres,
                    dp.apellidos,
                    j.fecha_jornada DESC"
            );
            $statement->execute([
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'id_supervisor' => $supervisorId,
                'selected_technician' => $selectedTechnicianId,
                'selected_technician_filter' => $selectedTechnicianId,
            ]);
            $rows = $statement->fetchAll();

            $missionStatement = $connection->prepare(
                "SELECT
                    t.id_usuario AS tecnico_id,
                    j.fecha_jornada,
                    ev.id_evento,
                    ev.tipo_evento,
                    ev.descripcion_mision,
                    ev.comentario_tecnico,
                    ev.mensaje_supervisor,
                    ev.estado_evento,
                    ev.hora_asignacion,
                    ev.hora_inicio_trayecto,
                    ev.hora_fin_trayecto
                 FROM Eventos_Jornada ev
                 INNER JOIN Jornadas j
                    ON j.id_jornada = ev.id_jornada
                 INNER JOIN Usuarios t
                    ON t.id_usuario = j.id_usuario
                 INNER JOIN Asignacion_Supervisores a
                    ON a.id_tecnico = t.id_usuario
                   AND a.id_supervisor = :mission_supervisor
                   AND a.estado_activo = 1
                 WHERE ev.id_supervisor_autoriza = :event_supervisor
                   AND j.fecha_jornada
                        BETWEEN :mission_start_date AND :mission_end_date
                   AND (
                        :mission_technician = 0
                        OR t.id_usuario = :mission_technician_filter
                   )
                 ORDER BY
                    t.id_usuario,
                    j.fecha_jornada DESC,
                    ev.hora_asignacion"
            );
            $missionStatement->execute([
                'mission_supervisor' => $supervisorId,
                'event_supervisor' => $supervisorId,
                'mission_start_date' => $startDate->format('Y-m-d'),
                'mission_end_date' => $endDate->format('Y-m-d'),
                'mission_technician' => $selectedTechnicianId,
                'mission_technician_filter' => $selectedTechnicianId,
            ]);
            $missionRows = $missionStatement->fetchAll();
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][supervisor_report] '
                . $exception->getMessage()
            );
            Response::error(
                503,
                'supervisor_report_unavailable',
                'No fue posible generar el reporte del supervisor.'
            );
        }

        $technicians = [];
        $recordIndexes = [];
        $daysWithActivity = 0;
        $completeDays = 0;
        $checkIns = 0;
        $lateCheckIns = 0;
        $checkOuts = 0;
        $earlyCheckOuts = 0;

        foreach ($rows as $row) {
            $technicianId = (int) $row['tecnico_id'];
            if (!isset($technicians[$technicianId])) {
                $technicians[$technicianId] = [
                    'id' => $technicianId,
                    'username' => (string) $row['tecnico_username'],
                    'email' => $row['tecnico_email'],
                    'fullName' => trim(
                        (string) $row['nombres']
                        . ' '
                        . (string) $row['apellidos']
                    ),
                    'totalOvertimeMinutes' => 0,
                    'records' => [],
                ];
            }

            $recordDate = $row['fecha_jornada'];
            if (!is_string($recordDate) || trim($recordDate) === '') {
                continue;
            }

            $checkInDate = $this->dateValue(
                $row['fecha_hora_entrada'],
                $timezone
            );
            $checkOutDate = $this->dateValue(
                $row['fecha_hora_salida'],
                $timezone
            );
            $isLate = $checkInDate !== null
                && $checkInDate->format('H:i:s')
                    > self::LATE_CHECK_IN_TIME;
            $isEarly = $checkOutDate !== null
                && $checkOutDate->format('H:i:s')
                    < self::EARLY_CHECK_OUT_TIME;
            $overtimeMinutes = $this->overtimeMinutes(
                $checkInDate,
                $checkOutDate,
                $recordDate,
                $timezone
            );
            $technicians[$technicianId]['totalOvertimeMinutes'] +=
                $overtimeMinutes;

            if ($checkInDate !== null || $checkOutDate !== null) {
                $daysWithActivity++;
            }

            if ($checkInDate !== null && $checkOutDate !== null) {
                $completeDays++;
            }

            if ($checkInDate !== null) {
                $checkIns++;
                if ($isLate) {
                    $lateCheckIns++;
                }
            }

            if ($checkOutDate !== null) {
                $checkOuts++;
                if ($isEarly) {
                    $earlyCheckOuts++;
                }
            }

            $recordIndex = count(
                $technicians[$technicianId]['records']
            );
            $technicians[$technicianId]['records'][] = [
                'date' => $recordDate,
                'overtimeMinutes' => $overtimeMinutes,
                'checkIn' => [
                    'registered' => $checkInDate !== null,
                    'registeredAt' => $checkInDate?->format(DATE_ATOM),
                    'late' => $isLate,
                    'locationName' => $row['ubicacion_entrada'],
                    'comment' => $row['comentario_atraso'],
                ],
                'checkOut' => [
                    'registered' => $checkOutDate !== null,
                    'registeredAt' => $checkOutDate?->format(DATE_ATOM),
                    'early' => $isEarly,
                    'locationName' => $row['ubicacion_salida'],
                    'comment' => $row['comentario_retiro_anticipado'],
                ],
                'missions' => [],
            ];
            $recordIndexes[$technicianId][$recordDate] = $recordIndex;
        }

        foreach ($missionRows as $missionRow) {
            $technicianId = (int) $missionRow['tecnico_id'];
            $recordDate = (string) $missionRow['fecha_jornada'];
            if (!isset($technicians[$technicianId])) {
                continue;
            }

            if (!isset($recordIndexes[$technicianId][$recordDate])) {
                $recordIndex = count(
                    $technicians[$technicianId]['records']
                );
                $technicians[$technicianId]['records'][] = [
                    'date' => $recordDate,
                    'overtimeMinutes' => 0,
                    'checkIn' => [
                        'registered' => false,
                        'registeredAt' => null,
                        'late' => false,
                        'locationName' => null,
                        'comment' => null,
                    ],
                    'checkOut' => [
                        'registered' => false,
                        'registeredAt' => null,
                        'early' => false,
                        'locationName' => null,
                        'comment' => null,
                    ],
                    'missions' => [],
                ];
                $recordIndexes[$technicianId][$recordDate] =
                    $recordIndex;
            }

            $recordIndex =
                $recordIndexes[$technicianId][$recordDate];
            $assignedAt = $this->dateValue(
                $missionRow['hora_asignacion'],
                $timezone
            );
            $startedAt = $this->dateValue(
                $missionRow['hora_inicio_trayecto'],
                $timezone
            );
            $finishedAt = $this->dateValue(
                $missionRow['hora_fin_trayecto'],
                $timezone
            );

            $technicians[$technicianId]['records'][$recordIndex]
                ['missions'][] = [
                    'id' => (int) $missionRow['id_evento'],
                    'type' => (string) $missionRow['tipo_evento'],
                    'description' =>
                        (string) $missionRow['descripcion_mision'],
                    'status' => (string) $missionRow['estado_evento'],
                    'assignedAt' => $assignedAt?->format(DATE_ATOM),
                    'startedAt' => $startedAt?->format(DATE_ATOM),
                    'finishedAt' => $finishedAt?->format(DATE_ATOM),
                    'technicianComment' =>
                        $missionRow['comentario_tecnico'],
                    'supervisorMessage' =>
                        $missionRow['mensaje_supervisor'],
                ];
        }

        Response::json([
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
            'days' => $days,
            'selectedTechnicianId' => $selectedTechnicianId > 0
                ? $selectedTechnicianId
                : null,
            'generatedAt' => (new DateTimeImmutable('now', $timezone))
                ->format(DATE_ATOM),
            'metrics' => [
                'assignedTechnicians' => count($technicians),
                'daysWithActivity' => $daysWithActivity,
                'completeDays' => $completeDays,
                'checkIns' => $checkIns,
                'lateCheckIns' => $lateCheckIns,
                'checkOuts' => $checkOuts,
                'earlyCheckOuts' => $earlyCheckOuts,
            ],
            'availableTechnicians' => $availableTechnicians,
            'technicians' => array_values($technicians),
        ]);
    }

    /** @param array<string, mixed> $claims */
    private function requireSupervisor(array $claims): PDO
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
                "SELECT r.nombre_rol
                 FROM Usuarios u
                 INNER JOIN Roles r ON r.id_rol = u.id_rol
                 WHERE u.id_usuario = :id_usuario
                   AND u.estado_activo = 1
                 LIMIT 1"
            );
            $statement->execute(['id_usuario' => $userId]);
            $role = $statement->fetchColumn();
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][supervisor_authorization] '
                . $exception->getMessage()
            );
            Response::error(
                503,
                'authorization_unavailable',
                'No fue posible validar los permisos del supervisor.'
            );
        }

        if (!is_string($role)
            || strtolower($role) !== 'supervisor') {
            Response::error(
                403,
                'supervisor_required',
                'Esta función requiere el rol de supervisor.'
            );
        }

        return $connection;
    }

    private function requestedReportDays(): int
    {
        $requestedDays = trim((string) ($_GET['days'] ?? '15'));
        if ($requestedDays === ''
            || !ctype_digit($requestedDays)) {
            Response::error(
                422,
                'invalid_report_days',
                'La cantidad de días debe ser un número entero.'
            );
        }

        $days = (int) $requestedDays;
        if ($days < 1 || $days > 365) {
            Response::error(
                422,
                'invalid_report_days',
                'La cantidad de días debe estar entre 1 y 365.'
            );
        }

        return $days;
    }

    private function requestedReportTechnicianId(): int
    {
        $requestedId = trim(
            (string) ($_GET['technicianId'] ?? '0')
        );
        if ($requestedId === '' || $requestedId === '0') {
            return 0;
        }

        if (!ctype_digit($requestedId) || (int) $requestedId <= 0) {
            Response::error(
                422,
                'invalid_report_technician',
                'El técnico seleccionado no es válido.'
            );
        }

        return (int) $requestedId;
    }

    private function requestedDate(DateTimeZone $timezone): string
    {
        $requestedDate = trim((string) ($_GET['date'] ?? ''));
        if ($requestedDate === '') {
            return (new DateTimeImmutable('now', $timezone))
                ->format('Y-m-d');
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $requestedDate,
            $timezone
        );
        $errors = DateTimeImmutable::getLastErrors();
        $invalidDate = $date === false
            || ($errors !== false
                && ($errors['warning_count'] > 0
                    || $errors['error_count'] > 0));

        if ($invalidDate || $date->format('Y-m-d') !== $requestedDate) {
            Response::error(
                422,
                'invalid_date',
                'La fecha solicitada no es válida.'
            );
        }

        return $requestedDate;
    }

    private function overtimeMinutes(
        ?DateTimeImmutable $checkIn,
        ?DateTimeImmutable $checkOut,
        string $recordDate,
        DateTimeZone $timezone
    ): int {
        $scheduledStart = new DateTimeImmutable(
            $recordDate . ' ' . self::LATE_CHECK_IN_TIME,
            $timezone
        );
        $scheduledEnd = new DateTimeImmutable(
            $recordDate . ' ' . self::EARLY_CHECK_OUT_TIME,
            $timezone
        );
        $minutes = 0;

        if ($checkIn !== null && $checkIn < $scheduledStart) {
            $minutes += intdiv(
                $scheduledStart->getTimestamp()
                    - $checkIn->getTimestamp(),
                60
            );
        }

        if ($checkOut !== null && $checkOut > $scheduledEnd) {
            $minutes += intdiv(
                $checkOut->getTimestamp()
                    - $scheduledEnd->getTimestamp(),
                60
            );
        }

        return max(0, $minutes);
    }

    private function dateValue(
        mixed $value,
        DateTimeZone $timezone
    ): ?DateTimeImmutable {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return new DateTimeImmutable($value, $timezone);
    }
}
