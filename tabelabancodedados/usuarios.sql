-- ============================================
-- Script de Cria��o da Tabela: usuarios
-- Sistema: MustaBarber
-- Descri��o: Tabela para armazenar dados dos barbeiros
-- Banco de Dados: PostgreSQL
-- Data: 27/11/2025
-- ============================================

-- Remove a tabela se j� existir (use com cuidado em produ��o)
-- DROP TABLE IF EXISTS usuarios;

-- Cria��o da tabela usuarios
CREATE TABLE usuarios (
    -- Chave prim�ria
    codigo SERIAL PRIMARY KEY,
    
    -- Dados pessoais
    nome VARCHAR(150) NOT NULL,
    cpf VARCHAR(14) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    telefone VARCHAR(15) NOT NULL,
    
    -- Credenciais de acesso
    senha VARCHAR(255) NOT NULL,
    
    -- Perfil do usu�rio
    perfil INT NOT NULL DEFAULT 1,
    
    -- Foto do barbeiro
    foto_caminho VARCHAR(255) NULL,
    
    -- Controle de status e auditoria
    status INT NOT NULL DEFAULT 1,
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Plano selecionado no trial ('essencial' ou 'premium')
    plano_trial VARCHAR(20) NOT NULL DEFAULT 'essencial',

    -- Indica se o barbeiro está isento de mensalidade (10 indicações convertidas)
    isento_pagamento BOOLEAN NOT NULL DEFAULT FALSE,

    -- Dia do mês preferido pelo barbeiro para vencimento da mensalidade (1 a 28)
    dia_vencimento SMALLINT NOT NULL DEFAULT 1,

    -- Preferência de finalização automática de agendamentos expirados
    finalizacao_automatica BOOLEAN NOT NULL DEFAULT TRUE
);

-- �ndices para otimiza��o de consultas
CREATE INDEX idx_usuarios_cpf ON usuarios(cpf);
CREATE INDEX idx_usuarios_email ON usuarios(email);
CREATE INDEX idx_usuarios_status ON usuarios(status);
CREATE INDEX idx_usuarios_perfil ON usuarios(perfil);
-- ============================================
-- Migration: adicionar colunas para trial e isenção (caso tabela já exista)
-- ============================================
-- ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS plano_trial VARCHAR(20) NOT NULL DEFAULT 'essencial';
-- ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS isento_pagamento BOOLEAN NOT NULL DEFAULT FALSE;
-- ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS dia_vencimento SMALLINT NOT NULL DEFAULT 1;

-- ============================================
-- Migration: preferência de finalização automática de agendamentos
-- ============================================
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS finalizacao_automatica BOOLEAN NOT NULL DEFAULT TRUE;
COMMENT ON COLUMN usuarios.finalizacao_automatica IS 'Se TRUE, agendamentos expirados são finalizados automaticamente';

-- ============================================
-- Migration: adicionar coluna de vínculo com barbearia (sistema de equipes)
-- Execute este bloco caso a tabela já exista sem essa coluna:
-- ============================================
-- ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS codigo_barbearia INTEGER NULL;
-- ALTER TABLE usuarios ADD CONSTRAINT fk_usuarios_barbearia
--     FOREIGN KEY (codigo_barbearia) REFERENCES barbearia(codigo) ON DELETE SET NULL;
-- CREATE INDEX IF NOT EXISTS idx_usuarios_barbearia ON usuarios(codigo_barbearia);
-- Coment�rios dos campos
COMMENT ON TABLE usuarios IS 'Tabela de usu�rios/barbeiros do sistema';
COMMENT ON COLUMN usuarios.codigo IS 'Identificador �nico do usu�rio';
COMMENT ON COLUMN usuarios.nome IS 'Nome completo do barbeiro';
COMMENT ON COLUMN usuarios.cpf IS 'CPF no formato 000.000.000-00';
COMMENT ON COLUMN usuarios.email IS 'E-mail do barbeiro';
COMMENT ON COLUMN usuarios.telefone IS 'Telefone no formato (00) 00000-0000';
COMMENT ON COLUMN usuarios.senha IS 'Senha criptografada do usu�rio';
COMMENT ON COLUMN usuarios.perfil IS 'Perfil/tipo do usu�rio (1=Barbeiro, 2=Admin, etc)';
COMMENT ON COLUMN usuarios.foto_caminho IS 'Caminho da foto 3x4 do barbeiro';
COMMENT ON COLUMN usuarios.status IS 'Status do barbeiro (1=Ativo, 0=Inativo)';
COMMENT ON COLUMN usuarios.data_cadastro IS 'Data e hora do cadastro';
COMMENT ON COLUMN usuarios.data_atualizacao IS 'Data e hora da �ltima atualiza��o';

-- Trigger para atualizar automaticamente data_atualizacao
CREATE OR REPLACE FUNCTION atualizar_data_atualizacao()
RETURNS TRIGGER AS $$
BEGIN
    NEW.data_atualizacao = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_atualizar_usuarios
BEFORE UPDATE ON usuarios
FOR EACH ROW
EXECUTE FUNCTION atualizar_data_atualizacao();

-- ============================================
-- Descri��o dos campos:
-- ============================================
-- codigo: Identificador �nico do usu�rio (chave prim�ria auto incremento)
-- nome: Nome completo do barbeiro
-- cpf: CPF do barbeiro (�nico no sistema)
-- email: E-mail do barbeiro (�nico no sistema)
-- telefone: Telefone de contato
-- senha: Senha criptografada (usar bcrypt ou similar)
-- perfil: Tipo/perfil do usu�rio (1=Barbeiro, 2=Admin, etc)
-- foto_caminho: Caminho relativo ou URL da foto 3x4
-- status: Situa��o do barbeiro (1=Ativo, 0=Inativo)
-- data_cadastro: Data de cria��o do registro
-- data_atualizacao: Data da �ltima modifica��o (atualizada automaticamente)

-- ============================================
-- Exemplos de uso:
-- ============================================

-- Inserir novo usu�rio (exemplo)
/*
INSERT INTO usuarios (nome, cpf, email, telefone, senha, perfil, foto_caminho, status) 
VALUES (
    'Jo�o da Silva',
    '123.456.789-00',
    'joao@mustabarber.com',
    '(11) 98765-4321',
    '$2y$10$abcdefghijklmnopqrstuvwxyz123456', -- senha hash
    1, -- perfil: 1=Barbeiro
    'uploads/fotos/joao-silva-3x4.jpg',
    1 -- status: 1=Ativo
);
*/

-- Consultar todos os usu�rios ativos
/*
SELECT 
    codigo,
    nome,
    cpf,
    email,
    telefone,
    perfil,
    foto_caminho,
    CASE 
        WHEN status = 1 THEN 'Ativo'
        WHEN status = 0 THEN 'Inativo'
    END as status_desc,
    TO_CHAR(data_cadastro, 'DD/MM/YYYY HH24:MI') AS cadastrado_em
FROM usuarios 
WHERE status = 1
ORDER BY nome;
*/

-- Atualizar dados de um usu�rio
/*
UPDATE usuarios 
SET 
    nome = 'Jo�o Silva Santos',
    telefone = '(11) 91234-5678',
    foto_caminho = 'uploads/fotos/joao-silva-novo-3x4.jpg'
WHERE codigo = 1;
*/

-- Alterar status do usu�rio
/*
UPDATE usuarios 
SET status = 0 -- 0=Inativo
WHERE codigo = 1;
*/

-- Buscar usu�rio por CPF
/*
SELECT * FROM usuarios 
WHERE cpf = '123.456.789-00';
*/

-- Buscar usu�rio por e-mail
/*
SELECT * FROM usuarios 
WHERE email = 'joao@mustabarber.com';
*/

-- Contar usu�rios por status
/*
SELECT 
    CASE 
        WHEN status = 1 THEN 'Ativo'
        WHEN status = 0 THEN 'Inativo'
    END as status_desc,
    COUNT(*) as total
FROM usuarios
GROUP BY status;
*/

-- Listar usu�rios por perfil
/*
SELECT 
    codigo,
    nome,
    email,
    perfil,
    CASE 
        WHEN status = 1 THEN 'Ativo'
        ELSE 'Inativo'
    END as status_desc
FROM usuarios
WHERE perfil = 1 -- 1=Barbeiro
ORDER BY nome;
*/

-- ============================================
-- Observa��es importantes:
-- ============================================
-- 1. SEMPRE criptografe as senhas antes de armazenar (use bcrypt, argon2, etc)
-- 2. O campo foto_caminho armazena o caminho relativo da foto (ex: uploads/fotos/usuario-123.jpg)
-- 3. Os campos CPF e email s�o �nicos para evitar duplica��es
-- 4. Use prepared statements/parametrized queries para prevenir SQL Injection
-- 5. Status: 1=Ativo, 0=Inativo
-- 6. Perfil: Defina os tipos de perfil conforme necessidade (1=Barbeiro, 2=Admin, etc)
-- 7. O campo codigo usa SERIAL (auto incremento do PostgreSQL)
-- 8. Trigger atualiza automaticamente o campo data_atualizacao
-- 9. Considere adicionar logs de auditoria em produ��o
-- 10. Fa�a backup regular dos dados
