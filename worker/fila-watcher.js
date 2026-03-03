/**
 * ASSISTENTE - Worker Node.js
 * Monitora a fila de mensagens e executa Claude Code CLI
 * Suporta ferramentas por topico (prompt + config customizados)
 */

const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const http = require('http');
const { Pool } = require('pg');

// Diretorios
const FILA_DIR = '/var/assistente/fila';
const PROCESSADOS_DIR = '/var/assistente/fila/processados';
const PROMPTS_DIR = '/var/www/html/prompts';
const TOOLS_DIR = '/var/www/html/tools';
const TEMP_DIR = '/tmp/assistente';

// Config padrao
const TIMEOUT_PADRAO_MS = 300000; // 5 minutos
const MAX_TURNS_PADRAO = 10;
const INTERVALO_POLL_MS = 2000;

// PostgreSQL config
const DB_HOST = process.env.DATABASE_HOST || 'db';
const DB_USER = process.env.DATABASE_USER || 'assistente';
const DB_PASS = process.env.DATABASE_PASSWORD || 'assistente_dev_2026';
const DB_NAME = process.env.DATABASE_NAME || 'assistente';

const pool = new Pool({
    host: DB_HOST,
    port: 5432,
    user: DB_USER,
    password: DB_PASS,
    database: DB_NAME,
    max: 3,
    idleTimeoutMillis: 30000,
    connectionTimeoutMillis: 10000,
});

// ========================================
// LOG UNIFICADO (tudo em stdout)
// ========================================

function log(componente, msg, dados) {
    const ts = new Date().toISOString().slice(11, 19);
    const extra = dados ? ` | ${JSON.stringify(dados)}` : '';
    console.log(`[${ts}][${componente}] ${msg}${extra}`);
}

// ========================================
// FERRAMENTAS
// ========================================

function carregarFerramentas() {
    const arquivo = path.join(TOOLS_DIR, 'ferramentas.json');
    try {
        return JSON.parse(fs.readFileSync(arquivo, 'utf8'));
    } catch {
        log('tools', 'ferramentas.json nao encontrado, usando padrao');
        return {
            geral: {
                nome: 'Geral',
                prompt: 'sistema-padrao.txt',
                max_turns: MAX_TURNS_PADRAO,
                timeout_ms: TIMEOUT_PADRAO_MS,
            }
        };
    }
}

function obterConfigFerramenta(slug) {
    const ferramentas = carregarFerramentas();
    return ferramentas[slug] || ferramentas['geral'];
}

// ========================================
// POSTGRESQL HELPERS
// ========================================

// Testar conexao no boot
pool.query('SELECT 1').then(() => {
    log('db', 'PostgreSQL conectado');
}).catch(err => {
    log('db', `PostgreSQL FALHOU: ${err.message}`);
});

// ========================================
// CARREGAR CONTEXTO
// ========================================

function carregarSystemPrompt(arquivoPrompt = 'sistema-padrao.txt') {
    const caminho = path.join(PROMPTS_DIR, arquivoPrompt);
    try {
        return fs.readFileSync(caminho, 'utf8');
    } catch {
        log('prompt', `Arquivo ${arquivoPrompt} nao encontrado, usando padrao`);
        const padrao = path.join(PROMPTS_DIR, 'sistema-padrao.txt');
        try {
            return fs.readFileSync(padrao, 'utf8');
        } catch {
            return 'Voce e o assistente pessoal do Wellington. Responda em portugues brasileiro.';
        }
    }
}

async function carregarMemoria() {
    try {
        const { rows } = await pool.query(
            'SELECT categoria, chave, valor FROM assistente_memoria ORDER BY relevancia DESC LIMIT 20'
        );
        return rows.map(m => `[${m.categoria}/${m.chave}]: ${m.valor}`).join('\n');
    } catch (err) {
        log('db', `Erro carregarMemoria: ${err.message}`);
        return '';
    }
}

async function carregarHistorico(sessaoId) {
    if (!sessaoId) return '';
    try {
        const { rows } = await pool.query(
            'SELECT direcao, conteudo FROM assistente_mensagens WHERE sessao_id = $1 ORDER BY criado_em DESC LIMIT 5',
            [sessaoId]
        );
        return rows
            .reverse()
            .map(m => `${m.direcao === 'recebida' ? 'Wellington' : 'Well-dev'}: ${m.conteudo}`)
            .join('\n');
    } catch (err) {
        log('db', `Erro carregarHistorico: ${err.message}`);
        return '';
    }
}

// ========================================
// EXECUTAR CLAUDE CODE CLI (via spawn + stdin)
// ========================================

function executarClaude(mensagem, contexto, claudeSessionId, opcoes = {}) {
    const maxTurns = opcoes.maxTurns || MAX_TURNS_PADRAO;
    const timeoutMs = opcoes.timeoutMs || TIMEOUT_PADRAO_MS;

    return new Promise((resolve, reject) => {
        const promptCompleto = contexto
            ? `${contexto}\n\n---\nMensagem do usuario:\n${mensagem}`
            : mensagem;

        // Salvar prompt em arquivo temp
        const promptFile = path.join(TEMP_DIR, `p-${Date.now()}.txt`);
        fs.writeFileSync(promptFile, promptCompleto, 'utf8');

        const args = [
            '--dangerously-skip-permissions',
            '--output-format', 'json',
            '--max-turns', String(maxTurns),
        ];

        if (claudeSessionId) {
            args.push('--resume', claudeSessionId);
        }

        const inicio = Date.now();

        log('claude', 'Executando CLI...', {
            msgLen: mensagem.length,
            ctxLen: contexto ? contexto.length : 0,
            maxTurns,
            timeoutMs,
            resume: claudeSessionId || 'nova',
        });

        // Usar spawn com stdin pipe
        const proc = spawn('claude', args, {
            env: { ...process.env, HOME: '/home/claude-user' },
            cwd: '/workspace',
            stdio: ['pipe', 'pipe', 'pipe'],
        });

        let stdout = '';
        let stderr = '';

        proc.stdout.on('data', (chunk) => { stdout += chunk.toString(); });
        proc.stderr.on('data', (chunk) => { stderr += chunk.toString(); });

        // Enviar prompt via stdin e fechar
        proc.stdin.write(promptCompleto);
        proc.stdin.end();

        // Timeout manual
        const timer = setTimeout(() => {
            log('claude', `TIMEOUT apos ${timeoutMs}ms - matando processo`);
            proc.kill('SIGTERM');
            setTimeout(() => proc.kill('SIGKILL'), 5000);
        }, timeoutMs);

        proc.on('close', (code, signal) => {
            clearTimeout(timer);
            const tempoMs = Date.now() - inicio;

            // Limpar arquivo temp
            try { fs.unlinkSync(promptFile); } catch {}

            if (signal) {
                log('claude', `Morto por sinal: ${signal} (${tempoMs}ms)`);
                reject(new Error(`Processo morto: ${signal}`));
                return;
            }

            if (code !== 0) {
                log('claude', `Exit code ${code} (${tempoMs}ms)`);
                log('claude', `stderr: ${stderr.substring(0, 1000)}`);
                log('claude', `stdout: ${stdout.substring(0, 500)}`);
                reject(new Error(`Exit ${code}: ${stderr.substring(0, 200)}`));
                return;
            }

            log('claude', `Concluido em ${tempoMs}ms (${stdout.length} bytes)`);

            try {
                const resultado = JSON.parse(stdout);

                // Extrair texto da resposta (result pode ser vazio em erros)
                let texto = '';
                if (resultado.result) {
                    texto = resultado.result;
                } else if (resultado.subtype === 'errorMaxTurns') {
                    texto = 'Desculpe, a tarefa atingiu o limite de etapas. Tente dividir em partes menores.';
                } else if (resultado.isError) {
                    texto = `Erro ao processar: ${resultado.subtype || 'desconhecido'}`;
                } else {
                    texto = 'Tarefa concluida, mas sem texto de resposta.';
                }

                resolve({
                    texto,
                    sessionId: resultado.sessionId || null,
                    modelo: resultado.modelUsage ? Object.keys(resultado.modelUsage)[0] : 'desconhecido',
                    tokensEntrada: resultado.usage?.inputTokens || 0,
                    tokensSaida: resultado.usage?.outputTokens || 0,
                    custoUSD: resultado.totalCostUSD || 0,
                    tempoMs,
                });
            } catch {
                log('claude', `Resposta nao-JSON: ${stdout.substring(0, 300)}`);
                resolve({
                    texto: stdout.trim() || 'Sem resposta do CLI',
                    sessionId: null,
                    modelo: 'desconhecido',
                    tokensEntrada: 0,
                    tokensSaida: 0,
                    custoUSD: 0,
                    tempoMs,
                });
            }
        });

        proc.on('error', (erro) => {
            clearTimeout(timer);
            log('claude', `Erro spawn: ${erro.message}`);
            try { fs.unlinkSync(promptFile); } catch {}
            reject(erro);
        });
    });
}

// ========================================
// ENVIAR RESPOSTA VIA PHP
// ========================================

function enviarViaPHP(canal, chatId, texto, messageId, threadId) {
    return new Promise((resolve) => {
        const endpoint = canal === 'telegram'
            ? '/api/enviar-telegram.php'
            : '/api/enviar-whatsapp.php';

        const payload = { chat_id: chatId, texto, message_id: messageId };
        if (threadId) payload.thread_id = threadId;
        const corpo = JSON.stringify(payload);

        const req = http.request({
            hostname: '127.0.0.1',
            port: 80,
            path: endpoint,
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(corpo),
            },
        }, (res) => {
            let dados = '';
            res.on('data', chunk => dados += chunk);
            res.on('end', () => {
                log('enviar', `${canal} status: ${res.statusCode}`);
                resolve(dados);
            });
        });

        req.on('error', (erro) => {
            log('enviar', `ERRO ${canal}: ${erro.message}`);
            resolve(null);
        });

        req.write(corpo);
        req.end();
    });
}

// ========================================
// PROCESSAR ITEM DA FILA
// ========================================

let processando = false;

async function processarItem(arquivo) {
    if (processando) return;
    processando = true;

    const nomeArquivo = path.basename(arquivo);
    log('fila', `Processando: ${nomeArquivo}`);

    try {
        const conteudo = fs.readFileSync(arquivo, 'utf8');
        const item = JSON.parse(conteudo);

        // Detectar ferramenta (passada pelo webhook ou padrao)
        const ferramentaSlug = item.ferramenta || 'geral';
        const ferramentaConfig = obterConfigFerramenta(ferramentaSlug);

        log('fila', `Msg de ${item.canal}/${item.chat_id}: "${item.mensagem.substring(0, 50)}"`, {
            ferramenta: ferramentaSlug,
            prompt: ferramentaConfig.prompt,
        });

        // Montar contexto com prompt especifico da ferramenta
        const systemPrompt = carregarSystemPrompt(ferramentaConfig.prompt);
        const memoria = await carregarMemoria();
        const historico = await carregarHistorico(item.sessao_id);

        const contexto = [
            systemPrompt,
            memoria ? `\n## Memoria Persistente\n${memoria}` : '',
            historico ? `\n## Historico Recente\n${historico}` : '',
        ].filter(Boolean).join('\n');

        log('fila', `Contexto: ${contexto.length} chars (ferramenta: ${ferramentaSlug})`);

        // Executar Claude Code CLI com config da ferramenta
        let resultado;
        try {
            resultado = await executarClaude(
                item.mensagem,
                contexto,
                item.claude_session_id,
                {
                    maxTurns: ferramentaConfig.max_turns,
                    timeoutMs: ferramentaConfig.timeout_ms,
                }
            );
        } catch (erroResume) {
            // Se falhou com --resume (sessao nao encontrada), tentar sem
            if (item.claude_session_id && erroResume.message.includes('No conversation found')) {
                log('fila', 'Sessao nao encontrada, tentando sem --resume...');
                resultado = await executarClaude(item.mensagem, contexto, null, {
                    maxTurns: ferramentaConfig.max_turns,
                    timeoutMs: ferramentaConfig.timeout_ms,
                });
            } else {
                throw erroResume;
            }
        }

        log('fila', `Resposta: "${resultado.texto.substring(0, 100)}"`);

        // Enviar resposta ao canal (com thread_id para Topics)
        await enviarViaPHP(item.canal, item.chat_id, resultado.texto, item.message_id, item.thread_id);

        // Salvar resposta no PostgreSQL
        try {
            await pool.query(
                `INSERT INTO assistente_mensagens
                 (sessao_id, canal, chat_id, direcao, conteudo, tokens_entrada, tokens_saida, tempo_resposta_ms, modelo, metadata)
                 VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)`,
                [
                    item.sessao_id, item.canal, item.chat_id, 'enviada', resultado.texto,
                    resultado.tokensEntrada, resultado.tokensSaida, resultado.tempoMs,
                    resultado.modelo, JSON.stringify({ ferramenta: ferramentaSlug })
                ]
            );

            if (resultado.sessionId && item.sessao_id) {
                const agora = new Date().toISOString();
                await pool.query(
                    `UPDATE assistente_sessoes
                     SET claude_session_id = $1, ferramenta = $2,
                         ultima_mensagem_em = $3, atualizado_em = $4
                     WHERE id = $5`,
                    [resultado.sessionId, ferramentaSlug, agora, agora, item.sessao_id]
                );
            }
        } catch (dbErr) {
            log('db', `Erro ao salvar resposta: ${dbErr.message}`);
        }

        // Mover para processados
        fs.renameSync(arquivo, path.join(PROCESSADOS_DIR, nomeArquivo));
        log('fila', `Concluido: ${nomeArquivo}`);

    } catch (erro) {
        log('fila', `ERRO: ${erro.message}`);

        try {
            const item = JSON.parse(fs.readFileSync(arquivo, 'utf8'));
            await enviarViaPHP(
                item.canal,
                item.chat_id,
                'Desculpe, tive um problema processando sua mensagem. Tente novamente em alguns segundos.',
                item.message_id,
                item.thread_id
            );
        } catch {}

        try {
            fs.renameSync(arquivo, path.join(PROCESSADOS_DIR, 'ERRO_' + nomeArquivo));
        } catch {}
    }

    processando = false;
}

// ========================================
// MONITORAR FILA
// ========================================

function verificarFila() {
    try {
        const arquivos = fs.readdirSync(FILA_DIR)
            .filter(f => f.endsWith('.json'))
            .sort();

        if (arquivos.length > 0) {
            processarItem(path.join(FILA_DIR, arquivos[0]));
        }
    } catch (erro) {
        if (erro.code !== 'ENOENT') {
            log('fila', `Erro lendo diretorio: ${erro.message}`);
        }
    }
}

// ========================================
// TESTE INICIAL DO CLI
// ========================================

async function testarCLI() {
    log('teste', 'Testando Claude CLI...');
    try {
        const resultado = await executarClaude('Responda apenas: OK', '', null);
        log('teste', `CLI funcionando! Resposta: "${resultado.texto.substring(0, 50)}"`);
        return true;
    } catch (erro) {
        log('teste', `CLI FALHOU: ${erro.message}`);
        return false;
    }
}

// ========================================
// INICIALIZACAO
// ========================================

const ferramentas = carregarFerramentas();
const totalFerramentas = Object.keys(ferramentas).length;

log('worker', '========================================');
log('worker', 'ASSISTENTE IA PESSOAL - Worker Node.js');
log('worker', `HOME: ${process.env.HOME}`);
log('worker', `Fila: ${FILA_DIR}`);
log('worker', `Ferramentas: ${totalFerramentas} carregadas (${Object.keys(ferramentas).join(', ')})`);
log('worker', `PostgreSQL: ${DB_HOST}/${DB_NAME}`);
log('worker', '========================================');

// Garantir que diretorios existem
[FILA_DIR, PROCESSADOS_DIR, TEMP_DIR].forEach(d => {
    if (!fs.existsSync(d)) fs.mkdirSync(d, { recursive: true });
});

// Testar CLI antes de iniciar o poll
testarCLI().then((ok) => {
    if (ok) {
        log('worker', 'CLI OK - iniciando monitoramento da fila');
    } else {
        log('worker', 'CLI FALHOU - iniciando mesmo assim (pode ser auth pendente)');
    }

    setInterval(verificarFila, INTERVALO_POLL_MS);
    verificarFila();
    log('worker', 'Monitorando fila...');
});
