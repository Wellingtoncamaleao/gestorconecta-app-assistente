# Well-Dev Assistente - IA Pessoal do Wellington

## O que e
Assistente IA pessoal que roda no Easypanel (Docker), controlado via Telegram e WhatsApp.
Usa a assinatura Claude Max ($1100/mes) via OAuth — zero custo extra de tokens.
Substitui o OpenClaw com controle total e integracao com os sistemas existentes.

## URLs e Acessos
- **Dominio**: assistente.gestorconecta.com.br
- **Easypanel**: easypanel.gestorconecta.com.br → projeto `well-dev-assistente` → servico `assistente-vscode`
- **GitHub**: github.com/Wellingtoncamaleao/gestorconecta-app-assistente (branch master)
- **Telegram Bot**: @WellDevAssistenteBot (token: 8657545298:...)
- **Supabase**: qmzrfwqopjaxruiegzsn.supabase.co (compartilhado, prefixo `assistente_`)

## Arquitetura

```
Telegram / WhatsApp (Evolution API)
    ↓
Nginx (HTTPS, Easypanel gera cert)
    ↓
PHP Webhooks (recebe, valida whitelist, detecta ferramenta, enfileira)
    ↓
Fila JSON no disco (/var/assistente/fila/)
    ↓
Worker Node.js (monitora fila a cada 2s, roda como claude-user)
    ↓
Claude Code CLI (--dangerously-skip-permissions, prompt da ferramenta)
    ↓
Resposta → PHP envia de volta pro canal
    ↓
Supabase (historico, sessoes, memoria persistente)
```

## Container Docker

Um container com 3 processos (supervisord):
- **Nginx** — reverse proxy, serve web panel
- **PHP-FPM 8.4** — webhooks de entrada + envio de respostas
- **Node.js 22** — worker que executa Claude Code CLI

Usuario nao-root `claude-user` para o worker (necessario para --dangerously-skip-permissions).
Entrypoint.sh corrige permissoes dos volumes a cada boot.

## Volumes Easypanel (persistentes)
| Volume | Mount Path | Conteudo |
|--------|-----------|----------|
| claude-auth | /home/claude-user/.claude | Auth OAuth + sessions Claude Code |
| assistente-data | /var/assistente | Fila, dedup, logs, sessoes |

## Variaveis de Ambiente (Easypanel)
```
SUPABASE_URL=https://qmzrfwqopjaxruiegzsn.supabase.co
SUPABASE_SERVICE_KEY=eyJhbGci...
TELEGRAM_BOT_TOKEN=8657545298:AAHK3rqeAWR9-fgrL_n6tabh_akvgMTgGvM
TELEGRAM_CHAT_IDS=["8584895842"]
EVOLUTION_API_URL=https://evolution.gestorconecta.com.br
EVOLUTION_API_KEY=(pendente)
EVOLUTION_INSTANCIA=assistente
WHATSAPP_CHAT_IDS=(pendente)
FACEBOOK_APP_TOKEN=APP_ID|APP_SECRET
```

## Banco de Dados (Supabase)

5 tabelas com prefixo `assistente_`:
| Tabela | Funcao |
|--------|--------|
| assistente_sessoes | Uma por conversa. Guarda claude_session_id + ferramenta |
| assistente_mensagens | Historico completo (recebidas + enviadas) |
| assistente_memoria | Fatos persistentes entre sessoes (categoria/chave/valor) |
| assistente_configs | Configs (tokens, chat_ids, mapa_topicos, etc.) |
| assistente_logs | Logs do sistema |

Schema: sql/schema.sql
Migration ferramentas: sql/migration-ferramentas.sql

## Estrutura de Arquivos
```
D:/Well-Dev-Assistente/
├── .claude/CLAUDE.md
├── .gitignore
├── Dockerfile
├── entrypoint.sh              (corrige permissoes volumes no boot)
├── nginx.conf
├── supervisord.conf
├── api/
│   ├── config.php             (le env vars, nunca commitado)
│   ├── functions.php          (helpers: supabase, telegram, whatsapp, fila, ferramentas)
│   ├── webhook-telegram.php   (recebe, valida, detecta ferramenta, enfileira)
│   ├── webhook-whatsapp.php   (pendente)
│   ├── enviar-telegram.php    (worker chama via HTTP local)
│   └── enviar-whatsapp.php    (pendente)
├── tools/
│   └── ferramentas.json       (registro de ferramentas: slug, prompt, max_turns, timeout)
├── worker/
│   ├── package.json
│   └── fila-watcher.js        (monitora fila, carrega ferramenta, executa claude)
├── web/
│   └── index.html             (painel web - pendente)
├── sql/
│   ├── schema.sql
│   └── migration-ferramentas.sql
├── prompts/
│   ├── sistema-padrao.txt           (prompt geral)
│   ├── ferramenta-instagram-replicar.txt
│   └── ferramenta-youtube-resumo.txt
└── logs/
```

## Sistema de Ferramentas por Topico

### Conceito
Cada topico do Telegram pode ser mapeado a uma **ferramenta** (tool) com prompt e configuracao proprios.
Topicos sem mapeamento usam a ferramenta "geral" (sistema-padrao.txt).

### Registro de Ferramentas
Arquivo `tools/ferramentas.json` define as ferramentas disponiveis:
```json
{
    "geral": { "prompt": "sistema-padrao.txt", "max_turns": 10, "timeout_ms": 300000 },
    "instagram-replicar": { "prompt": "ferramenta-instagram-replicar.txt", "max_turns": 15 },
    "youtube-resumo": { "prompt": "ferramenta-youtube-resumo.txt", "max_turns": 10 }
}
```

### Mapeamento Topico → Ferramenta
Armazenado na tabela `assistente_configs`, chave `mapa_topicos`:
```json
{"chat_id:thread_id": "slug-ferramenta"}
```

### Comandos Telegram
| Comando | Funcao |
|---------|--------|
| `/ferramentas` | Lista ferramentas disponiveis |
| `/mapear <slug>` | Mapeia o topico atual a uma ferramenta |
| `/desmapear` | Remove mapeamento do topico (volta para geral) |
| `/topicos` | Mostra todos os mapeamentos ativos |

### Fluxo
1. Webhook recebe mensagem com chat_id + thread_id
2. `detectarFerramenta()` busca `mapa_topicos` no Supabase
3. Se encontra mapeamento → usa prompt/config da ferramenta
4. Se nao encontra → usa "geral"
5. Worker recebe `ferramenta` no item da fila
6. Carrega prompt especifico de `ferramentas.json`
7. Passa max_turns e timeout_ms customizados ao Claude CLI

### Como Adicionar Nova Ferramenta
1. Criar prompt em `prompts/ferramenta-{slug}.txt`
2. Registrar em `tools/ferramentas.json`
3. Commit + push → Easypanel rebuilda
4. No Telegram, criar topico e usar `/mapear slug`

### Scraper Instagram (ferramenta instagram-replicar)
Quando usuario envia link de Instagram no topico mapeado para `instagram-replicar`:
1. PHP detecta link via regex (post/reel/tv)
2. Busca dados via Facebook Graph API oEmbed (precisa `FACEBOOK_APP_TOKEN`)
3. Fallback: scraping meta tags OG da pagina
4. Enriquece mensagem com autor, legenda, hashtags, thumbnail
5. Claude recebe dados formatados + mensagem original
Requer: Facebook Developer App (gratis) → App ID|App Secret como env var

## Fluxo de Mensagem (Telegram)

1. Usuario envia texto no Telegram
2. webhook-telegram.php recebe, valida chat_id contra whitelist
3. Deduplicacao por update_id (arquivo em /var/assistente/dedup/)
4. Detecta comandos internos (/ferramentas, /mapear, /desmapear, /topicos)
5. Detecta ferramenta pelo mapeamento do topico
6. Envia "digitando..." ao usuario
7. Busca/cria sessao no Supabase
8. Salva mensagem recebida no Supabase
9. Cria arquivo JSON na fila com campo `ferramenta`
10. Responde 200 OK ao Telegram (< 1s)
11. Worker detecta novo arquivo (poll 2s)
12. Carrega config da ferramenta (prompt, max_turns, timeout)
13. Monta contexto: prompt da ferramenta + memoria + historico
14. Executa: `claude --dangerously-skip-permissions --output-format json --max-turns N`
15. Se --resume falha, tenta sem
16. Extrai texto do resultado JSON (trata errorMaxTurns)
17. Envia resposta ao Telegram (divide se > 4096 chars)
18. Salva resposta + atualiza sessao (com ferramenta) no Supabase
19. Move arquivo para /processados/

## Telegram Topics

- Suporta grupos com Topics (Forum mode)
- Cada topico = sessao isolada (chat_id:thread_id)
- Cada topico pode ter ferramenta propria (via /mapear)
- Chat direto = sessao por chat_id, ferramenta "geral"
- Whitelist verifica tanto chat_id quanto user_id (from.id)
- thread_id passado em todas respostas para reply no topico correto

## Detalhes Tecnicos Importantes

### Claude Code CLI
- Roda como `claude-user` (nao-root) — obrigatorio para --dangerously-skip-permissions
- Auth via OAuth (claude /login interativo no container)
- Token expira em ~8h — precisa re-login periodico
- stdin pipe para enviar prompt (NAO usar argumento -p com texto longo — causa timeout)
- Saida JSON: campo `result` contem texto, `sessionId` (camelCase), `usage.inputTokens` (camelCase)
- Quando `result` e vazio (errorMaxTurns): retornar mensagem amigavel, NAO o JSON bruto

### Permissoes
- /var/assistente: claude-user:www-data (775) — PHP escreve na fila, worker le
- /home/claude-user/.claude: claude-user:claude-user — auth e sessions
- /var/www/html: www-data:www-data — PHP webhooks + tools/
- /var/www/worker: claude-user:claude-user — worker Node.js

### Supervisord
- Nao tem socket configurado (supervisorctl nao funciona)
- Para reiniciar worker: `pkill -f fila-watcher.js` (procps instalado)

## Problemas Resolvidos

1. **execFile com texto longo → timeout 2min**: Reescrito para spawn + stdin pipe
2. **Mensagens duplicadas do Telegram**: Deduplicacao por update_id em /var/assistente/dedup/
3. **JSON bruto no Telegram**: Fix parsing — trata errorMaxTurns, campos camelCase
4. **--dangerously-skip-permissions bloqueado como root**: Criado claude-user nao-root
5. **PHP nao escreve na fila**: /var/assistente precisa grupo www-data com 775
6. **--resume com sessao antiga**: Retry sem --resume quando "No conversation found"
7. **Volumes com dono root**: Entrypoint.sh corrige permissoes a cada boot
8. **Config backup**: Entrypoint restaura .claude.json do backup automaticamente

## Custos
| Item | Custo |
|------|-------|
| Claude Max | Ja pago ($1100/mes) |
| Easypanel | Ja pago |
| Telegram Bot | Gratis |
| Evolution API | Ja tem |
| Supabase | Ja tem |
| **Total extra** | **R$0** |
