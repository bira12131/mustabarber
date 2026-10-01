-- ============================================
-- Script de Criação da Tabela: perfil
-- Sistema: MustaBarber
-- Descrição: Tabela para armazenar perfis de usuários e seus níveis de acesso
-- Banco de Dados: PostgreSQL
-- Data: 21/12/2025
-- ============================================

-- Remove a tabela se já existir (use com cuidado em produção)
-- DROP TABLE IF EXISTS perfil CASCADE;

-- Criação da tabela perfil
CREATE TABLE perfil (
    -- Chave primária
    codigo SERIAL PRIMARY KEY,
    
    -- Dados do perfil
    nome VARCHAR(100) NOT NULL UNIQUE,
    descricao TEXT,
    
    -- Controle de auditoria
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Índices para otimização de consultas
CREATE INDEX idx_perfil_nome ON perfil(nome);

-- Comentários dos campos
COMMENT ON TABLE perfil IS 'Tabela de perfis de usuários do sistema';
COMMENT ON COLUMN perfil.codigo IS 'Identificador único do perfil';
COMMENT ON COLUMN perfil.nome IS 'Nome do perfil (ex: Administrador, Barbeiro Plano Básico)';
COMMENT ON COLUMN perfil.descricao IS 'Descrição detalhada do perfil e suas permissões';
COMMENT ON COLUMN perfil.data_cadastro IS 'Data e hora do cadastro do perfil';

-- Inserir perfis padrão do sistema
INSERT INTO perfil (codigo, nome, descricao) VALUES
(1, 'Administrador', 'Acesso total ao sistema incluindo gestão de usuários e configurações'),
(2, 'Barbeiro Plano Básico', 'Acesso básico: agendamentos, clientes e serviços'),
(3, 'Barbeiro Plano Médio', 'Acesso médio: agendamentos, clientes, serviços, produtos e financeiro básico'),
(4, 'Barbeiro Plano Avançado', 'Acesso completo: todos os módulos incluindo relatórios avançados e integrações'),
(5, 'Cliente', 'Acesso limitado para visualizar e gerenciar apenas seus próprios agendamentos');

-- Resetar sequence para próximo ID
SELECT setval('perfil_codigo_seq', (SELECT MAX(codigo) FROM perfil));

-- ============================================
-- Fim do Script
-- ============================================
