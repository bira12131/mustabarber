-- Tabela de Categorias
-- PostgreSQL
CREATE TABLE categoria (
    codigo SERIAL PRIMARY KEY,
    descricao VARCHAR(100) NOT NULL,
    tipo_transacao INT NOT NULL, -- 1 = Receita, 2 = Despesa
    dtnow TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Inserir categorias padr�o
-- Categorias de Receitas
INSERT INTO categoria (descricao, tipo_transacao) VALUES
('Serviços', 1),
('Venda de Produtos', 1),
('Outras Receitas', 1),
-- Categorias de Despesas
('Aluguel', 2),
('Salários', 2),
('Fornecedores', 2),
('Compra de Produtos', 2),
('Equipamentos', 2),
('Contas (Água, Luz, etc)', 2),
('Manutenção', 2),
('Marketing', 2),
('Impostos', 2),
('Outras Despesas', 2);
