-- Tabela de Produtos
CREATE TABLE IF NOT EXISTS produtos (
    codigo SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    marca VARCHAR(100),
    preco DECIMAL(10, 2) NOT NULL,
    quantidade_estoque INTEGER NOT NULL DEFAULT 0,
    foto_caminho VARCHAR(500),
    codigo_barbeiro INTEGER NOT NULL,
    status INTEGER NOT NULL DEFAULT 1 CHECK (status IN (0, 1)),
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (codigo_barbeiro) REFERENCES usuarios(codigo) ON DELETE CASCADE
);

-- Comentários explicativos
COMMENT ON TABLE produtos IS 'Tabela para armazenar produtos da barbearia';
COMMENT ON COLUMN produtos.codigo IS 'Código único do produto (chave primária)';
COMMENT ON COLUMN produtos.nome IS 'Nome do produto';
COMMENT ON COLUMN produtos.marca IS 'Marca do produto';
COMMENT ON COLUMN produtos.preco IS 'Preço do produto';
COMMENT ON COLUMN produtos.quantidade_estoque IS 'Quantidade disponível em estoque';
COMMENT ON COLUMN produtos.foto_caminho IS 'Caminho da foto do produto';
COMMENT ON COLUMN produtos.status IS 'Status do produto: 0 = Inativo, 1 = Ativo';
COMMENT ON COLUMN produtos.data_cadastro IS 'Data e hora do cadastro do produto';
COMMENT ON COLUMN produtos.data_atualizacao IS 'Data e hora da última atualização';

-- Trigger para atualizar automaticamente data_atualizacao
CREATE OR REPLACE FUNCTION atualizar_data_produtos()
RETURNS TRIGGER AS $$
BEGIN
    NEW.data_atualizacao = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_atualizar_data_produtos
BEFORE UPDATE ON produtos
FOR EACH ROW
EXECUTE FUNCTION atualizar_data_produtos();

-- Índices para melhorar performance
CREATE INDEX idx_produtos_status ON produtos(status);
CREATE INDEX idx_produtos_nome ON produtos(nome);
CREATE INDEX idx_produtos_marca ON produtos(marca);
