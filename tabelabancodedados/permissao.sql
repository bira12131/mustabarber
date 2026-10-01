-- ============================================
-- Script de Criação da Tabela: permissao
-- Sistema: MustaBarber
-- Descrição: Tabela para armazenar permissões específicas de cada perfil
-- Banco de Dados: PostgreSQL
-- Data: 21/12/2025
-- ============================================

-- Remove a tabela se já existir (use com cuidado em produção)
-- DROP TABLE IF EXISTS permissao CASCADE;

-- Criação da tabela permissao
CREATE TABLE permissao (
    -- Chave primária
    codigo SERIAL PRIMARY KEY,
    
    -- Relacionamento com perfil
    codigo_perfil INT NOT NULL,
    
    -- Dados da permissão
    nome VARCHAR(100) NOT NULL,
    modulo VARCHAR(50) NOT NULL,
    acao VARCHAR(50) NOT NULL,
    descricao TEXT,
    
    -- Controle de auditoria
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Constraint de chave estrangeira
    CONSTRAINT fk_permissao_perfil 
        FOREIGN KEY (codigo_perfil) 
        REFERENCES perfil(codigo) 
        ON DELETE CASCADE
);

-- Índices para otimização de consultas
CREATE INDEX idx_permissao_codigo_perfil ON permissao(codigo_perfil);
CREATE INDEX idx_permissao_modulo ON permissao(modulo);
CREATE INDEX idx_permissao_acao ON permissao(acao);

-- Comentários dos campos
COMMENT ON TABLE permissao IS 'Tabela de permissões específicas por perfil';
COMMENT ON COLUMN permissao.codigo IS 'Identificador único da permissão';
COMMENT ON COLUMN permissao.codigo_perfil IS 'Código do perfil vinculado';
COMMENT ON COLUMN permissao.nome IS 'Nome da permissão';
COMMENT ON COLUMN permissao.modulo IS 'Módulo do sistema (ex: agendamentos, clientes, produtos)';
COMMENT ON COLUMN permissao.acao IS 'Ação permitida (ex: visualizar, criar, editar, excluir)';
COMMENT ON COLUMN permissao.descricao IS 'Descrição detalhada da permissão';
COMMENT ON COLUMN permissao.data_cadastro IS 'Data e hora do cadastro da permissão';

-- Inserir permissões padrão para Administrador (perfil 1)
INSERT INTO permissao (codigo_perfil, nome, modulo, acao, descricao) VALUES
-- Dashboard
(1, 'Ver Dashboard', 'dashboard', 'visualizar', 'Visualizar painel principal com estatísticas'),
-- Agendamentos
(1, 'Ver Agendamentos', 'agendamentos', 'visualizar', 'Visualizar lista de agendamentos'),
(1, 'Criar Agendamentos', 'agendamentos', 'criar', 'Criar novos agendamentos'),
(1, 'Editar Agendamentos', 'agendamentos', 'editar', 'Editar agendamentos existentes'),
(1, 'Excluir Agendamentos', 'agendamentos', 'excluir', 'Excluir agendamentos'),
-- Clientes
(1, 'Ver Clientes', 'clientes', 'visualizar', 'Visualizar lista de clientes'),
(1, 'Criar Clientes', 'clientes', 'criar', 'Cadastrar novos clientes'),
(1, 'Editar Clientes', 'clientes', 'editar', 'Editar dados de clientes'),
(1, 'Excluir Clientes', 'clientes', 'excluir', 'Excluir clientes'),
-- Serviços
(1, 'Ver Serviços', 'servicos', 'visualizar', 'Visualizar lista de serviços'),
(1, 'Criar Serviços', 'servicos', 'criar', 'Cadastrar novos serviços'),
(1, 'Editar Serviços', 'servicos', 'editar', 'Editar serviços existentes'),
(1, 'Excluir Serviços', 'servicos', 'excluir', 'Excluir serviços'),
-- Produtos
(1, 'Ver Produtos', 'produtos', 'visualizar', 'Visualizar lista de produtos'),
(1, 'Criar Produtos', 'produtos', 'criar', 'Cadastrar novos produtos'),
(1, 'Editar Produtos', 'produtos', 'editar', 'Editar produtos existentes'),
(1, 'Excluir Produtos', 'produtos', 'excluir', 'Excluir produtos'),
(1, 'Entrada Estoque', 'produtos', 'estoque', 'Registrar entrada de estoque'),
-- Financeiro
(1, 'Ver Financeiro', 'financeiro', 'visualizar', 'Visualizar gestão financeira'),
(1, 'Criar Receitas', 'financeiro', 'receita_criar', 'Registrar receitas'),
(1, 'Criar Despesas', 'financeiro', 'despesa_criar', 'Registrar despesas'),
(1, 'Excluir Transações', 'financeiro', 'excluir', 'Excluir transações financeiras'),
-- Horários
(1, 'Ver Horários', 'horarios', 'visualizar', 'Visualizar configuração de horários'),
(1, 'Configurar Horários', 'horarios', 'configurar', 'Configurar horários de funcionamento'),
(1, 'Bloquear Horários', 'horarios', 'bloquear', 'Bloquear horários específicos'),
-- Administrador
(1, 'Acessar Admin', 'administrador', 'visualizar', 'Acessar painel administrativo'),
(1, 'Gerenciar Usuários', 'administrador', 'gerenciar', 'Gerenciar todos os usuários do sistema');

-- Inserir permissões para Barbeiro Plano Básico (perfil 2)
INSERT INTO permissao (codigo_perfil, nome, modulo, acao, descricao) VALUES
(2, 'Ver Dashboard', 'dashboard', 'visualizar', 'Visualizar painel principal'),
(2, 'Ver Agendamentos', 'agendamentos', 'visualizar', 'Visualizar agendamentos'),
(2, 'Criar Agendamentos', 'agendamentos', 'criar', 'Criar agendamentos'),
(2, 'Editar Agendamentos', 'agendamentos', 'editar', 'Editar agendamentos'),
(2, 'Ver Clientes', 'clientes', 'visualizar', 'Visualizar clientes'),
(2, 'Criar Clientes', 'clientes', 'criar', 'Cadastrar clientes'),
(2, 'Ver Serviços', 'servicos', 'visualizar', 'Visualizar serviços'),
(2, 'Ver Horários', 'horarios', 'visualizar', 'Visualizar horários');

-- Inserir permissões para Barbeiro Plano Médio (perfil 3)
INSERT INTO permissao (codigo_perfil, nome, modulo, acao, descricao) VALUES
(3, 'Ver Dashboard', 'dashboard', 'visualizar', 'Visualizar painel principal'),
(3, 'Ver Agendamentos', 'agendamentos', 'visualizar', 'Visualizar agendamentos'),
(3, 'Criar Agendamentos', 'agendamentos', 'criar', 'Criar agendamentos'),
(3, 'Editar Agendamentos', 'agendamentos', 'editar', 'Editar agendamentos'),
(3, 'Ver Clientes', 'clientes', 'visualizar', 'Visualizar clientes'),
(3, 'Criar Clientes', 'clientes', 'criar', 'Cadastrar clientes'),
(3, 'Editar Clientes', 'clientes', 'editar', 'Editar clientes'),
(3, 'Ver Serviços', 'servicos', 'visualizar', 'Visualizar serviços'),
(3, 'Criar Serviços', 'servicos', 'criar', 'Criar serviços'),
(3, 'Ver Produtos', 'produtos', 'visualizar', 'Visualizar produtos'),
(3, 'Ver Financeiro', 'financeiro', 'visualizar', 'Visualizar financeiro básico'),
(3, 'Ver Horários', 'horarios', 'visualizar', 'Visualizar horários'),
(3, 'Configurar Horários', 'horarios', 'configurar', 'Configurar horários');

-- Inserir permissões para Barbeiro Plano Avançado (perfil 4)
INSERT INTO permissao (codigo_perfil, nome, modulo, acao, descricao) VALUES
(4, 'Ver Dashboard', 'dashboard', 'visualizar', 'Visualizar painel principal'),
(4, 'Ver Agendamentos', 'agendamentos', 'visualizar', 'Visualizar agendamentos'),
(4, 'Criar Agendamentos', 'agendamentos', 'criar', 'Criar agendamentos'),
(4, 'Editar Agendamentos', 'agendamentos', 'editar', 'Editar agendamentos'),
(4, 'Excluir Agendamentos', 'agendamentos', 'excluir', 'Excluir agendamentos'),
(4, 'Ver Clientes', 'clientes', 'visualizar', 'Visualizar clientes'),
(4, 'Criar Clientes', 'clientes', 'criar', 'Cadastrar clientes'),
(4, 'Editar Clientes', 'clientes', 'editar', 'Editar clientes'),
(4, 'Excluir Clientes', 'clientes', 'excluir', 'Excluir clientes'),
(4, 'Ver Serviços', 'servicos', 'visualizar', 'Visualizar serviços'),
(4, 'Criar Serviços', 'servicos', 'criar', 'Criar serviços'),
(4, 'Editar Serviços', 'servicos', 'editar', 'Editar serviços'),
(4, 'Excluir Serviços', 'servicos', 'excluir', 'Excluir serviços'),
(4, 'Ver Produtos', 'produtos', 'visualizar', 'Visualizar produtos'),
(4, 'Criar Produtos', 'produtos', 'criar', 'Cadastrar produtos'),
(4, 'Editar Produtos', 'produtos', 'editar', 'Editar produtos'),
(4, 'Excluir Produtos', 'produtos', 'excluir', 'Excluir produtos'),
(4, 'Entrada Estoque', 'produtos', 'estoque', 'Registrar entrada de estoque'),
(4, 'Ver Financeiro', 'financeiro', 'visualizar', 'Visualizar gestão financeira completa'),
(4, 'Criar Receitas', 'financeiro', 'receita_criar', 'Registrar receitas'),
(4, 'Criar Despesas', 'financeiro', 'despesa_criar', 'Registrar despesas'),
(4, 'Excluir Transações', 'financeiro', 'excluir', 'Excluir transações'),
(4, 'Ver Horários', 'horarios', 'visualizar', 'Visualizar horários'),
(4, 'Configurar Horários', 'horarios', 'configurar', 'Configurar horários'),
(4, 'Bloquear Horários', 'horarios', 'bloquear', 'Bloquear horários');

-- Inserir permissões para Cliente (perfil 5)
INSERT INTO permissao (codigo_perfil, nome, modulo, acao, descricao) VALUES
(5, 'Ver Meus Agendamentos', 'agendamentos', 'visualizar_proprios', 'Visualizar apenas seus próprios agendamentos'),
(5, 'Criar Agendamento', 'agendamentos', 'criar_proprio', 'Criar agendamento para si mesmo'),
(5, 'Cancelar Agendamento', 'agendamentos', 'cancelar_proprio', 'Cancelar seu próprio agendamento');

-- ============================================
-- Fim do Script
-- ============================================
