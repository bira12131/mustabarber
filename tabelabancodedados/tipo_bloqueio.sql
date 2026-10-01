-- Tabela para armazenar os tipos de bloqueio
CREATE TABLE IF NOT EXISTS tipo_bloqueio (
    codigo SERIAL PRIMARY KEY,
    nome VARCHAR(50) NOT NULL UNIQUE,
    descricao VARCHAR(255) DEFAULT NULL,
    ativo BOOLEAN DEFAULT TRUE,
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Comentários explicativos
COMMENT ON TABLE tipo_bloqueio IS 'Tipos de bloqueio de horários';
COMMENT ON COLUMN tipo_bloqueio.nome IS 'Nome do tipo de bloqueio';
COMMENT ON COLUMN tipo_bloqueio.descricao IS 'Descrição do tipo de bloqueio';

-- Inserir os tipos de bloqueio padrão
INSERT INTO tipo_bloqueio (nome, descricao) VALUES
('individual', 'Bloqueio de um horário específico'),
('dia_completo', 'Bloqueio de todos os horários do dia'),
('periodo', 'Bloqueio de um período de horários');

-- Índice para melhorar performance
CREATE INDEX idx_tipo_bloqueio_ativo ON tipo_bloqueio(ativo);
