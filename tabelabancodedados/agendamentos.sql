-- Tabela para armazenar os agendamentos de clientes
CREATE TABLE IF NOT EXISTS agendamentos (
    codigo SERIAL PRIMARY KEY,
    codigo_barbeiro INTEGER NOT NULL,
    cliente_nome VARCHAR(255) NOT NULL,
    cliente_telefone VARCHAR(20) DEFAULT NULL,
    data_agendamento DATE NOT NULL,
    horario TIME NOT NULL,
    codigo_servico INTEGER DEFAULT NULL,
    duracao_minutos INTEGER DEFAULT 30,
    valor DECIMAL(10, 2) DEFAULT NULL,
    codigo_status INTEGER NOT NULL DEFAULT 1,
    observacoes TEXT DEFAULT NULL,
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    cancelado_por INTEGER DEFAULT NULL,
    motivo_cancelamento VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (codigo_barbeiro) REFERENCES usuarios(codigo) ON DELETE CASCADE,
    FOREIGN KEY (codigo_servico) REFERENCES servicos(codigo) ON DELETE SET NULL,
    FOREIGN KEY (codigo_status) REFERENCES status_agendamento(codigo) ON DELETE RESTRICT,
    FOREIGN KEY (cancelado_por) REFERENCES usuarios(codigo) ON DELETE SET NULL,
    CONSTRAINT unique_agendamento UNIQUE (codigo_barbeiro, data_agendamento, horario)
);

-- Comentários explicativos
COMMENT ON TABLE agendamentos IS 'Agendamentos de clientes com barbeiros';
COMMENT ON COLUMN agendamentos.codigo_barbeiro IS 'Código do barbeiro';

-- Trigger para atualizar automaticamente data_atualizacao
CREATE OR REPLACE FUNCTION atualizar_data_agendamentos()
RETURNS TRIGGER AS $$
BEGIN
    NEW.data_atualizacao = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_atualizar_data_agendamentos
BEFORE UPDATE ON agendamentos
FOR EACH ROW
EXECUTE FUNCTION atualizar_data_agendamentos();

-- Índices para melhorar performance
CREATE INDEX idx_agendamentos_usuario_data ON agendamentos(codigo_barbeiro, data_agendamento);
CREATE INDEX idx_agendamentos_data_hora ON agendamentos(data_agendamento, horario);
CREATE INDEX idx_agendamentos_status ON agendamentos(codigo_status);
CREATE INDEX idx_agendamentos_telefone ON agendamentos(cliente_telefone);
CREATE INDEX idx_agendamentos_servico ON agendamentos(codigo_servico);
