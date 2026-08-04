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

final class AdminController
{
    /** @param array<string, mixed> $claims */
    public function dashboard(array $claims): never
    {
        $connection = $this->requireAdministrator($claims);
        $today = (new DateTimeImmutable(
            'now',
            new DateTimeZone('America/El_Salvador')
        ))->format('Y-m-d');

        try {
            $metricsStatement = $connection->prepare(
                "SELECT
                    COUNT(*) AS total_usuarios,
                    COALESCE(SUM(u.estado_activo = 1), 0) AS usuarios_activos,
                    COALESCE(SUM(u.estado_activo <> 1 OR u.estado_activo IS NULL), 0)
                        AS usuarios_inactivos,
                    COALESCE(SUM(
                        u.estado_activo = 1
                        AND LOWER(r.nombre_rol) = 'supervisor'
                    ), 0) AS supervisores,
                    COALESCE(SUM(
                        u.estado_activo = 1
                        AND LOWER(r.nombre_rol) IN ('tecnico', 'técnico')
                    ), 0) AS tecnicos
                 FROM Usuarios u
                 INNER JOIN Roles r ON r.id_rol = u.id_rol"
            );
            $metricsStatement->execute();
            $metrics = $metricsStatement->fetch();

            $assignedStatement = $connection->query(
                "SELECT COUNT(DISTINCT a.id_tecnico)
                 FROM Asignacion_Supervisores a
                 INNER JOIN Usuarios t ON t.id_usuario = a.id_tecnico
                 INNER JOIN Roles r ON r.id_rol = t.id_rol
                 WHERE a.estado_activo = 1
                   AND t.estado_activo = 1
                   AND LOWER(r.nombre_rol) IN ('tecnico', 'técnico')"
            );
            $assignedTechnicians = (int) $assignedStatement->fetchColumn();

            $attendanceStatement = $connection->prepare(
                'SELECT
                    (SELECT COUNT(*)
                     FROM Asistencia_Entrada e
                     INNER JOIN Jornadas j ON j.id_jornada = e.id_jornada
                     WHERE j.fecha_jornada = :fecha_entrada) AS entradas_hoy,
                    (SELECT COUNT(*)
                     FROM Asistencia_Salida s
                     INNER JOIN Jornadas j ON j.id_jornada = s.id_jornada
                     WHERE j.fecha_jornada = :fecha_salida) AS salidas_hoy'
            );
            $attendanceStatement->execute([
                'fecha_entrada' => $today,
                'fecha_salida' => $today,
            ]);
            $attendance = $attendanceStatement->fetch();

            $roles = array_map(
                static fn (array $role): array => [
                    'id' => (int) $role['id_rol'],
                    'name' => (string) $role['nombre_rol'],
                    'description' => $role['descripcion'],
                ],
                $connection->query(
                    'SELECT id_rol, nombre_rol, descripcion
                     FROM Roles
                     ORDER BY nombre_rol'
                )->fetchAll()
            );

            $userRows = $connection->query(
                'SELECT
                    u.id_usuario,
                    u.username,
                    u.email,
                    CAST(u.estado_activo AS UNSIGNED) AS estado_activo,
                    u.fecha_registro,
                    r.id_rol,
                    r.nombre_rol,
                    dp.nombres,
                    dp.apellidos,
                    a.id_supervisor,
                    su.username AS supervisor_username,
                    sdp.nombres AS supervisor_nombres,
                    sdp.apellidos AS supervisor_apellidos
                 FROM Usuarios u
                 INNER JOIN Roles r ON r.id_rol = u.id_rol
                 INNER JOIN Datos_Personales dp
                    ON dp.id_datos_personales = u.id_datos_personales
                 LEFT JOIN Asignacion_Supervisores a
                    ON a.id_tecnico = u.id_usuario
                   AND a.estado_activo = 1
                 LEFT JOIN Usuarios su ON su.id_usuario = a.id_supervisor
                 LEFT JOIN Datos_Personales sdp
                    ON sdp.id_datos_personales = su.id_datos_personales
                 ORDER BY u.estado_activo DESC, dp.nombres, dp.apellidos'
            )->fetchAll();

            $users = array_map(
                static function (array $user): array {
                    $supervisorName = null;
                    if ($user['id_supervisor'] !== null) {
                        $supervisorName = trim(
                            (string) $user['supervisor_nombres']
                            . ' '
                            . (string) $user['supervisor_apellidos']
                        );
                    }

                    return [
                        'id' => (int) $user['id_usuario'],
                        'username' => (string) $user['username'],
                        'email' => $user['email'],
                        'fullName' => trim(
                            (string) $user['nombres']
                            . ' '
                            . (string) $user['apellidos']
                        ),
                        'roleId' => (int) $user['id_rol'],
                        'role' => (string) $user['nombre_rol'],
                        'active' => (int) $user['estado_activo'] === 1,
                        'registeredAt' => $user['fecha_registro'],
                        'supervisorId' => $user['id_supervisor'] === null
                            ? null
                            : (int) $user['id_supervisor'],
                        'supervisorName' => $supervisorName,
                        'supervisorUsername' => $user['supervisor_username'],
                    ];
                },
                $userRows
            );

            $supervisors = array_values(array_map(
                static fn (array $user): array => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'fullName' => $user['fullName'],
                ],
                array_filter(
                    $users,
                    static fn (array $user): bool =>
                        $user['active']
                        && strtolower($user['role']) === 'supervisor'
                )
            ));

            $technicians = array_values(array_map(
                static fn (array $user): array => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'fullName' => $user['fullName'],
                    'supervisorId' => $user['supervisorId'],
                    'supervisorName' => $user['supervisorName'],
                ],
                array_filter(
                    $users,
                    static fn (array $user): bool =>
                        $user['active']
                        && in_array(
                            strtolower($user['role']),
                            ['tecnico', 'técnico'],
                            true
                        )
                )
            ));

            $locationRows = $connection->query(
                'SELECT
                    l.id_ubicacion,
                    l.nombre_lugar,
                    l.direccion,
                    l.ciudad,
                    l.latitud_esperada,
                    l.longitud_esperada,
                    CAST(l.estado_activo AS UNSIGNED) AS estado_activo,
                    (
                        SELECT COUNT(*)
                        FROM Asistencia_Entrada e
                        WHERE e.id_ubicacion = l.id_ubicacion
                    ) + (
                        SELECT COUNT(*)
                        FROM Asistencia_Salida s
                        WHERE s.id_ubicacion = l.id_ubicacion
                    ) AS cantidad_uso
                 FROM Ubicaciones l
                 ORDER BY l.estado_activo DESC, l.nombre_lugar'
            )->fetchAll();

            $locations = array_map(
                static fn (array $location): array => [
                    'id' => (int) $location['id_ubicacion'],
                    'name' => (string) $location['nombre_lugar'],
                    'address' => $location['direccion'],
                    'city' => $location['ciudad'],
                    'latitude' => (float) $location['latitud_esperada'],
                    'longitude' => (float) $location['longitud_esperada'],
                    'active' => (int) $location['estado_activo'] === 1,
                    'usageCount' => (int) $location['cantidad_uso'],
                ],
                $locationRows
            );

            $technicianCount = (int) ($metrics['tecnicos'] ?? 0);
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][admin_dashboard] ' . $exception->getMessage()
            );
            Response::error(
                503,
                'admin_dashboard_unavailable',
                'No fue posible cargar el panel administrativo.'
            );
        }

        Response::json([
            'metrics' => [
                'totalUsers' => (int) ($metrics['total_usuarios'] ?? 0),
                'activeUsers' => (int) ($metrics['usuarios_activos'] ?? 0),
                'inactiveUsers' => (int) ($metrics['usuarios_inactivos'] ?? 0),
                'supervisors' => (int) ($metrics['supervisores'] ?? 0),
                'technicians' => $technicianCount,
                'assignedTechnicians' => $assignedTechnicians,
                'unassignedTechnicians' =>
                    max(0, $technicianCount - $assignedTechnicians),
                'checkInsToday' => (int) ($attendance['entradas_hoy'] ?? 0),
                'checkOutsToday' => (int) ($attendance['salidas_hoy'] ?? 0),
            ],
            'roles' => $roles,
            'users' => $users,
            'supervisors' => $supervisors,
            'technicians' => $technicians,
            'locations' => $locations,
        ]);
    }

    /** @param array<string, mixed> $claims */
    public function updateUserRole(
        array $claims,
        int $userId
    ): never {
        $connection = $this->requireAdministrator($claims);
        $administratorId = (int) ($claims['sub'] ?? 0);
        $body = $this->requestBody();
        $roleId = filter_var(
            $body['roleId'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($userId <= 0 || $roleId === false) {
            Response::error(
                422,
                'invalid_role_assignment',
                'El usuario y el rol son obligatorios.'
            );
        }

        if ($userId === $administratorId) {
            Response::error(
                409,
                'cannot_change_own_role',
                'No puede cambiar su propio rol administrativo.'
            );
        }

        try {
            $roleStatement = $connection->prepare(
                'SELECT nombre_rol FROM Roles WHERE id_rol = :id_rol LIMIT 1'
            );
            $roleStatement->execute(['id_rol' => $roleId]);
            $role = $roleStatement->fetch();

            if (!is_array($role)) {
                Response::error(404, 'role_not_found', 'El rol no existe.');
            }

            $connection->beginTransaction();
            $userStatement = $connection->prepare(
                'UPDATE Usuarios
                 SET id_rol = :id_rol
                 WHERE id_usuario = :id_usuario'
            );
            $userStatement->execute([
                'id_rol' => $roleId,
                'id_usuario' => $userId,
            ]);

            if ($userStatement->rowCount() === 0) {
                $existsStatement = $connection->prepare(
                    'SELECT id_usuario
                     FROM Usuarios
                     WHERE id_usuario = :id_usuario
                     LIMIT 1'
                );
                $existsStatement->execute(['id_usuario' => $userId]);
                if ($existsStatement->fetch() === false) {
                    $connection->rollBack();
                    Response::error(
                        404,
                        'user_not_found',
                        'El usuario no existe.'
                    );
                }
            }

            $normalizedRole = strtolower((string) $role['nombre_rol']);
            if (!in_array(
                $normalizedRole,
                ['tecnico', 'técnico'],
                true
            )) {
                $deactivateTechnician = $connection->prepare(
                    'DELETE FROM Asignacion_Supervisores
                     WHERE id_tecnico = :id_usuario'
                );
                $deactivateTechnician->execute(['id_usuario' => $userId]);
            }

            if ($normalizedRole !== 'supervisor') {
                $deactivateSupervisor = $connection->prepare(
                    'DELETE FROM Asignacion_Supervisores
                     WHERE id_supervisor = :id_usuario'
                );
                $deactivateSupervisor->execute(['id_usuario' => $userId]);
            }

            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            error_log(
                '[API Asistencia][admin_role] ' . $exception->getMessage()
            );
            Response::error(
                500,
                'role_update_failed',
                'No fue posible actualizar el rol.'
            );
        }

        Response::json([
            'message' => 'Rol actualizado correctamente.',
            'userId' => $userId,
            'roleId' => $roleId,
        ]);
    }

    /** @param array<string, mixed> $claims */
    public function updateUserStatus(
        array $claims,
        int $userId
    ): never {
        $connection = $this->requireAdministrator($claims);
        $administratorId = (int) ($claims['sub'] ?? 0);
        $body = $this->requestBody();
        $active = filter_var(
            $body['active'] ?? null,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        if ($userId <= 0 || $active === null) {
            Response::error(
                422,
                'invalid_user_status',
                'Debe indicar un estado válido.'
            );
        }

        if ($userId === $administratorId && !$active) {
            Response::error(
                409,
                'cannot_deactivate_self',
                'No puede desactivar su propia cuenta.'
            );
        }

        try {
            $connection->beginTransaction();
            $activeLiteral = $active ? "b'1'" : "b'0'";
            $statement = $connection->prepare(
                "UPDATE Usuarios
                 SET estado_activo = {$activeLiteral}
                 WHERE id_usuario = :id_usuario"
            );
            $statement->execute([
                'id_usuario' => $userId,
            ]);

            if ($statement->rowCount() === 0) {
                $existsStatement = $connection->prepare(
                    'SELECT id_usuario
                     FROM Usuarios
                     WHERE id_usuario = :id_usuario
                     LIMIT 1'
                );
                $existsStatement->execute(['id_usuario' => $userId]);
                if ($existsStatement->fetch() === false) {
                    $connection->rollBack();
                    Response::error(
                        404,
                        'user_not_found',
                        'El usuario no existe.'
                    );
                }
            }

            if (!$active) {
                $assignmentStatement = $connection->prepare(
                    'DELETE FROM Asignacion_Supervisores
                     WHERE id_supervisor = :id_supervisor
                        OR id_tecnico = :id_tecnico'
                );
                $assignmentStatement->execute([
                    'id_supervisor' => $userId,
                    'id_tecnico' => $userId,
                ]);
            }

            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            error_log(
                '[API Asistencia][admin_status] ' . $exception->getMessage()
            );
            Response::error(
                500,
                'status_update_failed',
                'No fue posible actualizar el estado del usuario.'
            );
        }

        Response::json([
            'message' => 'Estado actualizado correctamente.',
            'userId' => $userId,
            'active' => $active,
        ]);
    }

    /** @param array<string, mixed> $claims */
    public function assignTechnicians(array $claims): never
    {
        $connection = $this->requireAdministrator($claims);
        $body = $this->requestBody();
        $supervisorId = filter_var(
            $body['supervisorId'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $technicianIds = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $value): int => (int) $value,
                is_array($body['technicianIds'] ?? null)
                    ? $body['technicianIds']
                    : []
            ),
            static fn (int $value): bool => $value > 0
        )));

        if ($supervisorId === false || $technicianIds === []) {
            Response::error(
                422,
                'invalid_supervisor_assignment',
                'Seleccione un supervisor y al menos un técnico.'
            );
        }

        try {
            $supervisorStatement = $connection->prepare(
                "SELECT u.id_usuario
                 FROM Usuarios u
                 INNER JOIN Roles r ON r.id_rol = u.id_rol
                 WHERE u.id_usuario = :id_usuario
                   AND u.estado_activo = 1
                   AND LOWER(r.nombre_rol) = 'supervisor'
                 LIMIT 1"
            );
            $supervisorStatement->execute(['id_usuario' => $supervisorId]);
            if ($supervisorStatement->fetch() === false) {
                Response::error(
                    422,
                    'invalid_supervisor',
                    'El usuario seleccionado no es un supervisor activo.'
                );
            }

            $placeholders = implode(
                ',',
                array_fill(0, count($technicianIds), '?')
            );
            $technicianStatement = $connection->prepare(
                "SELECT u.id_usuario
                 FROM Usuarios u
                 INNER JOIN Roles r ON r.id_rol = u.id_rol
                 WHERE u.id_usuario IN ({$placeholders})
                   AND u.estado_activo = 1
                   AND LOWER(r.nombre_rol) IN ('tecnico', 'técnico')"
            );
            $technicianStatement->execute($technicianIds);
            $validTechnicianIds = array_map(
                'intval',
                $technicianStatement->fetchAll(PDO::FETCH_COLUMN)
            );

            sort($validTechnicianIds);
            $expectedTechnicianIds = $technicianIds;
            sort($expectedTechnicianIds);
            if ($validTechnicianIds !== $expectedTechnicianIds) {
                Response::error(
                    422,
                    'invalid_technicians',
                    'Uno o más usuarios seleccionados no son técnicos activos.'
                );
            }

            $assignmentPlaceholders = implode(
                ',',
                array_fill(0, count($technicianIds), '?')
            );
            $existingAssignmentStatement = $connection->prepare(
                "SELECT id_tecnico
                 FROM Asignacion_Supervisores
                 WHERE id_tecnico IN ({$assignmentPlaceholders})
                   AND estado_activo = 1"
            );
            $existingAssignmentStatement->execute($technicianIds);
            if ($existingAssignmentStatement->fetch() !== false) {
                Response::error(
                    409,
                    'technician_already_assigned',
                    'Uno o más técnicos ya tienen un supervisor. Retire primero la asignación actual.'
                );
            }

            $connection->beginTransaction();
            $deleteInactiveStatement = $connection->prepare(
                "DELETE FROM Asignacion_Supervisores
                 WHERE id_tecnico IN ({$assignmentPlaceholders})"
            );
            $deleteInactiveStatement->execute($technicianIds);

            $assignmentStatement = $connection->prepare(
                'INSERT INTO Asignacion_Supervisores (
                    id_supervisor,
                    id_tecnico,
                    fecha_asignacion,
                    estado_activo
                 ) VALUES (
                    :id_supervisor,
                    :id_tecnico,
                    CURRENT_TIMESTAMP,
                    1
                 )'
            );

            foreach ($technicianIds as $technicianId) {
                $assignmentStatement->execute([
                    'id_supervisor' => $supervisorId,
                    'id_tecnico' => $technicianId,
                ]);
            }
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            error_log(
                '[API Asistencia][admin_assignments] '
                . $exception->getMessage()
            );
            Response::error(
                500,
                'assignment_failed',
                'No fue posible guardar las asignaciones.'
            );
        }

        Response::json([
            'message' => 'Técnicos asignados correctamente.',
            'supervisorId' => $supervisorId,
            'technicianIds' => $technicianIds,
        ]);
    }

    /** @param array<string, mixed> $claims */
    public function removeTechnicianAssignment(
        array $claims,
        int $technicianId
    ): never {
        $connection = $this->requireAdministrator($claims);

        try {
            $statement = $connection->prepare(
                'DELETE FROM Asignacion_Supervisores
                 WHERE id_tecnico = :id_tecnico'
            );
            $statement->execute(['id_tecnico' => $technicianId]);
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][admin_unassign] ' . $exception->getMessage()
            );
            Response::error(
                500,
                'unassignment_failed',
                'No fue posible retirar la asignación.'
            );
        }

        if ($statement->rowCount() === 0) {
            Response::error(
                404,
                'assignment_not_found',
                'El técnico no tiene una asignación activa.'
            );
        }

        Response::json([
            'message' => 'Asignación retirada correctamente.',
            'technicianId' => $technicianId,
        ]);
    }

    /** @param array<string, mixed> $claims */
    public function createLocation(array $claims): never
    {
        $connection = $this->requireAdministrator($claims);
        $location = $this->locationPayload($this->requestBody());
        $activeSql = $location['active'] ? "b'1'" : "b'0'";

        try {
            $statement = $connection->prepare(
                'INSERT INTO Ubicaciones (
                    nombre_lugar,
                    direccion,
                    ciudad,
                    latitud_esperada,
                    longitud_esperada,
                    estado_activo
                 ) VALUES (
                    :nombre_lugar,
                    :direccion,
                    :ciudad,
                    :latitud_esperada,
                    :longitud_esperada,
                    ' . $activeSql . '
                 )'
            );
            $statement->execute([
                'nombre_lugar' => $location['name'],
                'direccion' => $location['address'],
                'ciudad' => $location['city'],
                'latitud_esperada' => $location['latitude'],
                'longitud_esperada' => $location['longitude'],
            ]);
            $locationId = (int) $connection->lastInsertId();
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                Response::error(
                    409,
                    'location_name_exists',
                    'Ya existe una ubicación con ese nombre.'
                );
            }

            error_log(
                '[API Asistencia][admin_location_create] '
                . $exception->getMessage()
            );
            Response::error(
                503,
                'location_create_unavailable',
                'No fue posible crear la ubicación.'
            );
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][admin_location_create] '
                . $exception->getMessage()
            );
            Response::error(
                503,
                'location_create_unavailable',
                'No fue posible crear la ubicación.'
            );
        }

        Response::json([
            'message' => 'Ubicación creada correctamente.',
            'locationId' => $locationId,
        ], 201);
    }

    /** @param array<string, mixed> $claims */
    public function updateLocation(
        array $claims,
        int $locationId
    ): never {
        $connection = $this->requireAdministrator($claims);
        if ($locationId <= 0) {
            Response::error(
                422,
                'invalid_location',
                'La ubicación solicitada no es válida.'
            );
        }

        $location = $this->locationPayload($this->requestBody());
        $activeSql = $location['active'] ? "b'1'" : "b'0'";

        try {
            $existsStatement = $connection->prepare(
                'SELECT id_ubicacion
                 FROM Ubicaciones
                 WHERE id_ubicacion = :id_ubicacion
                 LIMIT 1'
            );
            $existsStatement->execute(['id_ubicacion' => $locationId]);
            if ($existsStatement->fetchColumn() === false) {
                Response::error(
                    404,
                    'location_not_found',
                    'La ubicación indicada no existe.'
                );
            }

            $statement = $connection->prepare(
                'UPDATE Ubicaciones
                 SET nombre_lugar = :nombre_lugar,
                     direccion = :direccion,
                     ciudad = :ciudad,
                     latitud_esperada = :latitud_esperada,
                     longitud_esperada = :longitud_esperada,
                     estado_activo = ' . $activeSql . '
                 WHERE id_ubicacion = :id_ubicacion'
            );
            $statement->execute([
                'nombre_lugar' => $location['name'],
                'direccion' => $location['address'],
                'ciudad' => $location['city'],
                'latitud_esperada' => $location['latitude'],
                'longitud_esperada' => $location['longitude'],
                'id_ubicacion' => $locationId,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                Response::error(
                    409,
                    'location_name_exists',
                    'Ya existe otra ubicación con ese nombre.'
                );
            }

            error_log(
                '[API Asistencia][admin_location_update] '
                . $exception->getMessage()
            );
            Response::error(
                503,
                'location_update_unavailable',
                'No fue posible actualizar la ubicación.'
            );
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][admin_location_update] '
                . $exception->getMessage()
            );
            Response::error(
                503,
                'location_update_unavailable',
                'No fue posible actualizar la ubicación.'
            );
        }

        Response::json([
            'message' => 'Ubicación actualizada correctamente.',
            'locationId' => $locationId,
        ]);
    }

    /** @param array<string, mixed> $claims */
    public function deleteLocation(
        array $claims,
        int $locationId
    ): never {
        $connection = $this->requireAdministrator($claims);
        if ($locationId <= 0) {
            Response::error(
                422,
                'invalid_location',
                'La ubicación solicitada no es válida.'
            );
        }

        try {
            $connection->beginTransaction();
            $statement = $connection->prepare(
                'SELECT
                    l.nombre_lugar,
                    (
                        SELECT COUNT(*)
                        FROM Asistencia_Entrada e
                        WHERE e.id_ubicacion = l.id_ubicacion
                    ) + (
                        SELECT COUNT(*)
                        FROM Asistencia_Salida s
                        WHERE s.id_ubicacion = l.id_ubicacion
                    ) AS cantidad_uso
                 FROM Ubicaciones l
                 WHERE l.id_ubicacion = :id_ubicacion
                 LIMIT 1
                 FOR UPDATE'
            );
            $statement->execute(['id_ubicacion' => $locationId]);
            $existingLocation = $statement->fetch();

            if (!is_array($existingLocation)) {
                $connection->rollBack();
                Response::error(
                    404,
                    'location_not_found',
                    'La ubicación indicada no existe.'
                );
            }

            $usageCount = (int) $existingLocation['cantidad_uso'];
            if ($usageCount > 0) {
                $archiveStatement = $connection->prepare(
                    "UPDATE Ubicaciones
                     SET estado_activo = b'0'
                     WHERE id_ubicacion = :id_ubicacion"
                );
                $archiveStatement->execute([
                    'id_ubicacion' => $locationId,
                ]);
                $connection->commit();

                Response::json([
                    'message' =>
                        'La ubicación tiene marcaciones históricas y fue archivada.',
                    'deleted' => false,
                    'archived' => true,
                ]);
            }

            $deleteStatement = $connection->prepare(
                'DELETE FROM Ubicaciones
                 WHERE id_ubicacion = :id_ubicacion'
            );
            $deleteStatement->execute(['id_ubicacion' => $locationId]);
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            error_log(
                '[API Asistencia][admin_location_delete] '
                . $exception->getMessage()
            );
            Response::error(
                503,
                'location_delete_unavailable',
                'No fue posible eliminar la ubicación.'
            );
        }

        Response::json([
            'message' => 'Ubicación eliminada correctamente.',
            'deleted' => true,
            'archived' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $claims
     */
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
                '[API Asistencia][admin_authorization] '
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
                ['admin', 'administrador', 'administración', 'administracion'],
                true
            )) {
            Response::error(
                403,
                'administrator_required',
                'Esta función requiere un rol administrativo.'
            );
        }

        return $connection;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{
     *     name: string,
     *     address: ?string,
     *     city: ?string,
     *     latitude: float,
     *     longitude: float,
     *     active: bool
     * }
     */
    private function locationPayload(array $body): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        $address = trim((string) ($body['address'] ?? ''));
        $city = trim((string) ($body['city'] ?? ''));
        $latitude = filter_var(
            $body['latitude'] ?? null,
            FILTER_VALIDATE_FLOAT
        );
        $longitude = filter_var(
            $body['longitude'] ?? null,
            FILTER_VALIDATE_FLOAT
        );
        $active = $body['active'] ?? true;

        if ($name === '') {
            Response::error(
                422,
                'location_name_required',
                'Debe indicar el nombre de la ubicación.'
            );
        }

        if (strlen($name) > 100) {
            Response::error(
                422,
                'location_name_too_long',
                'El nombre no puede superar los 100 caracteres.'
            );
        }

        if (strlen($address) > 255) {
            Response::error(
                422,
                'location_address_too_long',
                'La dirección no puede superar los 255 caracteres.'
            );
        }

        if (strlen($city) > 50) {
            Response::error(
                422,
                'location_city_too_long',
                'La ciudad no puede superar los 50 caracteres.'
            );
        }

        if ($latitude === false
            || !is_finite($latitude)
            || $latitude < -90
            || $latitude > 90) {
            Response::error(
                422,
                'invalid_location_latitude',
                'La latitud debe estar entre -90 y 90.'
            );
        }

        if ($longitude === false
            || !is_finite($longitude)
            || $longitude < -180
            || $longitude > 180) {
            Response::error(
                422,
                'invalid_location_longitude',
                'La longitud debe estar entre -180 y 180.'
            );
        }

        if (!is_bool($active)) {
            Response::error(
                422,
                'invalid_location_status',
                'El estado de la ubicación no es válido.'
            );
        }

        return [
            'name' => $name,
            'address' => $address === '' ? null : $address,
            'city' => $city === '' ? null : $city,
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'active' => $active,
        ];
    }

    /** @return array<string, mixed> */
    private function requestBody(): array
    {
        try {
            $body = json_decode(
                file_get_contents('php://input') ?: '',
                true,
                32,
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
