-- ========================================
-- MIGRATION: Sistema de Ferramentas por Topico
-- Rodar no Supabase SQL Editor APOS schema.sql
-- ========================================

-- Adicionar coluna ferramenta nas sessoes
ALTER TABLE assistente_sessoes ADD COLUMN IF NOT EXISTS ferramenta TEXT DEFAULT 'geral';
CREATE INDEX IF NOT EXISTS idx_assistente_sessoes_ferramenta ON assistente_sessoes(ferramenta);

-- Config: mapa de topicos → ferramentas (JSON)
-- Formato: {"chat_id:thread_id": "slug-ferramenta"}
INSERT INTO assistente_configs (chave, valor) VALUES
    ('mapa_topicos', '{}')
ON CONFLICT (chave) DO NOTHING;
