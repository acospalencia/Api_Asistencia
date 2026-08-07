CREATE TABLE IF NOT EXISTS Password_Reset_Tokens (
    id_reset BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_usuario INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_reset),
    INDEX IX_Password_Reset_User_Active (id_usuario, used_at, expires_at),
    CONSTRAINT FK_Password_Reset_Usuario
        FOREIGN KEY (id_usuario) REFERENCES Usuarios(id_usuario)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
