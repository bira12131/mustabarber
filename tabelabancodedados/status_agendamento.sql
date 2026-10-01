-- Tabela para armazenar os status de agendamento
CREATE TABLE IF NOT EXISTS status_agendamento (
    codigo SERIAL PRIMARY KEY,
    nome VARCHAR(50) NOT NULL UNIQUE,
    descricao VARCHAR(255) DEFAULT NULL,
    cor VARCHAR(20) DEFAULT NULL,
    ativo BOOLEAN DEFAULT TRUE,
    ordem_exibicao SMALLINT DEFAULT 0,
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Comentários explicativos
COMMENT ON TABLE status_agendamento IS 'Status possíveis para agendamentos';
COMMENT ON COLUMN status_agendamento.nome IS 'Nome do status';
COMMENT ON COLUMN status_agendamento.descricao IS 'Descrição do status';
COMMENT ON COLUMN status_agendamento.cor IS 'Cor para exibição na interface (hex ou nome)';
COMMENT ON COLUMN status_agendamento.ordem_exibicao IS 'Ordem de exibição na interface';

-- Inserir os status de agendamento padrão
INSERT INTO status_agendamento (nome, descricao, cor, ordem_exibicao) VALUES
('agendado', 'Agendamento realizado, aguardando confirmação', '#FFA500', 1),
('confirmado', 'Agendamento confirmado pelo cliente', '#4CAF50', 2),
('em_atendimento', 'Cliente está sendo atendido', '#2196F3', 3),
('concluido', 'Atendimento concluído com sucesso', '#4CAF50', 4),
('cancelado', 'Agendamento cancelado', '#F44336', 5),
('falta', 'Cliente não compareceu ao agendamento', '#9E9E9E', 6);

-- Índice para melhorar performance
CREATE INDEX idx_status_agendamento_ativo ON status_agendamento(ativo);
CREATE INDEX idx_status_agendamento_ordem ON status_agendamento(ordem_exibicao);
