/**
 * ASSISTENTE - Worker Node.js
 * Monitora a fila de mensagens e executa Claude Code CLI
 * Suporta ferramentas por topico (prompt + config customizados)
 */

const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const http = require('http');

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

// Supabase config
const SUPABASE_URL = process.env.SUPABASE_URL || '';
const SUPABASE_KEY = process.env.SUPABASE_SERVICE_KEY || '';

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
// SUPABASE HELPERS
// ========================================

async function supabaseFetch(caminho, opcoes = {}) {
    const url = `${SUPABASE_URL}/rest/v1/${caminho}`;
    const metodo = opcoes.metodo || 'GET';
    const corpo = opcoes.corpo ? JSON.stringify(opcoes.corpo) : undefined;

    try {
        const resposta = await fetch(url, {
            method: metodo,
            headers: {
                'apikey': SUPABASE_KEY,
                'Authorization': `Bearer ${SUPABASE_KEY}`,
                'Content-Type': 'application/json',
                'Prefer': 'return=representation',
            },
            body: corpo,
        });

        const dados = await resposta.json().catch(() => null);
        return { ok: resposta.ok, status: resposta.status, dados };
    } catch (erro) {
        log('supabase', `Erro: ${erro.message}`);
        return { ok: false, status: 0, dados: null };
    }
}

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
    if (!SUPABASE_URL || !SUPABASE_KEY) return '';

    const resultado = await supabaseFetch(
        'assistente_memoria?order=relevancia.desc&limit=20'
    );

    if (!resultado.ok || !resultado.dados) return '';

    return resultado.dados
        .map(m => `[${m.categoria}/${m.chave}]: ${m.valor}`)
        .join('\n');
}

async function carregarHistorico(sessaoId) {
    if (!sessaoId || !SUPABASE_URL || !SUPABASE_KEY) return '';

    const resultado = await supabaseFetch(
        `assistente_mensagens?sessao_id=eq.${sessaoId}&order=criado_em.desc&limit=5`
    );

    if (!resultado.ok || !resultado.dados) return '';

    return resultado.dados
        .reverse()
        .map(m => `${m.direcao === 'recebida' ? 'Wellington' : 'Well-dev'}: ${m.conteudo}`)
        .join('\n');
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

        // Salvar resposta no Supabase
        if (SUPABASE_URL && SUPABASE_KEY) {
            await supabaseFetch('assistente_mensagens', {
                metodo: 'POST',
                corpo: {
                    sessao_id: item.sessao_id,
                    canal: item.canal,
                    chat_id: item.chat_id,
                    direcao: 'enviada',
                    conteudo: resultado.texto,
                    tokens_entrada: resultado.tokensEntrada,
                    tokens_saida: resultado.tokensSaida,
                    tempo_resposta_ms: resultado.tempoMs,
                    modelo: resultado.modelo,
                    metadata: { ferramenta: ferramentaSlug },
                }
            });

            if (resultado.sessionId && item.sessao_id) {
                await supabaseFetch(`assistente_sessoes?id=eq.${item.sessao_id}`, {
                    metodo: 'PATCH',
                    corpo: {
                        claude_session_id: resultado.sessionId,
                        ferramenta: ferramentaSlug,
                        ultima_mensagem_em: new Date().toISOString(),
                        atualizado_em: new Date().toISOString(),
                    }
                });
            }
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
log('worker', `Supabase: ${SUPABASE_URL ? 'configurado' : 'AUSENTE'}`);
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
