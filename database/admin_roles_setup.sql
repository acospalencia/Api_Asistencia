INSERT INTO Roles (nombre_rol, descripcion)
VALUES
    ('Administrador', 'Acceso total al panel y configuración del sistema'),
    ('Supervisor', 'Supervisa y administra técnicos asignados'),
    ('Técnico', 'Registra asistencia y realiza labores operativas')
ON DUPLICATE KEY UPDATE
    descripcion = VALUES(descripcion);

-- Elimina asignaciones inactivas creadas por versiones anteriores.
DELETE FROM Asignacion_Supervisores
WHERE estado_activo <> 1 OR estado_activo IS NULL;

-- Ejecute una sola vez la siguiente instrucción, sustituyendo el username,
-- para convertir la primera cuenta administrativa:
--
-- UPDATE Usuarios
-- SET id_rol = (
--     SELECT id_rol
--     FROM Roles
--     WHERE LOWER(nombre_rol) IN ('administrador', 'admin')
--     LIMIT 1
-- )
-- WHERE username = 'REEMPLAZAR_USERNAME';
