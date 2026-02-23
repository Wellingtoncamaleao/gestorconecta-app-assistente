# Assistente IA Pessoal - Well-dev

## O que e
Bot assistente IA pessoal rodando no Easypanel (Docker), controlado via Telegram e WhatsApp. Usa Claude Code CLI autenticado via OAuth (assinatura Max).

## Arquitetura
- **Container Docker**: Nginx + PHP-FPM 8.4 + Node.js 22 + Claude Code CLI
- **Webhooks PHP**: Recebem mensagens, validam, enfileiram
- **Worker Node.js**: Monitora fila, executa `claude -p`, devolve resposta
- **Supabase**: Historico, sessoes, memoria persistente (prefixo `assistente_`)
- **Dominio**: assistente.gestorconecta.com.br

## Estrutura
```
api/            PHP webhooks + helpers
worker/         Node.js que executa Claude Code CLI
web/            Painel de historico (Vanilla JS)
sql/            Schema Supabase
prompts/        System prompt + contexto
```

## Fluxo
1. Mensagem chega (Telegram/WhatsApp)
2. PHP valida whitelist, salva, enfileira JSON
3. Worker detecta, carrega contexto + memoria
4. Executa `claude -p "msg" --resume "session" --output-format json`
5. Envia resposta ao canal via PHP
6. Salva tudo no Supabase

## Seguranca
- Whitelist de chat_ids (hardcoded em config.php)
- OAuth token via env var CLAUDE_CODE_OAUTH_TOKEN
- api/config.php NUNCA commitado
- HTTPS obrigatorio (Easypanel + Let's Encrypt)

## Deploy
- Easypanel: criar servico Docker apontando para este repo
- Env vars: CLAUDE_CODE_OAUTH_TOKEN, SUPABASE_URL, SUPABASE_SERVICE_KEY
- Rodar sql/schema.sql no Supabase SQL Editor
- Configurar webhooks: Telegram (@BotFather) + Evolution API