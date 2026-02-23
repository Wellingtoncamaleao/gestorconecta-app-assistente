-- ========================================
-- ASSISTENTE IA PESSOAL - Schema SQL
-- Prefixo: assistente_ (Supabase compartilhado)
-- Rodar no Supabase SQL Editor
-- ========================================

-- SESSOES (uma por conversa com Claude Code)
CREATE TABLE IF NOT EXISTS assistente_sessoes (
    id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    canal TEXT NOT NULL,
    chat_id TEXT NOT NULL,
    claude_session_id TEXT,
    titulo TEXT,
    status TEXT DEFAULT 'ativa',
    total_tokens_entrada BIGINT DEFAULT 0,
    total_tokens_saida BIGINT DEFAULT 0,
    total_mensagens INT DEFAULT 0,
    ultima_mensagem_em TIMESTAMPTZ,
    criado_em TIMESTAMPTZ DEFAULT now(),
    atualizado_em TIMESTAMPTZ DEFAULT now()
);

-- MENSAGENS (historico completo)
CREATE TABLE IF NOT EXISTS assistente_mensagens (
    id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    sessao_id UUID REFERENCES assistente_sessoes(id) ON DELETE CASCADE,
    canal TEXT NOT NULL,
    chat_id TEXT NOT NULL,
    direcao TEXT NOT NULL,
    conteudo TEXT NOT NULL,
    tipo TEXT DEFAULT 'texto',
    tokens_entrada INT DEFAULT 0,
    tokens_saida INT DEFAULT 0,
    tempo_resposta_ms INT,
    modelo TEXT,
    metadata JSONB DEFAULT '{}',
    criado_em TIMESTAMPTZ DEFAULT now()
);

-- MEMORIA (fatos persistentes entre sessoes)
CREATE TABLE IF NOT EXISTS assistente_memoria (
    id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    categoria TEXT NOT NULL,
    chave TEXT NOT NULL,
    valor TEXT NOT NULL,
    relevancia FLOAT DEFAULT 1.0,
    fonte TEXT,
    criado_em TIMESTAMPTZ DEFAULT now(),
    atualizado_em TIMESTAMPTZ DEFAULT now(),
    UNIQUE(categoria, chave)
);

-- CONFIGS
CREATE TABLE IF NOT EXISTS assistente_configs (
    chave TEXT PRIMARY KEY,
    valor TEXT,
    atualizado_em TIMESTAMPTZ DEFAULT now()
);

-- LOGS
CREATE TABLE IF NOT EXISTS assistente_logs (
    id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    nivel TEXT NOT NULL,
    componente TEXT NOT NULL,
    mensagem TEXT NOT NULL,
    dados JSONB DEFAULT '{}',
    criado_em TIMESTAMPTZ DEFAULT now()
);

-- ========================================
-- INDICES
-- ========================================
CREATE INDEX IF NOT EXISTS idx_assistente_sessoes_canal ON assistente_sessoes(canal, chat_id);
CREATE INDEX IF NOT EXISTS idx_assistente_sessoes_status ON assistente_sessoes(status);
CREATE INDEX IF NOT EXISTS idx_assistente_sessoes_ultima ON assistente_sessoes(ultima_mensagem_em DESC);
CREATE INDEX IF NOT EXISTS idx_assistente_mensagens_sessao ON assistente_mensagens(sessao_id);
CREATE INDEX IF NOT EXISTS idx_assistente_mensagens_canal ON assistente_mensagens(canal, chat_id);
CREATE INDEX IF NOT EXISTS idx_assistente_mensagens_criado ON assistente_mensagens(criado_em DESC);
CREATE INDEX IF NOT EXISTS idx_assistente_memoria_categoria ON assistente_memoria(categoria);
CREATE INDEX IF NOT EXISTS idx_assistente_logs_criado ON assistente_logs(criado_em DESC);

-- ========================================
-- RLS (acesso via service_role — usuario unico)
-- ========================================
ALTER TABLE assistente_sessoes ENABLE ROW LEVEL SECURITY;
ALTER TABLE assistente_mensagens ENABLE ROW LEVEL SECURITY;
ALTER TABLE assistente_memoria ENABLE ROW LEVEL SECURITY;
ALTER TABLE assistente_configs ENABLE ROW LEVEL SECURITY;
ALTER TABLE assistente_logs ENABLE ROW LEVEL SECURITY;

CREATE POLICY "assistente_sessoes_all" ON assistente_sessoes FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "assistente_mensagens_all" ON assistente_mensagens FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "assistente_memoria_all" ON assistente_memoria FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "assistente_configs_all" ON assistente_configs FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "assistente_logs_all" ON assistente_logs FOR ALL USING (true) WITH CHECK (true);

-- ========================================
-- SEED: CONFIGS
-- ========================================
INSERT INTO assistente_configs (chave, valor) VALUES
    ('modelo_padrao', 'opus'),
    ('max_turns', '5'),
    ('timeout_cli_ms', '120000'),
    ('telegram_bot_token', ''),
    ('telegram_chat_ids_permitidos', '[]'),
    ('whatsapp_chat_ids_permitidos', '[]'),
    ('evolution_instancia', ''),
    ('system_prompt_arquivo', 'prompts/sistema-padrao.txt')
ON CONFLICT (chave) DO NOTHING;

-- ========================================
-- SEED: MEMORIA INICIAL (migrada do OpenClaw)
-- ========================================
INSERT INTO assistente_memoria (categoria, chave, valor) VALUES
    ('projeto', 'control', 'ERP multi-tenant em D:/GESTORCONECTA/APP/control — prioridade alta'),
    ('projeto', 'loyal', 'Fidelizacao WhatsApp em D:/GESTORCONECTA/APP/loyal'),
    ('projeto', 'recupera', 'Recuperacao vendas WhatsApp em D:/GESTORCONECTA/APP/recupera — pendente setup'),
    ('projeto', 'prontuario', 'Prontuario eletronico em producao em D:/GESTORCONECTA/MED/prontuario'),
    ('projeto', 'darkflow', 'Canais YouTube automatizados em D:/DARKFLOW — 4 canais'),
    ('projeto', 'painel', 'Central de inteligencia em D:/GESTORCONECTA/APP/painel'),
    ('projeto', 'educacao360', 'Gestao escolar Laravel em D:/GESTORCONECTA/EDU/educacao360 — pendente deploy'),
    ('projeto', 'camaleao-painel', 'Sistema central Camaleao em D:/CAMALEAO/painel — fase 1 funcional'),
    ('projeto', 'camaleao-camisas', 'Sistema legado Laravel+GraphQL em D:/CAMALEAO/camisas — modernizacao'),
    ('preferencia', 'idioma', 'Portugues brasileiro sempre — codigo e comunicacao'),
    ('preferencia', 'stack', 'Vanilla JS ES6+ global scope + CSS puro + Supabase + PHP. NUNCA frameworks frontend.'),
    ('preferencia', 'hospedagem', 'HostGator cPanel para producao, Easypanel para Docker'),
    ('preferencia', 'comunicacao', 'Direto, sem enrolacao, max 10 linhas, 1 pergunta por vez'),
    ('licao', 'heartbeat-custo', 'Heartbeat frequente com contexto grande = desastre de custo. Carregar max 8KB por sessao.'),
    ('licao', 'telegram-topics', 'Telegram Topics = sessoes isoladas (recomendado). WhatsApp = contexto sobrecarregado.'),
    ('licao', 'supabase-cdn', 'Sempre fixar @2.39.7, usar fetch() direto em file://, flowType implicit, SECURITY DEFINER'),
    ('licao', 'xss-onclick', 'Nunca interpolar dados em onclick, usar data-* attributes + this.dataset'),
    ('decisao', 'modelo-assistente', 'Claude Max via OAuth (assinatura), nao API por token. Custo zero extra.')
ON CONFLICT (categoria, chave) DO UPDATE SET valor = EXCLUDED.valor;