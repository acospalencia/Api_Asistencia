-- Ejecutar una sola vez en Bd_Nordictechsv antes de publicar los endpoints.

ALTER TABLE Eventos_Jornada
    ADD COLUMN mensaje_supervisor VARCHAR(500) NULL
        AFTER comentario_tecnico,
    ADD COLUMN hora_mensaje_supervisor DATETIME NULL
        AFTER mensaje_supervisor;

CREATE INDEX IX_Eventos_Jornada_Estado
    ON Eventos_Jornada (id_jornada, estado_evento);

CREATE TABLE IF NOT EXISTS Dispositivos_Notificacion (
    id_dispositivo INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    token_dispositivo VARCHAR(512) NOT NULL,
    plataforma VARCHAR(20) NOT NULL,
    estado_activo BIT DEFAULT 1,
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT UQ_Dispositivo_Token UNIQUE (token_dispositivo),
    CONSTRAINT FK_Dispositivo_Usuario
        FOREIGN KEY (id_usuario)
        REFERENCES Usuarios(id_usuario)
        ON DELETE CASCADE,
    INDEX IX_Dispositivo_Usuario (id_usuario, estado_activo)
);
