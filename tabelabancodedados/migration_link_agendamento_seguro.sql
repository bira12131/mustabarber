-- Tabela para links publicos de agendamento com token seguro
-- Evita expor codigo de barbeiro/barbearia na URL

CREATE TABLE IF NOT EXISTS links_agendamento_publico (
    codigo SERIAL PRIMARY KEY,
    token_hash VARCHAR(64) UNIQUE NOT NULL,
    tipo VARCHAR(20) NOT NULL,
    codigo_barbeiro INTEGER,
    codigo_barbearia INTEGER,
    criado_por INTEGER NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    expira_em TIMESTAMP NOT NULL,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    ultimo_acesso_em TIMESTAMP,
    CONSTRAINT chk_links_agendamento_tipo
        CHECK (tipo IN ('barbeiro', 'barbearia'))
);

CREATE INDEX IF NOT EXISTS idx_links_agendamento_publico_expira
    ON links_agendamento_publico (expira_em);

CREATE INDEX IF NOT EXISTS idx_links_agendamento_publico_ativo
    ON links_agendamento_publico (ativo);
