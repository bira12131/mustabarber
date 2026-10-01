-- Tabela para armazenar horários bloqueados específicos por barbeiro e data
CREATE TABLE IF NOT EXISTS horarios_bloqueados (
    codigo SERIAL PRIMARY KEY,
    codigo_barbeiro INTEGER NOT NULL,
    data_bloqueio DATE NOT NULL,
    horario TIME NOT NULL,
    motivo VARCHAR(255) DEFAULT NULL,
    codigo_tipo_bloqueio INTEGER NOT NULL DEFAULT 1,
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    criado_por INTEGER DEFAULT NULL,
    FOREIGN KEY (codigo_barbeiro) REFERENCES usuarios(codigo) ON DELETE CASCADE,
    FOREIGN KEY (codigo_tipo_bloqueio) REFERENCES tipo_bloqueio(codigo) ON DELETE RESTRICT,
    FOREIGN KEY (criado_por) REFERENCES usuarios(codigo) ON DELETE SET NULL,
    CONSTRAINT unique_usuario_data_hora UNIQUE (codigo_barbeiro, data_bloqueio, horario)
);

-- Comentários explicativos
COMMENT ON TABLE horarios_bloqueados IS 'Horários bloqueados específicos por barbeiro e data';
COMMENT ON COLUMN horarios_bloqueados.motivo IS 'Motivo do bloqueio (opcional)';
COMMENT ON COLUMN horarios_bloqueados.criado_por IS 'Código do usuário que criou o bloqueio';

-- Índices para melhorar performance nas consultas
CREATE INDEX idx_horarios_bloqueados_usuario_data ON horarios_bloqueados(codigo_barbeiro, data_bloqueio);
CREATE INDEX idx_horarios_bloqueados_data ON horarios_bloqueados(data_bloqueio);
CREATE INDEX idx_horarios_bloqueados_tipo ON horarios_bloqueados(codigo_tipo_bloqueio);
