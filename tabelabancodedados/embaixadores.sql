-- ============================================
-- Script de Criação: Sistema de Embaixadores
-- Sistema: MustaBarber
-- Descrição: Tabela para controlar embaixadores da marca e
--            suas indicações com link de referral único.
--            O embaixador recebe 15% do valor das assinaturas
--            geradas via seu link.
-- Banco de Dados: PostgreSQL
-- Data: 06/05/2026
-- ============================================

-- ============================================================
-- 1. Adicionar coluna de desconto_embaixador na tabela assinaturas
--    (percentual de desconto aplicado por ser indicação de embaixador)
-- ============================================================
ALTER TABLE assinaturas
    ADD COLUMN IF NOT EXISTS codigo_embaixador INT NULL,
    ADD COLUMN IF NOT EXISTS desconto_embaixador DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS valor_sem_desconto DECIMAL(10,2) NULL;

-- FK para embaixadores (adicionada após criar a tabela abaixo)
-- ALTER TABLE assinaturas ADD CONSTRAINT fk_assinaturas_embaixador
--     FOREIGN KEY (codigo_embaixador) REFERENCES usuarios(codigo) ON DELETE SET NULL;

-- ============================================================
-- 2. Adicionar coluna de embaixador e slug de referral na tabela usuarios
-- ============================================================
ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS embaixador BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS referral_slug VARCHAR(32) NULL UNIQUE;

COMMENT ON COLUMN usuarios.embaixador IS 'TRUE quando o usuário é embaixador da marca MustaBarber';
COMMENT ON COLUMN usuarios.referral_slug IS 'Código único de referral para link de indicação (ex: slug de 8 chars)';

-- ============================================================
-- 3. Adicionar coluna codigo_embaixador na tabela indicacoes
--    para diferenciar indicações de embaixadores das normais
-- ============================================================
ALTER TABLE indicacoes
    ADD COLUMN IF NOT EXISTS codigo_embaixador INT NULL,
    ADD COLUMN IF NOT EXISTS desconto_aplicado DECIMAL(5,2) NOT NULL DEFAULT 0.00;

-- ============================================================
-- 4. Índices de performance
-- ============================================================
CREATE INDEX IF NOT EXISTS idx_usuarios_embaixador    ON usuarios(embaixador) WHERE embaixador = TRUE;
CREATE INDEX IF NOT EXISTS idx_usuarios_referral_slug ON usuarios(referral_slug);
CREATE INDEX IF NOT EXISTS idx_indicacoes_embaixador  ON indicacoes(codigo_embaixador);
CREATE INDEX IF NOT EXISTS idx_assinaturas_embaixador ON assinaturas(codigo_embaixador);

-- ============================================================
-- 5. Comentários
-- ============================================================
COMMENT ON COLUMN assinaturas.codigo_embaixador    IS 'Embaixador que indicou o barbeiro — base para calcular comissão de 15%';
COMMENT ON COLUMN assinaturas.desconto_embaixador  IS 'Percentual de desconto concedido ao cliente por ter vindo de um embaixador';
COMMENT ON COLUMN assinaturas.valor_sem_desconto   IS 'Valor original da mensalidade antes do desconto do embaixador';
COMMENT ON COLUMN indicacoes.codigo_embaixador     IS 'Se a indicação veio de um embaixador via link, armazena o código do embaixador';
COMMENT ON COLUMN indicacoes.desconto_aplicado     IS 'Percentual de desconto concedido ao indicado (0 se não for embaixador)';
