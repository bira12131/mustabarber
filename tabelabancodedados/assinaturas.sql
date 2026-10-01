-- ============================================
-- Script de Criação da Tabela: assinaturas
-- Sistema: MustaBarber
-- Descrição: Tabela para controlar assinaturas e pagamentos dos barbeiros
-- Banco de Dados: PostgreSQL
-- Data: 21/12/2025
-- ============================================

-- Remove a tabela se já existir (use com cuidado em produção)
-- DROP TABLE IF EXISTS assinaturas CASCADE;

-- Criação da tabela assinaturas
CREATE TABLE assinaturas (
    -- Chave primária
    codigo SERIAL PRIMARY KEY,
    
    -- Relacionamento com usuário/barbeiro
    codigo_barbeiro INT NOT NULL,
    
    -- Relacionamento com perfil/plano
    codigo_perfil INT NOT NULL,
    
    -- Dados da assinatura
    data_inicio DATE NOT NULL,
    data_vencimento DATE NOT NULL,
    valor_mensalidade DECIMAL(10,2) NOT NULL,
    
    -- Status do pagamento
    status_pagamento INT NOT NULL DEFAULT 1, -- 1 = Pendente, 2 = Pago, 3 = Vencido, 4 = Cancelado
    data_pagamento DATE NULL,
    
    -- Informações adicionais
    forma_pagamento VARCHAR(50),
    observacoes TEXT,
    
    -- Controle de auditoria
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Constraints de chaves estrangeiras
    CONSTRAINT fk_assinatura_barbeiro 
        FOREIGN KEY (codigo_barbeiro) 
        REFERENCES usuarios(codigo) 
        ON DELETE CASCADE,
    
    CONSTRAINT fk_assinatura_perfil 
        FOREIGN KEY (codigo_perfil) 
        REFERENCES perfil(codigo) 
        ON DELETE RESTRICT
);

-- Índices para otimização de consultas
CREATE INDEX idx_assinaturas_codigo_barbeiro ON assinaturas(codigo_barbeiro);
CREATE INDEX idx_assinaturas_codigo_perfil ON assinaturas(codigo_perfil);
CREATE INDEX idx_assinaturas_data_vencimento ON assinaturas(data_vencimento);
CREATE INDEX idx_assinaturas_status_pagamento ON assinaturas(status_pagamento);

-- Comentários dos campos
COMMENT ON TABLE assinaturas IS 'Tabela de controle de assinaturas e pagamentos dos barbeiros';
COMMENT ON COLUMN assinaturas.codigo IS 'Identificador único da assinatura';
COMMENT ON COLUMN assinaturas.codigo_barbeiro IS 'Código do barbeiro/usuário';
COMMENT ON COLUMN assinaturas.codigo_perfil IS 'Código do perfil/plano contratado';
COMMENT ON COLUMN assinaturas.data_inicio IS 'Data de início da assinatura';
COMMENT ON COLUMN assinaturas.data_vencimento IS 'Data de vencimento da mensalidade';
COMMENT ON COLUMN assinaturas.valor_mensalidade IS 'Valor da mensalidade do plano';
COMMENT ON COLUMN assinaturas.status_pagamento IS '1=Pendente, 2=Pago, 3=Vencido, 4=Cancelado';
COMMENT ON COLUMN assinaturas.data_pagamento IS 'Data em que o pagamento foi efetuado';
COMMENT ON COLUMN assinaturas.forma_pagamento IS 'Forma de pagamento utilizada';
COMMENT ON COLUMN assinaturas.observacoes IS 'Observações sobre a assinatura';
COMMENT ON COLUMN assinaturas.data_cadastro IS 'Data e hora do cadastro';
COMMENT ON COLUMN assinaturas.data_atualizacao IS 'Data e hora da última atualização';

-- Trigger para atualizar automaticamente data_atualizacao
CREATE OR REPLACE FUNCTION atualizar_data_assinaturas()
RETURNS TRIGGER AS $$
BEGIN
    NEW.data_atualizacao = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_atualizar_data_assinaturas
BEFORE UPDATE ON assinaturas
FOR EACH ROW
EXECUTE FUNCTION atualizar_data_assinaturas();

-- View para facilitar consultas de assinaturas com informações completas
CREATE OR REPLACE VIEW vw_assinaturas_completa AS
SELECT 
    a.codigo,
    a.codigo_barbeiro,
    u.nome AS nome_barbeiro,
    u.email AS email_barbeiro,
    u.telefone AS telefone_barbeiro,
    a.codigo_perfil,
    p.nome AS nome_plano,
    a.data_inicio,
    a.data_vencimento,
    a.valor_mensalidade,
    a.status_pagamento,
    CASE 
        WHEN a.status_pagamento = 1 THEN 'Pendente'
        WHEN a.status_pagamento = 2 THEN 'Pago'
        WHEN a.status_pagamento = 3 THEN 'Vencido'
        WHEN a.status_pagamento = 4 THEN 'Cancelado'
    END AS status_pagamento_texto,
    a.data_pagamento,
    a.forma_pagamento,
    -- Calcular dias para vencimento
    CASE 
        WHEN a.data_vencimento >= CURRENT_DATE THEN (a.data_vencimento - CURRENT_DATE)
        ELSE 0
    END AS dias_para_vencimento,
    -- Verificar se está vencido
    CASE 
        WHEN a.data_vencimento < CURRENT_DATE AND a.status_pagamento = 1 THEN true
        ELSE false
    END AS esta_vencido,
    a.observacoes,
    a.data_cadastro,
    a.data_atualizacao,
    u.data_cadastro AS data_cadastro_barbeiro
FROM assinaturas a
INNER JOIN usuarios u ON a.codigo_barbeiro = u.codigo
INNER JOIN perfil p ON a.codigo_perfil = p.codigo;

COMMENT ON VIEW vw_assinaturas_completa IS 'View com informações completas das assinaturas incluindo dados do barbeiro e plano';

-- ============================================
-- Fim do Script
-- ============================================
