-- ============================================================
-- Migration: Corrigir constraint única de agendamentos
-- Sistema: MustaBarber
-- Data: 08/05/2026
-- Problema: A constraint unique_agendamento bloqueava a inserção
-- de um novo agendamento no mesmo horário de um agendamento
-- cancelado/concluído/falta, pois era um índice global sem filtro
-- de status.
-- Solução: Substituir pelo índice parcial que só se aplica a
-- agendamentos ativos (status NOT IN (4=concluído, 5=cancelado, 6=falta)).
-- ============================================================

-- 1. Remover a constraint antiga
ALTER TABLE agendamentos
    DROP CONSTRAINT IF EXISTS unique_agendamento;

-- 2. Criar índice único PARCIAL (apenas para agendamentos ativos)
CREATE UNIQUE INDEX IF NOT EXISTS unique_agendamento_ativo
    ON agendamentos (codigo_barbeiro, data_agendamento, horario)
    WHERE codigo_status NOT IN (4, 5, 6);

COMMENT ON INDEX unique_agendamento_ativo
    IS 'Impede horário duplo para o mesmo barbeiro, ignorando agendamentos encerrados (concluído, cancelado, falta)';
