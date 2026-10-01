-- Migration: Horário de almoço na configuração de horários por dia da semana
-- Quando ativo, o intervalo definido fica bloqueado para novos agendamentos

ALTER TABLE horarios_configuracao
    ADD COLUMN IF NOT EXISTS almoco_ativo  BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS almoco_inicio TIME    DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS almoco_fim    TIME    DEFAULT NULL;

COMMENT ON COLUMN horarios_configuracao.almoco_ativo  IS 'Se true, bloqueia o intervalo de almoço para agendamentos neste dia';
COMMENT ON COLUMN horarios_configuracao.almoco_inicio IS 'Início do horário de almoço';
COMMENT ON COLUMN horarios_configuracao.almoco_fim    IS 'Fim do horário de almoço';
