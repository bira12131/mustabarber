-- ============================================================
-- Migration: Adicionar coluna ultimo_acesso na tabela usuarios
-- Sistema: MustaBarber
-- Data: 06/05/2026
-- Execute este script uma única vez no banco de dados.
-- ============================================================

-- 1. Adicionar coluna ultimo_acesso
ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS ultimo_acesso TIMESTAMP NULL;

COMMENT ON COLUMN usuarios.ultimo_acesso
    IS 'Data e hora do último login realizado pelo usuário';

-- 2. Garantir que dia_vencimento aceite até 31 (era 28 na documentação)
ALTER TABLE usuarios
    ALTER COLUMN dia_vencimento TYPE SMALLINT;

-- Atualizar constraint: dia entre 1 e 31
ALTER TABLE usuarios
    DROP CONSTRAINT IF EXISTS chk_dia_vencimento;

ALTER TABLE usuarios
    ADD CONSTRAINT chk_dia_vencimento CHECK (dia_vencimento BETWEEN 1 AND 31);

-- 3. Índice para queries de último acesso (admin panel)
CREATE INDEX IF NOT EXISTS idx_usuarios_ultimo_acesso ON usuarios(ultimo_acesso);
