-- Ejecutar una sola vez en la base de datos de asistencias.

CREATE TABLE IF NOT EXISTS Dias_No_Laborales (
    id_dia_no_laboral INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    motivo VARCHAR(255) NOT NULL,
    id_usuario_crea INT NOT NULL,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT UQ_Dia_No_Laboral_Fecha UNIQUE (fecha),
    CONSTRAINT FK_Dia_No_Laboral_Usuario
        FOREIGN KEY (id_usuario_crea)
        REFERENCES Usuarios(id_usuario)
        ON DELETE RESTRICT,
    INDEX IX_Dias_No_Laborales_Fecha (fecha)
);
