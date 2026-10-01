-- Migration: Adiciona coluna de bloqueio na tabela de clientes
-- Data: 05/05/2026
-- Descrição: Permite que o barbeiro bloqueie um cliente de realizar agendamentos

ALTER TABLE clientes
    ADD COLUMN IF NOT EXISTS bloqueado BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS motivo_bloqueio TEXT DEFAULT NULL;
