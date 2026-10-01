-- ============================================
-- Migration: Reset e Reconfiguração Completa da Tabela Perfil
-- Sistema: MustaBarber
-- Data: 25/04/2026
--
-- ATENÇÃO: Este script apaga todos os perfis e permissões existentes
-- e os recria do zero com os códigos corretos.
--
-- Mapeamento de códigos (deve estar em sincronia com plano_manager.php):
--   1 = Administrador
--   2 = Solo Básico   (Starter)        – 1 barbeiro
--   3 = Solo Pro      (Essencial)      – 1 barbeiro
--   4 = Equipe                         – até 3 barbeiros
--   5 = Cliente                        – acesso ao agendamento próprio
--   6 = Estabelecimento (Premium)      – até 8 barbeiros
--   7 = Master                         – até 12 barbeiros
-- ============================================

-- ============================================================
-- 1. Limpar tabelas dependentes (FK) e resetar sequências
-- ============================================================

-- Desabilitar temporariamente verificação de FK para limpeza segura
-- (PostgreSQL: usar TRUNCATE ... CASCADE)
TRUNCATE TABLE permissao RESTART IDENTITY CASCADE;
TRUNCATE TABLE perfil    RESTART IDENTITY CASCADE;

-- ============================================================
-- 2. Reinserir todos os perfis com IDs corretos
-- ============================================================
INSERT INTO perfil (codigo, nome, descricao) VALUES
(1, 'Administrador',
   'Acesso total ao sistema incluindo gestão de usuários e configurações'),

(2, 'Barbeiro Plano Solo Básico',
   'Plano Starter (R$52,90/mês): 1 barbeiro – agendamentos, clientes e catálogo de serviços'),

(3, 'Barbeiro Plano Solo Pro',
   'Plano Essencial (R$64,90/mês): 1 barbeiro – tudo do Starter + financeiro, estoque e WhatsApp automático'),

(4, 'Barbeiro Plano Equipe',
   'Plano Equipe (R$94,90/mês): até 3 barbeiros – tudo do Solo Pro + agenda individual, relatório por barbeiro e permissões por perfil'),

(5, 'Cliente',
   'Acesso limitado: visualizar e gerenciar apenas seus próprios agendamentos'),

(6, 'Barbeiro Plano Estabelecimento',
   'Plano Premium (R$136,90/mês): até 8 barbeiros – tudo do Equipe + relatórios avançados, suporte prioritário e onboarding guiado'),

(7, 'Barbeiro Plano Master',
   'Plano Master (R$199,90/mês): até 12 barbeiros – tudo do Estabelecimento + múltiplas unidades, gerente por unidade e suporte VIP');

-- Corrigir a sequência para o próximo INSERT automático partir de 8
SELECT setval('perfil_codigo_seq', (SELECT MAX(codigo) FROM perfil));

-- ============================================================
-- 3. Permissões do Administrador (perfil 1)
-- ============================================================
INSERT INTO permissao (codigo_perfil, nome, modulo, acao, descricao) VALUES
(1, 'Ver Dashboard',            'dashboard',      'visualizar',   'Visualizar painel principal com estatísticas'),
(1, 'Ver Agendamentos',         'agendamentos',   'visualizar',   'Visualizar lista de agendamentos'),
(1, 'Criar Agendamentos',       'agendamentos',   'criar',        'Criar novos agendamentos'),
(1, 'Editar Agendamentos',      'agendamentos',   'editar',       'Editar agendamentos existentes'),
(1, 'Excluir Agendamentos',     'agendamentos',   'excluir',      'Excluir agendamentos'),
(1, 'Ver Clientes',             'clientes',       'visualizar',   'Visualizar lista de clientes'),
(1, 'Criar Clientes',           'clientes',       'criar',        'Cadastrar novos clientes'),
(1, 'Editar Clientes',          'clientes',       'editar',       'Editar dados de clientes'),
(1, 'Excluir Clientes',         'clientes',       'excluir',      'Excluir clientes'),
(1, 'Ver Serviços',             'servicos',       'visualizar',   'Visualizar lista de serviços'),
(1, 'Criar Serviços',           'servicos',       'criar',        'Cadastrar novos serviços'),
(1, 'Editar Serviços',          'servicos',       'editar',       'Editar serviços existentes'),
(1, 'Excluir Serviços',         'servicos',       'excluir',      'Excluir serviços'),
(1, 'Ver Produtos',             'produtos',       'visualizar',   'Visualizar lista de produtos'),
(1, 'Criar Produtos',           'produtos',       'criar',        'Cadastrar novos produtos'),
(1, 'Editar Produtos',          'produtos',       'editar',       'Editar produtos existentes'),
(1, 'Excluir Produtos',         'produtos',       'excluir',      'Excluir produtos'),
(1, 'Entrada Estoque',          'produtos',       'estoque',      'Registrar entrada de estoque'),
(1, 'Ver Financeiro',           'financeiro',     'visualizar',   'Visualizar gestão financeira'),
(1, 'Criar Receitas',           'financeiro',     'receita_criar','Registrar receitas'),
(1, 'Criar Despesas',           'financeiro',     'despesa_criar','Registrar despesas'),
(1, 'Excluir Transações',       'financeiro',     'excluir',      'Excluir transações financeiras'),
(1, 'Ver Horários',             'horarios',       'visualizar',   'Visualizar configuração de horários'),
(1, 'Configurar Horários',      'horarios',       'configurar',   'Configurar horários de funcionamento'),
(1, 'Bloquear Horários',        'horarios',       'bloquear',     'Bloquear horários específicos'),
(1, 'Enviar WhatsApp',          'whatsapp',       'enviar',       'Enviar mensagens via WhatsApp'),
(1, 'Gerenciar Funcionários',   'funcionarios',   'gerenciar',    'Adicionar e remover funcionários da barbearia'),
(1, 'Relatórios Avançados',     'relatorios_avancados', 'visualizar', 'Visualizar relatórios avançados'),
(1, 'Múltiplas Unidades',       'multiplas_unidades',   'gerenciar',  'Gerenciar múltiplas unidades'),
(1, 'Gerente por Unidade',      'gerente_unidade',      'gerenciar',  'Definir gerentes por unidade'),
(1, 'Acessar Admin',            'administrador',  'visualizar',   'Acessar painel administrativo'),
(1, 'Gerenciar Usuários',       'administrador',  'gerenciar',    'Gerenciar todos os usuários do sistema');

-- ============================================================
-- 4. Permissões do Solo Básico (perfil 2)
--    Dashboard + Agendamentos + Clientes + Serviços + Horários
-- ============================================================
INSERT INTO permissao (codigo_perfil, nome, modulo, acao, descricao) VALUES
(2, 'Ver Dashboard',        'dashboard',    'visualizar', 'Visualizar painel principal'),
(2, 'Ver Agendamentos',     'agendamentos', 'visualizar', 'Visualizar agendamentos'),
(2, 'Criar Agendamentos',   'agendamentos', 'criar',      'Criar agendamentos'),
(2, 'Editar Agendamentos',  'agendamentos', 'editar',     'Editar agendamentos'),
(2, 'Ver Clientes',         'clientes',     'visualizar', 'Visualizar clientes'),
(2, 'Criar Clientes',       'clientes',     'criar',      'Cadastrar clientes'),
(2, 'Ver Serviços',         'servicos',     'visualizar', 'Visualizar serviços'),
(2, 'Ver Horários',         'horarios',     'visualizar', 'Visualizar horários');

-- ============================================================
-- 5. Permissões do Solo Pro (perfil 3)
--    Tudo do Solo Básico + Financeiro + Produtos/Estoque + WhatsApp
-- ============================================================
INSERT INTO permissao (codigo_perfil, nome, modulo, acao, descricao) VALUES
(3, 'Ver Dashboard',        'dashboard',    'visualizar',    'Visualizar painel principal'),
(3, 'Ver Agendamentos',     'agendamentos', 'visualizar',    'Visualizar agendamentos'),
(3, 'Criar Agendamentos',   'agendamentos', 'criar',         'Criar agendamentos'),
(3, 'Editar Agendamentos',  'agendamentos', 'editar',        'Editar agendamentos'),
(3, 'Ver Clientes',         'clientes',     'visualizar',    'Visualizar clientes'),
(3, 'Criar Clientes',       'clientes',     'criar',         'Cadastrar clientes'),
(3, 'Editar Clientes',      'clientes',     'editar',        'Editar clientes'),
(3, 'Ver Serviços',         'servicos',     'visualizar',    'Visualizar serviços'),
(3, 'Criar Serviços',       'servicos',     'criar',         'Criar serviços'),
(3, 'Editar Serviços',      'servicos',     'editar',        'Editar serviços'),
(3, 'Ver Produtos',         'produtos',     'visualizar',    'Visualizar produtos e estoque'),
(3, 'Criar Produtos',       'produtos',     'criar',         'Cadastrar produtos'),
(3, 'Editar Produtos',      'produtos',     'editar',        'Editar produtos'),
(3, 'Entrada Estoque',      'produtos',     'estoque',       'Registrar entrada de estoque'),
(3, 'Ver Financeiro',       'financeiro',   'visualizar',    'Visualizar gestão financeira'),
(3, 'Criar Receitas',       'financeiro',   'receita_criar', 'Registrar receitas'),
(3, 'Criar Despesas',       'financeiro',   'despesa_criar', 'Registrar despesas'),
(3, 'Ver Horários',         'horarios',     'visualizar',    'Visualizar horários'),
(3, 'Configurar Horários',  'horarios',     'configurar',    'Configurar horários de funcionamento'),
(3, 'Enviar WhatsApp',      'whatsapp',     'enviar',        'Enviar mensagens via WhatsApp');

-- ============================================================
-- 6. Permissões do Equipe (perfil 4)
--    Tudo do Solo Pro + Funcionários + Relatório por barbeiro + Permissões por perfil
-- ============================================================
INSERT INTO permissao (codigo_perfil, nome, modulo, acao, descricao)
SELECT 4, nome, modulo, acao, descricao FROM permissao WHERE codigo_perfil = 3;

INSERT INTO permissao (codigo_perfil, nome, modulo, acao, descricao) VALUES
(4, 'Excluir Agendamentos',       'agendamentos',      'excluir',    'Excluir agendamentos'),
(4, 'Excluir Clientes',           'clientes',          'excluir',    'Excluir clientes'),
(4, 'Excluir Serviços',           'servicos',          'excluir',    'Excluir serviços'),
(4, 'Excluir Produtos',           'produtos',          'excluir',    'Excluir produtos'),
(4, 'Excluir Transações',         'financeiro',        'excluir',    'Excluir transações financeiras'),
(4, 'Bloquear Horários',          'horarios',          'bloquear',   'Bloquear horários específicos'),
(4, 'Gerenciar Funcionários',     'funcionarios',      'gerenciar',  'Adicionar e remover funcionários da barbearia'),
(4, 'Relatório por Barbeiro',     'relatorio_barbeiro','visualizar', 'Visualizar relatórios individuais por barbeiro'),
(4, 'Permissões por Perfil',      'permissoes_perfil', 'gerenciar',  'Gerenciar permissões por perfil de equipe');

-- ============================================================
-- 7. Permissões do Cliente (perfil 5)
-- ============================================================
INSERT INTO permissao (codigo_perfil, nome, modulo, acao, descricao) VALUES
(5, 'Ver Meus Agendamentos', 'agendamentos', 'visualizar_proprios', 'Visualizar apenas seus próprios agendamentos'),
(5, 'Criar Agendamento',     'agendamentos', 'criar_proprio',       'Criar agendamento para si mesmo'),
(5, 'Cancelar Agendamento',  'agendamentos', 'cancelar_proprio',    'Cancelar seu próprio agendamento');

-- ============================================================
-- 8. Permissões do Estabelecimento (perfil 6)
--    Tudo do Equipe + Relatórios avançados
-- ============================================================
INSERT INTO permissao (codigo_perfil, nome, modulo, acao, descricao)
SELECT 6, nome, modulo, acao, descricao FROM permissao WHERE codigo_perfil = 4;

INSERT INTO permissao (codigo_perfil, nome, modulo, acao, descricao) VALUES
(6, 'Relatórios Avançados', 'relatorios_avancados', 'visualizar',
   'Visualizar relatórios avançados de desempenho por barbeiro e por período');

-- ============================================================
-- 9. Permissões do Master (perfil 7)
--    Tudo do Estabelecimento + Múltiplas unidades + Gerente por unidade
-- ============================================================
INSERT INTO permissao (codigo_perfil, nome, modulo, acao, descricao)
SELECT 7, nome, modulo, acao, descricao FROM permissao WHERE codigo_perfil = 6;

INSERT INTO permissao (codigo_perfil, nome, modulo, acao, descricao) VALUES
(7, 'Gerenciar Múltiplas Unidades', 'multiplas_unidades', 'gerenciar',
   'Criar e gerenciar múltiplas unidades de barbearia'),
(7, 'Gerente por Unidade',          'gerente_unidade',    'gerenciar',
   'Definir gerentes responsáveis por cada unidade');

-- ============================================================
-- Fim da migration
-- ============================================================

