-- Tabela para armazenar períodos de fechamento/paralisação da agenda do barbeiro
CREATE TABLE IF NOT EXISTS agenda_paralisacao (
    codigo SERIAL PRIMARY KEY,
    codigo_barbeiro INTEGER NOT NULL,
    data_inicio DATE NOT NULL,
    data_fim DATE DEFAULT NULL,   -- NULL = por tempo indeterminado
    motivo VARCHAR(500) DEFAULT NULL,
    ativa BOOLEAN DEFAULT TRUE,
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (codigo_barbeiro) REFERENCES usuarios(codigo) ON DELETE CASCADE
);

-- Comentários explicativos
COMMENT ON TABLE agenda_paralisacao IS 'Períodos de fechamento/paralisação da agenda do barbeiro';
COMMENT ON COLUMN agenda_paralisacao.data_fim IS 'Data de fim do fechamento. NULL indica tempo indeterminado.';
COMMENT ON COLUMN agenda_paralisacao.ativa IS 'Indica se a paralisação ainda está ativa';

-- Índices para melhorar performance nas consultas
CREATE INDEX idx_agenda_paralisacao_barbeiro ON agenda_paralisacao(codigo_barbeiro, ativa);
CREATE INDEX idx_agenda_paralisacao_datas ON agenda_paralisacao(data_inicio, data_fim);
CREATE INDEX idx_agenda_paralisacao_barbeiro_data ON agenda_paralisacao(codigo_barbeiro, data_inicio);
