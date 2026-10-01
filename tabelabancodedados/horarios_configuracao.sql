-- Tabela para armazenar as configurações de horário de funcionamento por barbeiro
CREATE TABLE IF NOT EXISTS horarios_configuracao (
    codigo SERIAL PRIMARY KEY,
    codigo_barbeiro INTEGER NOT NULL,
    dia_semana SMALLINT NOT NULL CHECK (dia_semana BETWEEN 0 AND 6),
    horario_inicio TIME NOT NULL,
    horario_fim TIME NOT NULL,
    intervalo_minutos INTEGER NOT NULL DEFAULT 30,
    ativo BOOLEAN DEFAULT TRUE,
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (codigo_barbeiro) REFERENCES usuarios(codigo) ON DELETE CASCADE,
    CONSTRAINT unique_usuario_dia UNIQUE (codigo_barbeiro, dia_semana)
);

-- Comentários explicativos
COMMENT ON TABLE horarios_configuracao IS 'Configurações de horário de funcionamento por barbeiro';
COMMENT ON COLUMN horarios_configuracao.dia_semana IS '0=Domingo, 1=Segunda, 2=Terça, 3=Quarta, 4=Quinta, 5=Sexta, 6=Sábado';

-- Trigger para atualizar automaticamente data_atualizacao
CREATE OR REPLACE FUNCTION atualizar_data_horarios_config()
RETURNS TRIGGER AS $$
BEGIN
    NEW.data_atualizacao = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_atualizar_data_horarios_config
BEFORE UPDATE ON horarios_configuracao
FOR EACH ROW
EXECUTE FUNCTION atualizar_data_horarios_config();

-- Índices para melhorar performance
CREATE INDEX idx_horarios_config_usuario_ativo ON horarios_configuracao(codigo_barbeiro, ativo);
CREATE INDEX idx_horarios_config_dia_semana ON horarios_configuracao(dia_semana);
