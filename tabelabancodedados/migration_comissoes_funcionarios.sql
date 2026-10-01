-- Tabela de comissoes por funcionario da barbearia
CREATE TABLE IF NOT EXISTS comissoes_funcionarios (
    codigo SERIAL PRIMARY KEY,
    codigo_barbearia INTEGER NOT NULL,
    codigo_funcionario INTEGER NOT NULL,
    percentual_servicos NUMERIC(5,2) NOT NULL DEFAULT 0,
    percentual_produtos NUMERIC(5,2) NOT NULL DEFAULT 0,
    data_atualizacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_comissao_funcionario UNIQUE (codigo_barbearia, codigo_funcionario),
    CONSTRAINT ck_percentual_servicos CHECK (percentual_servicos >= 0 AND percentual_servicos <= 100),
    CONSTRAINT ck_percentual_produtos CHECK (percentual_produtos >= 0 AND percentual_produtos <= 100),
    CONSTRAINT fk_comissao_barbearia FOREIGN KEY (codigo_barbearia) REFERENCES barbearia(codigo) ON DELETE CASCADE,
    CONSTRAINT fk_comissao_funcionario FOREIGN KEY (codigo_funcionario) REFERENCES usuarios(codigo) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_comissao_barbearia ON comissoes_funcionarios(codigo_barbearia);
CREATE INDEX IF NOT EXISTS idx_comissao_funcionario ON comissoes_funcionarios(codigo_funcionario);
