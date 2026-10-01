-- ============================================================
-- Tabela: convites
-- Sistema: MustaBarber
-- Descrição: Gerencia convites de donos de barbearia para
--            barbeiros entrarem na equipe
-- ============================================================

CREATE TABLE IF NOT EXISTS convites (
    codigo          SERIAL PRIMARY KEY,
    codigo_barbearia       INTEGER NOT NULL,
    codigo_usuario_dono    INTEGER NOT NULL,
    codigo_usuario_convidado INTEGER NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'pendente',
    -- pendente | aceito | recusado | cancelado | saiu
    data_convite    TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    data_resposta   TIMESTAMP WITH TIME ZONE,

    CONSTRAINT fk_convites_barbearia  FOREIGN KEY (codigo_barbearia)        REFERENCES barbearia(codigo),
    CONSTRAINT fk_convites_dono       FOREIGN KEY (codigo_usuario_dono)     REFERENCES usuarios(codigo),
    CONSTRAINT fk_convites_convidado  FOREIGN KEY (codigo_usuario_convidado) REFERENCES usuarios(codigo),
    CONSTRAINT chk_convites_status    CHECK (status IN ('pendente','aceito','recusado','cancelado','saiu'))
);

-- Garante que um convidado só tenha um convite pendente por barbearia por vez
CREATE UNIQUE INDEX IF NOT EXISTS uq_convite_pendente
    ON convites (codigo_barbearia, codigo_usuario_convidado)
    WHERE status = 'pendente';

CREATE INDEX IF NOT EXISTS idx_convites_convidado
    ON convites (codigo_usuario_convidado, status);

CREATE INDEX IF NOT EXISTS idx_convites_barbearia
    ON convites (codigo_barbearia, status);
