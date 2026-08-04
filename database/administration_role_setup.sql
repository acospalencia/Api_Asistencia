-- Ejecutar una vez en producción antes de publicar la app y la API.
INSERT INTO Roles (nombre_rol, descripcion)
VALUES (
    'Administración',
    'Administración del sistema y reportes globales de técnicos'
)
ON DUPLICATE KEY UPDATE
    descripcion = VALUES(descripcion);

-- Ejemplo opcional para asignar el rol a una cuenta existente:
-- UPDATE Usuarios
-- SET id_rol = (
--     SELECT id_rol
--     FROM Roles
--     WHERE LOWER(nombre_rol) IN ('administración', 'administracion')
--     LIMIT 1
-- )
-- WHERE username = 'REEMPLAZAR_USERNAME';
