-- Migration LGPD: consentimentos e solicitacoes do titular
-- Data: 23/05/2026

CREATE TABLE IF NOT EXISTS lgpd_consentimentos (
    codigo SERIAL PRIMARY KEY,
    codigo_usuario INTEGER NOT NULL REFERENCES usuarios(codigo) ON DELETE CASCADE,
    versao_termos VARCHAR(20) NOT NULL,
    versao_privacidade VARCHAR(20) NOT NULL,
    aceita_marketing BOOLEAN NOT NULL DEFAULT FALSE,
    ip_origem VARCHAR(64),
    user_agent TEXT,
    data_aceite TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_lgpd_consentimentos_usuario
    ON lgpd_consentimentos (codigo_usuario, data_aceite DESC);

CREATE TABLE IF NOT EXISTS lgpd_solicitacoes (
    codigo SERIAL PRIMARY KEY,
    codigo_usuario INTEGER NOT NULL REFERENCES usuarios(codigo) ON DELETE CASCADE,
    tipo VARCHAR(30) NOT NULL,
    descricao TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'aberta',
    data_solicitacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_lgpd_solicitacoes_usuario
    ON lgpd_solicitacoes (codigo_usuario, data_solicitacao DESC);

COMMENT ON TABLE lgpd_consentimentos IS 'Registro de aceite de termos e privacidade para compliance LGPD';
COMMENT ON TABLE lgpd_solicitacoes IS 'Solicitacoes de direitos dos titulares conforme LGPD';
