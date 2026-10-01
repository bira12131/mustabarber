-- ============================================
-- Migration: instancia WhatsApp personalizada por barbeiro
-- Data: 15/05/2026
-- ============================================

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS whatsapp_instancia_nome VARCHAR(180) NULL,
    ADD COLUMN IF NOT EXISTS whatsapp_instancia_status VARCHAR(40) NOT NULL DEFAULT 'fallback',
    ADD COLUMN IF NOT EXISTS whatsapp_instancia_conectada BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS whatsapp_instancia_atualizada_em TIMESTAMP NULL;

CREATE INDEX IF NOT EXISTS idx_usuarios_whatsapp_instancia_nome
    ON usuarios(whatsapp_instancia_nome);

CREATE INDEX IF NOT EXISTS idx_usuarios_whatsapp_instancia_conectada
    ON usuarios(whatsapp_instancia_conectada);

COMMENT ON COLUMN usuarios.whatsapp_instancia_nome IS 'Nome da instancia exclusiva do barbeiro na Evolution API';
COMMENT ON COLUMN usuarios.whatsapp_instancia_status IS 'Estado da conexao da instancia personalizada (open, connecting, disconnected, fallback)';
COMMENT ON COLUMN usuarios.whatsapp_instancia_conectada IS 'Indica se a instancia exclusiva do barbeiro esta conectada';
COMMENT ON COLUMN usuarios.whatsapp_instancia_atualizada_em IS 'Ultima atualizacao do estado da instancia personalizada';
