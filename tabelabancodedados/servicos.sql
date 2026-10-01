-- ============================================
-- Script de Cria&ccedil;&atilde;o da Tabela: servicos
-- Sistema: MustaBarber
-- Descri&ccedil;&atilde;o: Tabela para armazenar dados dos servi&ccedil;os oferecidos
-- Banco de Dados: PostgreSQL
-- Data: 28/11/2025
-- ============================================

-- Remove a tabela se j&aacute; existir (use com cuidado em produ&ccedil;&atilde;o)
-- DROP TABLE IF EXISTS servicos;

-- Cria&ccedil;&atilde;o da tabela servicos
CREATE TABLE servicos (
    -- Chave prim&aacute;ria
    codigo SERIAL PRIMARY KEY,
    
    -- Dados do servi&ccedil;o
    nome VARCHAR(150) NOT NULL,
    descricao TEXT NULL,
    preco DECIMAL(10, 2) NOT NULL,
    duracao INT NOT NULL,
    
    -- Foto do servi&ccedil;o
    foto_caminho VARCHAR(255) NULL,
    
    -- Relacionamento com barbeiro
    codigo_barbeiro INT NULL,
    
    -- Controle de status e auditoria
    status INT NOT NULL DEFAULT 1,
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Chave estrangeira
    CONSTRAINT fk_servicos_barbeiro FOREIGN KEY (codigo_barbeiro) 
        REFERENCES usuarios(codigo) ON DELETE SET NULL
);

-- &Iacute;ndices para otimiza&ccedil;&atilde;o de consultas
CREATE INDEX idx_servicos_nome ON servicos(nome);
CREATE INDEX idx_servicos_status ON servicos(status);
CREATE INDEX idx_servicos_barbeiro ON servicos(codigo_barbeiro);
CREATE INDEX idx_servicos_preco ON servicos(preco);

-- Coment&aacute;rios dos campos
COMMENT ON TABLE servicos IS 'Tabela de servi&ccedil;os oferecidos pela barbearia';
COMMENT ON COLUMN servicos.codigo IS 'Identificador &uacute;nico do servi&ccedil;o';
COMMENT ON COLUMN servicos.nome IS 'Nome do servi&ccedil;o (ex: Corte de Cabelo)';
COMMENT ON COLUMN servicos.descricao IS 'Descri&ccedil;&atilde;o detalhada do servi&ccedil;o';
COMMENT ON COLUMN servicos.preco IS 'Pre&ccedil;o do servi&ccedil;o em reais';
COMMENT ON COLUMN servicos.duracao IS 'Dura&ccedil;&atilde;o do servi&ccedil;o em minutos';
COMMENT ON COLUMN servicos.foto_caminho IS 'Caminho da foto do servi&ccedil;o';
COMMENT ON COLUMN servicos.codigo_barbeiro IS 'C&oacute;digo do barbeiro respons&aacute;vel (opcional)';
COMMENT ON COLUMN servicos.status IS 'Status do servi&ccedil;o (1=Ativo, 0=Inativo)';
COMMENT ON COLUMN servicos.data_cadastro IS 'Data e hora do cadastro';
COMMENT ON COLUMN servicos.data_atualizacao IS 'Data e hora da &uacute;ltima atualiza&ccedil;&atilde;o';

-- Trigger para atualizar automaticamente data_atualizacao
CREATE OR REPLACE FUNCTION atualizar_data_atualizacao_servicos()
RETURNS TRIGGER AS $$
BEGIN
    NEW.data_atualizacao = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_atualizar_servicos
BEFORE UPDATE ON servicos
FOR EACH ROW
EXECUTE FUNCTION atualizar_data_atualizacao_servicos();

-- ============================================
-- Descri&ccedil;&atilde;o dos campos:
-- ============================================
-- codigo: Identificador &uacute;nico do servi&ccedil;o (chave prim&aacute;ria auto incremento)
-- nome: Nome do servi&ccedil;o
-- descricao: Descri&ccedil;&atilde;o detalhada do servi&ccedil;o (opcional)
-- preco: Pre&ccedil;o do servi&ccedil;o em formato decimal (ex: 35.00)
-- duracao: Tempo estimado do servi&ccedil;o em minutos
-- foto_caminho: Caminho relativo ou URL da foto do servi&ccedil;o
-- codigo_barbeiro: C&oacute;digo do barbeiro vinculado ao servi&ccedil;o (opcional)
-- status: Situa&ccedil;&atilde;o do servi&ccedil;o (1=Ativo, 0=Inativo)
-- data_cadastro: Data de cria&ccedil;&atilde;o do registro
-- data_atualizacao: Data da &uacute;ltima modifica&ccedil;&atilde;o (atualizada automaticamente)

-- ============================================
-- Exemplos de uso:
-- ============================================

-- Inserir novo servi&ccedil;o (exemplo)
/*
INSERT INTO servicos (nome, descricao, preco, duracao, foto_caminho, codigo_barbeiro, status) 
VALUES (
    'Corte de Cabelo',
    'Corte profissional masculino com acabamento',
    35.00,
    30,
    'uploads/fotos/servicos/corte-cabelo.jpg',
    1, -- c&oacute;digo do barbeiro
    1  -- status: 1=Ativo
);
*/

-- Consultar todos os servi&ccedil;os ativos
/*
SELECT s.codigo, s.nome, s.descricao, s.preco, s.duracao, s.foto_caminho,
       u.nome as nome_barbeiro
FROM servicos s
LEFT JOIN usuarios u ON s.codigo_barbeiro = u.codigo
WHERE s.status = 1
ORDER BY s.nome;
*/

-- Atualizar pre&ccedil;o de um servi&ccedil;o
/*
UPDATE servicos 
SET preco = 40.00
WHERE codigo = 1;
*/

-- Inativar um servi&ccedil;o
/*
UPDATE servicos 
SET status = 0
WHERE codigo = 1;
*/

-- Consultar servi&ccedil;os por barbeiro
/*
SELECT * FROM servicos 
WHERE codigo_barbeiro = 1 AND status = 1
ORDER BY nome;
*/

-- Consultar servi&ccedil;os por faixa de pre&ccedil;o
/*
SELECT * FROM servicos 
WHERE preco BETWEEN 20.00 AND 50.00 AND status = 1
ORDER BY preco;
*/

-- Excluir um servi&ccedil;o (usar com cuidado)
/*
DELETE FROM servicos WHERE codigo = 1;
*/

-- ============================================
-- Observa&ccedil;&otilde;es:
-- ============================================
-- 1. A coluna codigo_barbeiro &eacute; opcional (NULL), permitindo servi&ccedil;os gen&eacute;ricos
-- 2. A foto_caminho segue o mesmo padr&atilde;o da tabela usuarios
-- 3. O trigger atualiza automaticamente a data_atualizacao em toda modifica&ccedil;&atilde;o
-- 4. A chave estrangeira garante integridade com a tabela usuarios
-- 5. O pre&ccedil;o usa DECIMAL(10,2) para maior precis&atilde;o monet&aacute;ria
-- 6. A dura&ccedil;&atilde;o &eacute; armazenada em minutos para facilitar c&aacute;lculos
