-- ============================================================
-- Migration: Endereco fiscal, cartao tokenizado e notas fiscais
-- Sistema: MustaBarber
-- Data: 08/06/2026
-- ============================================================

BEGIN;

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS endereco_cep VARCHAR(9);
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS endereco_logradouro VARCHAR(150);
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS endereco_numero VARCHAR(20);
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS endereco_complemento VARCHAR(120);
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS endereco_bairro VARCHAR(120);
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS endereco_cidade VARCHAR(120);
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS endereco_estado CHAR(2);

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS pagamento_auto_cartao BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS asaas_customer_id VARCHAR(40);
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS asaas_cartao_token VARCHAR(255);
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS cartao_bandeira VARCHAR(30);
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS cartao_final VARCHAR(4);
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS cartao_titular VARCHAR(150);
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS cartao_token_atualizado_em TIMESTAMP;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS pagamento_auto_aceite_em TIMESTAMP;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS pagamento_auto_aceite_ip VARCHAR(45);

CREATE INDEX IF NOT EXISTS idx_usuarios_asaas_customer_id ON usuarios(asaas_customer_id);

CREATE TABLE IF NOT EXISTS auditoria_pagamento_cartao (
    codigo SERIAL PRIMARY KEY,
    codigo_usuario INT NOT NULL,
    acao VARCHAR(60) NOT NULL,
    sucesso BOOLEAN NOT NULL DEFAULT FALSE,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(250) NULL,
    detalhes JSONB NULL,
    data_cadastro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_aud_cartao_usuario FOREIGN KEY (codigo_usuario) REFERENCES usuarios(codigo) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_aud_cartao_usuario ON auditoria_pagamento_cartao(codigo_usuario);
CREATE INDEX IF NOT EXISTS idx_aud_cartao_data ON auditoria_pagamento_cartao(data_cadastro);

CREATE TABLE IF NOT EXISTS notas_fiscais_pagamentos (
    codigo SERIAL PRIMARY KEY,
    codigo_barbeiro INT NOT NULL,
    codigo_assinatura INT NULL,
    asaas_payment_id VARCHAR(60) NOT NULL,
    asaas_invoice_id VARCHAR(60) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pendente',
    valor DECIMAL(10,2) NULL,
    numero_nota VARCHAR(50) NULL,
    url_nota TEXT NULL,
    url_xml TEXT NULL,
    url_pdf TEXT NULL,
    mensagem TEXT NULL,
    payload_envio JSONB NULL,
    payload_retorno JSONB NULL,
    data_emissao TIMESTAMP NULL,
    data_cadastro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_nf_barbeiro FOREIGN KEY (codigo_barbeiro) REFERENCES usuarios(codigo) ON DELETE CASCADE,
    CONSTRAINT fk_nf_assinatura FOREIGN KEY (codigo_assinatura) REFERENCES assinaturas(codigo) ON DELETE SET NULL,
    CONSTRAINT uk_nf_payment UNIQUE (asaas_payment_id)
);

CREATE INDEX IF NOT EXISTS idx_nf_barbeiro ON notas_fiscais_pagamentos(codigo_barbeiro);
CREATE INDEX IF NOT EXISTS idx_nf_status ON notas_fiscais_pagamentos(status);

CREATE OR REPLACE FUNCTION atualizar_data_notas_fiscais_pagamentos()
RETURNS TRIGGER AS $$
BEGIN
    NEW.data_atualizacao = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trigger_atualizar_notas_fiscais_pagamentos ON notas_fiscais_pagamentos;
CREATE TRIGGER trigger_atualizar_notas_fiscais_pagamentos
BEFORE UPDATE ON notas_fiscais_pagamentos
FOR EACH ROW
EXECUTE FUNCTION atualizar_data_notas_fiscais_pagamentos();

COMMIT;
