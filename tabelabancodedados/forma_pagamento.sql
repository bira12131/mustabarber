-- Tabela de Formas de Pagamento
-- PostgreSQL
CREATE TABLE forma_pagamento (
    codigo SERIAL PRIMARY KEY,
    descricao VARCHAR(100) NOT NULL,
    dtnow TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Inserir formas de pagamento padrão
INSERT INTO forma_pagamento (descricao) VALUES
('Dinheiro'),
('Cartão de Débito'),
('Cartão de Crédito'),
('PIX'),
('Transferência Bancária');
