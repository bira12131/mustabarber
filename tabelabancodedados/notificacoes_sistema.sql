-- ============================================================
-- Tabela: notificacoes_sistema
-- Sistema: MustaBarber
-- Descrição: Notificações geradas pelo sistema para cada usuário
--            (boas-vindas, informações de cobrança, avisos, etc.)
-- ============================================================

CREATE TABLE IF NOT EXISTS notificacoes_sistema (
    codigo        SERIAL PRIMARY KEY,
    codigo_usuario INTEGER NOT NULL,

    -- Tipo: info | cobranca_boas_vindas | aviso | sucesso
    tipo          VARCHAR(50) NOT NULL DEFAULT 'info',

    titulo        VARCHAR(200) NOT NULL,
    mensagem      TEXT NOT NULL,

    lida          BOOLEAN NOT NULL DEFAULT FALSE,

    data_cadastro TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_notif_sistema_usuario
        FOREIGN KEY (codigo_usuario) REFERENCES usuarios(codigo) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_notif_sistema_usuario
    ON notificacoes_sistema (codigo_usuario, lida);

COMMENT ON TABLE  notificacoes_sistema                IS 'Notificações geradas pelo sistema para cada barbeiro/usuário';
COMMENT ON COLUMN notificacoes_sistema.tipo           IS 'info | cobranca_boas_vindas | aviso | sucesso';
COMMENT ON COLUMN notificacoes_sistema.lida           IS 'FALSE = não lida (conta no badge), TRUE = lida';
