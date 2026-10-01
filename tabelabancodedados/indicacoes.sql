-- ============================================
-- Script de Criação da Tabela: indicacoes
-- Sistema: MustaBarber
-- Descrição: Tabela para controlar indicações entre barbeiros.
--            Quando um barbeiro indica 10 pessoas que se cadastram
--            e assinam um plano, ele fica isento de mensalidade.
-- Banco de Dados: PostgreSQL
-- Data: 26/03/2026
-- ============================================

-- Remove a tabela se já existir (use com cuidado em produção)
-- DROP TABLE IF EXISTS indicacoes CASCADE;

-- Coluna de isenção na tabela usuarios (executar antes desta tabela)
-- ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS isento_pagamento BOOLEAN NOT NULL DEFAULT FALSE;
-- COMMENT ON COLUMN usuarios.isento_pagamento IS 'TRUE quando o barbeiro está isento de mensalidade por ter 10 indicações convertidas';

-- Criação da tabela indicacoes
CREATE TABLE indicacoes (
    -- Chave primária
    codigo SERIAL PRIMARY KEY,

    -- Quem fez a indicação (barbeiro já cadastrado)
    codigo_indicador INT NOT NULL,

    -- Contato informado no momento do cadastro (email ou telefone do indicador)
    contato_informado VARCHAR(150) NOT NULL,

    -- Código do barbeiro que se cadastrou via indicação (preenchido após o cadastro)
    codigo_indicado INT NULL,

    -- Indica se o indicado já assinou algum plano (conversão realizada)
    convertido BOOLEAN NOT NULL DEFAULT FALSE,

    -- Data da conversão (quando o indicado passou a ser assinante)
    data_conversao TIMESTAMP NULL,

    -- Controle de auditoria
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Chaves estrangeiras
    CONSTRAINT fk_indicacoes_indicador
        FOREIGN KEY (codigo_indicador)
        REFERENCES usuarios(codigo)
        ON DELETE CASCADE,

    CONSTRAINT fk_indicacoes_indicado
        FOREIGN KEY (codigo_indicado)
        REFERENCES usuarios(codigo)
        ON DELETE SET NULL
);

-- Índices para otimização
CREATE INDEX idx_indicacoes_codigo_indicador ON indicacoes(codigo_indicador);
CREATE INDEX idx_indicacoes_codigo_indicado ON indicacoes(codigo_indicado);
CREATE INDEX idx_indicacoes_convertido ON indicacoes(convertido);

-- Comentários dos campos
COMMENT ON TABLE indicacoes IS 'Registra indicações feitas por barbeiros. Ao atingir 10 conversões (indicados que assinaram), o indicador fica isento de mensalidade.';
COMMENT ON COLUMN indicacoes.codigo IS 'Identificador único da indicação';
COMMENT ON COLUMN indicacoes.codigo_indicador IS 'Código do barbeiro que realizou a indicação';
COMMENT ON COLUMN indicacoes.contato_informado IS 'E-mail ou telefone do indicador informado pelo novo barbeiro no cadastro';
COMMENT ON COLUMN indicacoes.codigo_indicado IS 'Código do barbeiro que se cadastrou por esta indicação';
COMMENT ON COLUMN indicacoes.convertido IS 'TRUE quando o indicado assinou algum plano pago';
COMMENT ON COLUMN indicacoes.data_conversao IS 'Data/hora em que o indicado realizou o primeiro pagamento de plano';
COMMENT ON COLUMN indicacoes.data_cadastro IS 'Data/hora em que a indicação foi registrada';

-- ============================================
-- Migration: adicionar coluna isento_pagamento na tabela usuarios
-- Executar separadamente caso a tabela já exista:
-- ============================================
-- ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS isento_pagamento BOOLEAN NOT NULL DEFAULT FALSE;
