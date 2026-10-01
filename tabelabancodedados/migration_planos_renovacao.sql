-- ============================================================
-- Migration: Suporte a renovação de planos e tag assinante
-- Sistema: MustaBarber
-- Data: 02/05/2026
-- Execute este script uma única vez no banco de dados.
-- ============================================================

-- 1. Adicionar is_assinante à tabela clientes
ALTER TABLE clientes
    ADD COLUMN IF NOT EXISTS is_assinante BOOLEAN NOT NULL DEFAULT FALSE;

COMMENT ON COLUMN clientes.is_assinante
    IS 'TRUE quando o cliente possui plano ativo aceito pelo barbeiro';

-- 2. Adicionar colunas de gestão de plano à tabela solicitacoes_plano_cliente
ALTER TABLE solicitacoes_plano_cliente
    ADD COLUMN IF NOT EXISTS grupo_agendamentos       VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS codigo_cliente           INTEGER      NULL,
    ADD COLUMN IF NOT EXISTS cliente_confirmou        BOOLEAN      NULL,
    ADD COLUMN IF NOT EXISTS data_confirmacao_cliente TIMESTAMP    NULL;

-- Novos status possíveis: 'aguardando_confirmacao_cliente', 'recusado_pelo_cliente'
-- (coluna status é VARCHAR(30) — tamanho suficiente)

COMMENT ON COLUMN solicitacoes_plano_cliente.grupo_agendamentos
    IS 'Identificador do grupo de agendamentos criados para este plano (grupo_recorrente em agendamentos)';

COMMENT ON COLUMN solicitacoes_plano_cliente.codigo_cliente
    IS 'Código do cliente na tabela clientes, se cadastrado';

COMMENT ON COLUMN solicitacoes_plano_cliente.cliente_confirmou
    IS 'NULL = aguardando resposta do cliente, TRUE = aceitou termos, FALSE = recusou';

COMMENT ON COLUMN solicitacoes_plano_cliente.data_confirmacao_cliente
    IS 'Data/hora em que o cliente confirmou ou recusou os termos';

CREATE INDEX IF NOT EXISTS idx_spc_grupo ON solicitacoes_plano_cliente(grupo_agendamentos);

-- 3. Adicionar colunas de recorrência à tabela agendamentos (podem já existir)
ALTER TABLE agendamentos
    ADD COLUMN IF NOT EXISTS is_recorrente            BOOLEAN      NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS grupo_recorrente         VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS data_original_recorrencia DATE         NULL;

CREATE INDEX IF NOT EXISTS idx_agendamentos_grupo ON agendamentos(grupo_recorrente);

-- 4. Índice auxiliar para consultas de renovação
CREATE INDEX IF NOT EXISTS idx_agendamentos_grupo_status
    ON agendamentos(grupo_recorrente, codigo_status)
    WHERE grupo_recorrente IS NOT NULL;
