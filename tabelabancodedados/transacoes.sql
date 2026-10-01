-- Tabela de Transações Financeiras (Receitas e Despesas)
-- PostgreSQL
CREATE TABLE transacoes (
    codigo SERIAL PRIMARY KEY,
    tipo_transacao INT NOT NULL, -- 1 = Receita, 2 = Despesa
    descricao VARCHAR(255) NOT NULL,
    valor DECIMAL(10, 2) NOT NULL,
    data_transacao DATE NOT NULL,
    data_vencimento DATE NULL,
    forma_pagamento INT NOT NULL, -- 1 = Dinheiro, 2 = Cartão Débito, 3 = Cartão Crédito, 4 = PIX, 5 = Transferência
    categoria INT NOT NULL, -- Categorias: Receitas (1-Serviços, 2-Produtos, 3-Comissões, 4-Outras) | Despesas (10-Aluguel, 11-Salários, 12-Fornecedores, 13-Produtos, 14-Equipamentos, 15-Contas, 16-Manutenção, 17-Marketing, 18-Impostos, 19-Outras)
    fornecedor VARCHAR(255) NULL, -- Fornecedor ou beneficiário (usado em despesas)
    status_pagamento INT DEFAULT 1, -- 1 = Pendente, 2 = Pago (usado em despesas)
    codigo_barbeiro INT NULL, -- Código do barbeiro responsável pela transação
    observacoes TEXT NULL,
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Índices para otimização
CREATE INDEX idx_tipo_transacao ON transacoes(tipo_transacao);
CREATE INDEX idx_data_transacao ON transacoes(data_transacao);
CREATE INDEX idx_categoria ON transacoes(categoria);
CREATE INDEX idx_codigo_barbeiro ON transacoes(codigo_barbeiro);
CREATE INDEX idx_forma_pagamento ON transacoes(forma_pagamento);

-- Trigger para atualizar data_atualizacao automaticamente
CREATE OR REPLACE FUNCTION atualizar_data_atualizacao()
RETURNS TRIGGER AS $$
BEGIN
    NEW.data_atualizacao = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_atualizar_data_atualizacao
BEFORE UPDATE ON transacoes
FOR EACH ROW
EXECUTE FUNCTION atualizar_data_atualizacao();

-- Comentários sobre a estrutura:
-- tipo_transacao: 1 = Receita, 2 = Despesa
-- forma_pagamento: 1 = Dinheiro, 2 = Cartão Débito, 3 = Cartão Crédito, 4 = PIX, 5 = Transferência
-- categoria (Receitas): 1 = Serviços, 2 = Produtos, 3 = Comissões, 4 = Outras
-- categoria (Despesas): 10 = Aluguel, 11 = Salários, 12 = Fornecedores, 13 = Produtos, 14 = Equipamentos, 15 = Contas, 16 = Manutenção, 17 = Marketing, 18 = Impostos, 19 = Outras
-- status_pagamento: 1 = Pendente, 2 = Pago
