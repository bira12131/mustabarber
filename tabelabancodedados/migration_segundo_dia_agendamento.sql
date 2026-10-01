-- Migration: Suporte a segundo dia de agendamento por semana nos planos
-- Usado quando o cliente escolhe mais sessões do que semanas disponíveis

ALTER TABLE solicitacoes_plano_cliente
    ADD COLUMN IF NOT EXISTS dia_semana_2        VARCHAR(10) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS horario_preferido_2 TIME        DEFAULT NULL;

COMMENT ON COLUMN solicitacoes_plano_cliente.dia_semana_2        IS 'Segundo dia preferido (necessário quando total sessões > semanas)';
COMMENT ON COLUMN solicitacoes_plano_cliente.horario_preferido_2 IS 'Horário preferido no segundo dia';
