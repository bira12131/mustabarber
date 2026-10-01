-- ============================================
-- Script de Criação da Tabela: solicitacoes_plano_cliente
-- Sistema: MustaBarber
-- Descrição: Tabela para armazenar solicitações de planos de assinatura
--            feitas pelos clientes diretamente ao barbeiro.
-- Banco de Dados: PostgreSQL
-- Data: 01/05/2026
-- ============================================

-- ============================================
-- Migration: adicionar coluna permite_solicitacao_plano na tabela usuarios
-- Execute se a tabela já existir:
-- ============================================
ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS permite_solicitacao_plano BOOLEAN NOT NULL DEFAULT FALSE;

COMMENT ON COLUMN usuarios.permite_solicitacao_plano
    IS 'Se TRUE, o barbeiro aceita solicitações de planos personalizados dos clientes';

-- ============================================
-- Criação da tabela de solicitações de plano
-- ============================================
CREATE TABLE IF NOT EXISTS solicitacoes_plano_cliente (
    codigo               SERIAL PRIMARY KEY,

    -- Barbeiro alvo da solicitação
    codigo_barbeiro      INTEGER NOT NULL,

    -- Dados do cliente (pode ser cliente cadastrado ou avulso via link público)
    cliente_nome         VARCHAR(150) NOT NULL,
    cliente_telefone     VARCHAR(20)  NOT NULL,

    -- Serviços escolhidos pelo cliente (JSON: [{codigo, nome, valor, quantidade}])
    servicos             JSONB        NOT NULL DEFAULT '[]',

    -- Dia da semana e horário preferidos pelo cliente para a sessão recorrente
    -- Valores: segunda, terca, quarta, quinta, sexta, sabado, domingo
    dia_semana           VARCHAR(20)  NOT NULL DEFAULT 'segunda',
    horario_preferido    TIME         NOT NULL,

    -- Periodicidade desejada pelo cliente (semanal, quinzenal, mensal, etc.)
    periodicidade        VARCHAR(50)  NOT NULL DEFAULT 'mensal',

    -- Quantidade de sessões do plano
    quantidade_sessoes   SMALLINT     NOT NULL DEFAULT 1,

    -- Valor total calculado pelo cliente (sem desconto)
    valor_total_original DECIMAL(10,2) NOT NULL DEFAULT 0,

    -- Observações do cliente
    observacoes_cliente  TEXT         NULL,

    -- ---- Campos preenchidos/ajustados pelo barbeiro ----

    -- Barbeiro pode alterar data e horário
    data_ajustada        DATE         NULL,
    horario_ajustado     TIME         NULL,

    -- Tipo de desconto: 'percentual' ou 'fixo'
    tipo_desconto        VARCHAR(20)  NULL,

    -- Valor do desconto (percentual 0-100 ou valor fixo em R$)
    valor_desconto       DECIMAL(10,2) NULL,

    -- Valor final após desconto aplicado pelo barbeiro
    valor_final          DECIMAL(10,2) NULL,

    -- Observações do barbeiro
    observacoes_barbeiro TEXT         NULL,

    -- ---- Status da solicitação ----
    -- pendente | aceito | recusado | cancelado_cliente
    status               VARCHAR(30)  NOT NULL DEFAULT 'pendente',

    -- Data de resposta do barbeiro
    data_resposta        TIMESTAMP    NULL,

    -- Auditoria
    data_criacao         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_spc_barbeiro
        FOREIGN KEY (codigo_barbeiro) REFERENCES usuarios(codigo) ON DELETE CASCADE
);

-- Índices
CREATE INDEX IF NOT EXISTS idx_spc_barbeiro  ON solicitacoes_plano_cliente(codigo_barbeiro);
CREATE INDEX IF NOT EXISTS idx_spc_status    ON solicitacoes_plano_cliente(status);
CREATE INDEX IF NOT EXISTS idx_spc_telefone  ON solicitacoes_plano_cliente(cliente_telefone);
CREATE INDEX IF NOT EXISTS idx_spc_dia_semana ON solicitacoes_plano_cliente(dia_semana);

-- ============================================
-- Migration: caso a tabela já exista com data_preferida DATE
-- Execute os comandos abaixo para migrar a coluna:
-- ============================================
-- ALTER TABLE solicitacoes_plano_cliente
--     ADD COLUMN IF NOT EXISTS dia_semana VARCHAR(20) NOT NULL DEFAULT 'segunda';
-- UPDATE solicitacoes_plano_cliente
--     SET dia_semana = 'segunda' WHERE dia_semana IS NULL;
-- ALTER TABLE solicitacoes_plano_cliente DROP COLUMN IF EXISTS data_preferida;

-- Trigger para atualizar data_atualizacao automaticamente
CREATE OR REPLACE FUNCTION atualizar_data_spc()
RETURNS TRIGGER AS $$
BEGIN
    NEW.data_atualizacao = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_atualizar_spc
BEFORE UPDATE ON solicitacoes_plano_cliente
FOR EACH ROW
EXECUTE FUNCTION atualizar_data_spc();

COMMENT ON TABLE solicitacoes_plano_cliente IS
    'Solicitações de planos personalizados enviadas por clientes ao barbeiro';
