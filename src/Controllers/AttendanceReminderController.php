<?php
declare(strict_types=1);

namespace Nordictech\Api\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Nordictech\Api\Config\ApiConfig;
use Nordictech\Api\Data\Database;
use Nordictech\Api\Http\Response;
use Nordictech\Api\Services\PushNotificationService;
use PDO;
use RuntimeException;
use Throwable;

final class AttendanceReminderController
{
    private const TIMEZONE = 'America/El_Salvador';
    private const CHECK_IN_WINDOW_START = 7 * 60 + 55;
    private const CHECK_IN_WINDOW_END = 8 * 60;
    private const CHECK_OUT_WINDOW_START = 17 * 60;
    private const CHECK_OUT_WINDOW_END = 17 * 60 + 5;

    public function run(
        ?string $forcedType = null,
        ?string $testUsername = null
    ): never
    {
        $this->requireCronSecret();

        $timezone = new DateTimeZone(self::TIMEZONE);
        $now = new DateTimeImmutable('now', $timezone);
        $testMode = $forcedType !== null || $testUsername !== null;

        if ($testMode) {
            $forcedType = strtoupper(trim((string) $forcedType));
            $testUsername = trim((string) $testUsername);
            if (!in_array($forcedType, ['CHECK_IN', 'CHECK_OUT'], true)
                || $testUsername === '') {
                Response::error(
                    422,
                    'invalid_reminder_test',
                    'La prueba requiere tipo CHECK_IN o CHECK_OUT y un usuario.'
                );
            }
        }

        $reminderType = $testMode
            ? $forcedType
            : $this->reminderType($now);

        if ($reminderType === null) {
            Response::json([
                'message' => 'No hay recordatorios programados para este momento.',
                'localTime' => $now->format(DATE_ATOM),
                'processedUsers' => 0,
                'sentMessages' => 0,
            ]);
        }

        try {
            $connection = Database::connection();
            $date = $now->format('Y-m-d');

            if (!$testMode
                && $this->isNonWorkingDay($connection, $date)) {
                Response::json([
                    'message' =>
                        'Hoy es un día sin marcación; no se enviaron recordatorios.',
                    'localTime' => $now->format(DATE_ATOM),
                    'processedUsers' => 0,
                    'sentMessages' => 0,
                ]);
            }

            $userIds = $testMode
                ? [$this->testUserId($connection, $testUsername)]
                : $this->pendingUserIds(
                    $connection,
                    $reminderType,
                    $date
                );

            $processedUsers = 0;
            $sentMessages = 0;
            $skippedAlreadyProcessed = 0;
            $deliveryResults = [];

            foreach ($userIds as $userId) {
                if (!$testMode && !$this->claimReminder(
                    $connection,
                    $userId,
                    $date,
                    $reminderType
                )) {
                    $skippedAlreadyProcessed++;
                    continue;
                }

                [$title, $body, $route] = $this->message($reminderType);
                $sent = PushNotificationService::sendToUser(
                    $userId,
                    $title,
                    $body,
                    [
                        'type' => 'attendance_reminder',
                        'reminderType' => $reminderType,
                        'route' => $route,
                    ]
                );

                if (!$testMode) {
                    $this->finishReminder(
                        $connection,
                        $userId,
                        $date,
                        $reminderType,
                        $sent
                    );
                }

                $processedUsers++;
                $sentMessages += $sent;
                $deliveryResults[] = [
                    'userId' => $userId,
                    'sentMessages' => $sent,
                    'errorCode' =>
                        PushNotificationService::lastErrorCode(),
                    'errorMessage' =>
                        PushNotificationService::lastErrorMessage(),
                ];
            }
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][attendance_reminders] '
                . $exception->getMessage()
            );
            Response::error(
                503,
                'attendance_reminders_unavailable',
                'No fue posible procesar los recordatorios de asistencia.'
            );
        }

        Response::json([
            'message' => 'Recordatorios de asistencia procesados.',
            'localTime' => $now->format(DATE_ATOM),
            'reminderType' => $reminderType,
            'testMode' => $testMode,
            'testUsername' => $testMode ? $testUsername : null,
            'pendingUsers' => count($userIds),
            'processedUsers' => $processedUsers,
            'sentMessages' => $sentMessages,
            'skippedAlreadyProcessed' => $skippedAlreadyProcessed,
            'deliveryResults' => $deliveryResults,
        ]);
    }

    private function requireCronSecret(): void
    {
        try {
            $expected = ApiConfig::cronSecret();
        } catch (RuntimeException $exception) {
            error_log(
                '[API Asistencia][attendance_reminders] '
                . $exception->getMessage()
            );
            Response::error(
                503,
                'cron_not_configured',
                'El proceso programado no está configurado.'
            );
        }

        $provided = trim(
            (string) ($_SERVER['HTTP_X_CRON_SECRET'] ?? '')
        );

        if ($provided === '' || !hash_equals($expected, $provided)) {
            Response::error(
                401,
                'invalid_cron_secret',
                'La autorización del proceso programado no es válida.'
            );
        }
    }

    private function reminderType(DateTimeImmutable $now): ?string
    {
        $minutes = ((int) $now->format('H')) * 60
            + (int) $now->format('i');

        if ($minutes >= self::CHECK_IN_WINDOW_START
            && $minutes < self::CHECK_IN_WINDOW_END) {
            return 'CHECK_IN';
        }

        if ($minutes >= self::CHECK_OUT_WINDOW_START
            && $minutes < self::CHECK_OUT_WINDOW_END) {
            return 'CHECK_OUT';
        }

        return null;
    }

    private function isNonWorkingDay(
        PDO $connection,
        string $date
    ): bool {
        $statement = $connection->prepare(
            'SELECT 1
             FROM Dias_No_Laborales
             WHERE fecha = :fecha
             LIMIT 1'
        );
        $statement->execute(['fecha' => $date]);
        return $statement->fetchColumn() !== false;
    }

    private function testUserId(
        PDO $connection,
        string $username
    ): int {
        $statement = $connection->prepare(
            "SELECT u.id_usuario
             FROM Usuarios u
             INNER JOIN Roles r ON r.id_rol = u.id_rol
             WHERE u.username = :username
               AND u.estado_activo = 1
               AND LOWER(r.nombre_rol) IN ('tecnico', 'técnico')
             LIMIT 1"
        );
        $statement->execute(['username' => $username]);
        $userId = $statement->fetchColumn();

        if ($userId === false) {
            Response::error(
                404,
                'test_technician_not_found',
                'No existe un técnico activo con ese usuario.'
            );
        }

        return (int) $userId;
    }

    /**
     * @return list<int>
     */
    private function pendingUserIds(
        PDO $connection,
        string $reminderType,
        string $date
    ): array {
        if ($reminderType === 'CHECK_IN') {
            $sql = "
                SELECT DISTINCT u.id_usuario
                FROM Usuarios u
                INNER JOIN Roles r ON r.id_rol = u.id_rol
                INNER JOIN Dispositivos_Notificacion d
                    ON d.id_usuario = u.id_usuario
                   AND d.estado_activo = 1
                LEFT JOIN Jornadas j
                    ON j.id_usuario = u.id_usuario
                   AND j.fecha_jornada = :fecha_jornada
                LEFT JOIN Asistencia_Entrada e
                    ON e.id_jornada = j.id_jornada
                WHERE u.estado_activo = 1
                  AND LOWER(r.nombre_rol) IN ('tecnico', 'técnico')
                  AND e.id_entrada IS NULL
            ";
        } else {
            $sql = "
                SELECT DISTINCT u.id_usuario
                FROM Usuarios u
                INNER JOIN Roles r ON r.id_rol = u.id_rol
                INNER JOIN Dispositivos_Notificacion d
                    ON d.id_usuario = u.id_usuario
                   AND d.estado_activo = 1
                INNER JOIN Jornadas j
                    ON j.id_usuario = u.id_usuario
                   AND j.fecha_jornada = :fecha_jornada
                INNER JOIN Asistencia_Entrada e
                    ON e.id_jornada = j.id_jornada
                LEFT JOIN Asistencia_Salida s
                    ON s.id_jornada = j.id_jornada
                WHERE u.estado_activo = 1
                  AND LOWER(r.nombre_rol) IN ('tecnico', 'técnico')
                  AND s.id_salida IS NULL
            ";
        }

        $statement = $connection->prepare($sql);
        $statement->execute(['fecha_jornada' => $date]);

        return array_map(
            static fn (mixed $value): int => (int) $value,
            $statement->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    private function claimReminder(
        PDO $connection,
        int $userId,
        string $date,
        string $reminderType
    ): bool {
        $statement = $connection->prepare(
            'INSERT IGNORE INTO Recordatorios_Asistencia (
                id_usuario,
                fecha_recordatorio,
                tipo_recordatorio,
                fecha_proceso,
                envios_exitosos
             ) VALUES (
                :id_usuario,
                :fecha_recordatorio,
                :tipo_recordatorio,
                CURRENT_TIMESTAMP,
                0
             )'
        );
        $statement->execute([
            'id_usuario' => $userId,
            'fecha_recordatorio' => $date,
            'tipo_recordatorio' => $reminderType,
        ]);

        if ($statement->rowCount() === 1) {
            return true;
        }

        $retryStatement = $connection->prepare(
            'SELECT envios_exitosos
             FROM Recordatorios_Asistencia
             WHERE id_usuario = :id_usuario
               AND fecha_recordatorio = :fecha_recordatorio
               AND tipo_recordatorio = :tipo_recordatorio
             LIMIT 1'
        );
        $retryStatement->execute([
            'id_usuario' => $userId,
            'fecha_recordatorio' => $date,
            'tipo_recordatorio' => $reminderType,
        ]);
        $successfulDeliveries = $retryStatement->fetchColumn();

        // Si el intento anterior no entregó ningún mensaje, el siguiente
        // minuto de la ventana vuelve a intentarlo.
        return $successfulDeliveries !== false
            && (int) $successfulDeliveries === 0;
    }

    private function finishReminder(
        PDO $connection,
        int $userId,
        string $date,
        string $reminderType,
        int $sentMessages
    ): void {
        $statement = $connection->prepare(
            'UPDATE Recordatorios_Asistencia
             SET envios_exitosos = :envios_exitosos,
                 fecha_proceso = CURRENT_TIMESTAMP
             WHERE id_usuario = :id_usuario
               AND fecha_recordatorio = :fecha_recordatorio
               AND tipo_recordatorio = :tipo_recordatorio'
        );
        $statement->execute([
            'envios_exitosos' => $sentMessages,
            'id_usuario' => $userId,
            'fecha_recordatorio' => $date,
            'tipo_recordatorio' => $reminderType,
        ]);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function message(string $reminderType): array
    {
        if ($reminderType === 'CHECK_IN') {
            return [
                'Recordatorio de entrada',
                'Faltan 5 minutos para las 8:00 a. m. Registra tu entrada para evitar que se marque como tardía.',
                '/marcar_entrada',
            ];
        }

        return [
            'Recordatorio de salida',
            'Son las 5:00 p. m. y tu salida aún no está registrada. Recuerda marcarla antes de finalizar tu jornada.',
            '/marcar_salida',
        ];
    }
}
