/**
 * ASSISTENTE - Worker Node.js
 * Monitora a fila de mensagens e executa Claude Code CLI
 */

const fs = require('fs');
const path = require('path');
const { execFile } = require('child_process');
const http = require('http');

// Diretorios
const FILA_DIR = '/var/assistente/fila';
const PROCESSADOS_DIR = '/var/assistente/fila/processados';
const PROMPTS_DIR = '/var/www/html/prompts';

// Config
const TIMEOUT_MS = 120000; // 2 minutos
const MAX_TURNS = 5;
const INTERVALO_POLL_MS = 2000; // 2 segundos

// Supabase config (lido das env vars ou hardcoded no container)
const SUPABASE_URL = process.env.SUPABASE_URL || '';
const SUPABASE_KEY = process.env.SUPABASE_SERVICE_KEY || '';

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
        console.error(`[supabase] Erro: ${erro.message}`);
        return { ok: false, status: 0, dados: null };
    }
}

// ========================================
// CARREGAR CONTEXTO
// ========================================

function carregarSystemPrompt() {
    const arquivo = path.join(PROMPTS_DIR, 'sistema-padrao.txt');
    try {
        return fs.readFileSync(arquivo, 'utf8');
    } catch {
        return 'Voce e o assistente pessoal do Wellington. Responda em portugues brasileiro.';
    }
}

async function carregarMemoria() {
    const resultado = await supabaseFetch(
        'assistente_memoria?order=relevancia.desc&limit=20'
    );

    if (!resultado.ok || !resultado.dados) return '';

    return resultado.dados
        .map(m => `[${m.categoria}/${m.chave}]: ${m.valor}`)
        .join('\n');
}

async function carregarHistorico(sessaoId) {
    if (!sessaoId) return '';

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
// EXECUTAR CLAUDE CODE CLI
// ========================================

function executarClaude(mensagem, contexto, claudeSessionId) {
    return new Promise((resolve, reject) => {
        const args = [
            '-p', mensagem,
            '--output-format', 'json',
            '--max-turns', String(MAX_TURNS),
            '--dangerously-skip-permissions',
        ];

        // Injetar contexto via append-system-prompt
        if (contexto) {
            args.push('--append-system-prompt', contexto);
        }

        // Resumir sessao anterior
        if (claudeSessionId) {
            args.push('--resume', claudeSessionId);
        }

        const inicio = Date.now();

        const proc = execFile('claude', args, {
            timeout: TIMEOUT_MS,
            maxBuffer: 10 * 1024 * 1024, // 10MB
            env: {
                ...process.env,
                CLAUDE_CODE_OAUTH_TOKEN: process.env.CLAUDE_CODE_OAUTH_TOKEN,
            },
        }, (erro, stdout, stderr) => {
            const tempoMs = Date.now() - inicio;

            if (erro) {
                console.error(`[claude] Erro (${tempoMs}ms): ${erro.message}`);
                if (stderr) console.error(`[claude] stderr: ${stderr}`);
                reject(erro);
                return;
            }

            try {
                const resultado = JSON.parse(stdout);
                resolve({
                    texto: resultado.result || resultado.text || stdout,
                    sessionId: resultado.session_id || null,
                    modelo: resultado.model || 'desconhecido',
                    tokensEntrada: resultado.usage?.input_tokens || 0,
                    tokensSaida: resultado.usage?.output_tokens || 0,
                    tempoMs,
                });
            } catch {
                // Se nao for JSON valido, retornar o texto bruto
                resolve({
                    texto: stdout.trim(),
                    sessionId: null,
                    modelo: 'desconhecido',
                    tokensEntrada: 0,
                    tokensSaida: 0,
                    tempoMs,
                });
            }
        });
    });
}

// ========================================
// ENVIAR RESPOSTA VIA PHP
// ========================================

function enviarViaPHP(canal, chatId, texto, messageId) {
    return new Promise((resolve) => {
        const endpoint = canal === 'telegram'
            ? '/api/enviar-telegram.php'
            : '/api/enviar-whatsapp.php';

        const corpo = JSON.stringify({ chat_id: chatId, texto, message_id: messageId });

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
            res.on('end', () => resolve(dados));
        });

        req.on('error', (erro) => {
            console.error(`[enviar] Erro: ${erro.message}`);
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
    if (processando) return; // Uma mensagem por vez
    processando = true;

    const nomeArquivo = path.basename(arquivo);
    console.log(`[fila] Processando: ${nomeArquivo}`);

    try {
        const conteudo = fs.readFileSync(arquivo, 'utf8');
        const item = JSON.parse(conteudo);

        // Montar contexto
        const systemPrompt = carregarSystemPrompt();
        const memoria = await carregarMemoria();
        const historico = await carregarHistorico(item.sessao_id);

        const contexto = [
            systemPrompt,
            memoria ? `\n## Memoria Persistente\n${memoria}` : '',
            historico ? `\n## Historico Recente\n${historico}` : '',
        ].filter(Boolean).join('\n');

        // Executar Claude Code CLI
        const resultado = await executarClaude(
            item.mensagem,
            contexto,
            item.claude_session_id
        );

        console.log(`[claude] Resposta em ${resultado.tempoMs}ms (${resultado.tokensSaida} tokens saida)`);

        // Enviar resposta ao canal
        await enviarViaPHP(item.canal, item.chat_id, resultado.texto, item.message_id);

        // Salvar resposta no Supabase
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
            }
        });

        // Atualizar sessao com session_id do Claude
        if (resultado.sessionId && item.sessao_id) {
            await supabaseFetch(`assistente_sessoes?id=eq.${item.sessao_id}`, {
                metodo: 'PATCH',
                corpo: {
                    claude_session_id: resultado.sessionId,
                    ultima_mensagem_em: new Date().toISOString(),
                    atualizado_em: new Date().toISOString(),
                }
            });
        }

        // Mover para processados
        const destino = path.join(PROCESSADOS_DIR, nomeArquivo);
        fs.renameSync(arquivo, destino);
        console.log(`[fila] Concluido: ${nomeArquivo}`);

    } catch (erro) {
        console.error(`[fila] Erro processando ${nomeArquivo}: ${erro.message}`);

        // Tentar enviar mensagem de erro ao usuario
        try {
            const item = JSON.parse(fs.readFileSync(arquivo, 'utf8'));
            await enviarViaPHP(
                item.canal,
                item.chat_id,
                'Desculpe, tive um problema processando sua mensagem. Tente novamente em alguns segundos.',
                item.message_id
            );
        } catch {}

        // Mover para processados mesmo com erro (evitar loop)
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
            .sort(); // processar na ordem de criacao

        if (arquivos.length > 0) {
            processarItem(path.join(FILA_DIR, arquivos[0]));
        }
    } catch (erro) {
        if (erro.code !== 'ENOENT') {
            console.error(`[fila] Erro lendo diretorio: ${erro.message}`);
        }
    }
}

// ========================================
// INICIALIZACAO
// ========================================

console.log('========================================');
console.log('ASSISTENTE IA PESSOAL - Worker Node.js');
console.log(`Fila: ${FILA_DIR}`);
console.log(`Poll: ${INTERVALO_POLL_MS}ms`);
console.log(`Timeout CLI: ${TIMEOUT_MS}ms`);
console.log(`OAuth token: ${process.env.CLAUDE_CODE_OAUTH_TOKEN ? 'presente' : 'AUSENTE'}`);
console.log('========================================');

// Garantir que diretorios existem
if (!fs.existsSync(FILA_DIR)) fs.mkdirSync(FILA_DIR, { recursive: true });
if (!fs.existsSync(PROCESSADOS_DIR)) fs.mkdirSync(PROCESSADOS_DIR, { recursive: true });

// Poll a cada 2 segundos (mais robusto que fs.watch em containers)
setInterval(verificarFila, INTERVALO_POLL_MS);

// Verificar imediatamente
verificarFila();

console.log('[worker] Monitorando fila...');