-- Esquema de tablas necesarias para habilitar las funciones de Tests y Rollback

-- 1. Tabla para almacenar resultados de tests automatizados
CREATE TABLE IF NOT EXISTS test_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    status ENUM('passed', 'failed', 'skipped') NOT NULL DEFAULT 'skipped',
    details TEXT COMMENT 'JSON con los resultados detallados de cada test',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    INDEX idx_session_id (session_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabla para almacenar el historial de versiones de código (necesaria para Rollback)
CREATE TABLE IF NOT EXISTS code_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    version_number INT NOT NULL COMMENT 'Número secuencial de la versión',
    code_content TEXT NOT NULL COMMENT 'Código completo en esta versión',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    INDEX idx_session_version (session_id, version_number),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nota: Asegúrate de que la tabla chat_sessions tenga la columna 'project_id'
-- y que chat_messages exista para registrar mensajes de sistema del rollback.
