-- Tabela para armazenar os dias da semana
CREATE TABLE IF NOT EXISTS dias_semana (
    codigo SERIAL PRIMARY KEY,
    numero SMALLINT NOT NULL UNIQUE CHECK (numero BETWEEN 0 AND 6),
    nome_completo VARCHAR(50) NOT NULL,
    nome_abreviado VARCHAR(10) NOT NULL,
    ativo BOOLEAN DEFAULT TRUE,
    ordem_exibicao SMALLINT DEFAULT 0
);

-- Comentários explicativos
COMMENT ON TABLE dias_semana IS 'Tabela de referência para dias da semana';
COMMENT ON COLUMN dias_semana.numero IS 'Número do dia: 0=Domingo, 1=Segunda, ..., 6=Sábado';
COMMENT ON COLUMN dias_semana.nome_completo IS 'Nome completo do dia da semana';
COMMENT ON COLUMN dias_semana.nome_abreviado IS 'Nome abreviado do dia da semana';
COMMENT ON COLUMN dias_semana.ordem_exibicao IS 'Ordem de exibição na interface';

-- Inserir os dias da semana
INSERT INTO dias_semana (numero, nome_completo, nome_abreviado, ordem_exibicao) VALUES
(0, 'Domingo', 'DOM', 0),
(1, 'Segunda-feira', 'SEG', 1),
(2, 'Terça-feira', 'TER', 2),
(3, 'Quarta-feira', 'QUA', 3),
(4, 'Quinta-feira', 'QUI', 4),
(5, 'Sexta-feira', 'SEX', 5),
(6, 'Sábado', 'SÁB', 6);

-- Índice para melhorar performance
CREATE INDEX idx_dias_semana_numero ON dias_semana(numero);
CREATE INDEX idx_dias_semana_ativo ON dias_semana(ativo);
