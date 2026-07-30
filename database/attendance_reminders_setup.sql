-- Ejecutar una sola vez en Bd_Nordictechsv antes de activar el cron.

CREATE TABLE IF NOT EXISTS Recordatorios_Asistencia (
    id_recordatorio INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    fecha_recordatorio DATE NOT NULL,
    tipo_recordatorio VARCHAR(20) NOT NULL,
    fecha_proceso DATETIME DEFAULT CURRENT_TIMESTAMP,
    envios_exitosos INT NOT NULL DEFAULT 0,
    CONSTRAINT UQ_Recordatorio_Usuario_Fecha_Tipo
        UNIQUE (id_usuario, fecha_recordatorio, tipo_recordatorio),
    CONSTRAINT FK_Recordatorio_Usuario
        FOREIGN KEY (id_usuario)
        REFERENCES Usuarios(id_usuario)
        ON DELETE CASCADE,
    INDEX IX_Recordatorio_Fecha_Tipo (
        fecha_recordatorio,
        tipo_recordatorio
    )
);
