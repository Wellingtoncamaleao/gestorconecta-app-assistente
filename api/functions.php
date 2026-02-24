<?php
/**
 * ASSISTENTE IA PESSOAL - Helpers compartilhados
 */

require_once __DIR__ . '/config.php';

// ========================================
// SUPABASE
// ========================================

function supabaseFetch($caminho, $opcoes = []) {
    $metodo = $opcoes['metodo'] ?? 'GET';
    $corpo = $opcoes['corpo'] ?? null;
    $headers = [
        'apikey: ' . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ];

    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $caminho);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $metodo,
    ]);

    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo));
    }

    $resposta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'dados' => json_decode($resposta, true)
    ];
}

// ========================================
// TELEGRAM
// ========================================

function enviarTelegram($chatId, $texto, $extras = []) {
    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';

    // Limitar a 4096 chars (Telegram max)
    $partes = dividirMensagem($texto, 4096);

    foreach ($partes as $parte) {
        $payload = array_merge([
            'chat_id' => $chatId,
            'text' => $parte,
            'parse_mode' => 'Markdown',
        ], $extras);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resultado = curl_exec($ch);

        // Se Markdown falhar, tentar sem parse_mode
        $resposta = json_decode($resultado, true);
        if (!($resposta['ok'] ?? false)) {
            unset($payload['parse_mode']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_exec($ch);
        }

        curl_close($ch);
    }
}

function telegramDigitando($chatId, $threadId = null) {
    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendChatAction';
    $payload = [
        'chat_id' => $chatId,
        'action' => 'typing',
    ];
    if ($threadId) {
        $payload['message_thread_id'] = (int)$threadId;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ========================================
// WHATSAPP (Evolution API)
// ========================================

function enviarWhatsApp($chatId, $texto) {
    $url = EVOLUTION_API_URL . '/message/sendText/' . EVOLUTION_INSTANCIA;

    $partes = dividirMensagem($texto, 4000);

    foreach ($partes as $parte) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'number' => $chatId,
                'text' => $parte,
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'apikey: ' . EVOLUTION_API_KEY,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

// ========================================
// SESSOES
// ========================================

function buscarOuCriarSessao($canal, $chatId) {
    // Buscar sessao ativa
    $resultado = supabaseFetch(
        'assistente_sessoes?canal=eq.' . $canal .
        '&chat_id=eq.' . $chatId .
        '&status=eq.ativa' .
        '&order=ultima_mensagem_em.desc&limit=1'
    );

    if ($resultado['ok'] && !empty($resultado['dados'])) {
        return $resultado['dados'][0];
    }

    // Criar nova sessao
    $nova = supabaseFetch('assistente_sessoes', [
        'metodo' => 'POST',
        'corpo' => [
            'canal' => $canal,
            'chat_id' => $chatId,
            'status' => 'ativa',
            'ultima_mensagem_em' => date('c'),
        ]
    ]);

    return $nova['dados'][0] ?? ['id' => null, 'claude_session_id' => null];
}

// ========================================
// MENSAGENS
// ========================================

function salvarMensagem($sessaoId, $canal, $chatId, $direcao, $conteudo, $metadata = []) {
    supabaseFetch('assistente_mensagens', [
        'metodo' => 'POST',
        'corpo' => [
            'sessao_id' => $sessaoId,
            'canal' => $canal,
            'chat_id' => $chatId,
            'direcao' => $direcao,
            'conteudo' => $conteudo,
            'metadata' => $metadata,
        ]
    ]);

    // Atualizar contador e timestamp da sessao
    if ($sessaoId) {
        supabaseFetch('assistente_sessoes?id=eq.' . $sessaoId, [
            'metodo' => 'PATCH',
            'corpo' => [
                'total_mensagens' => 'total_mensagens + 1',
                'ultima_mensagem_em' => date('c'),
                'atualizado_em' => date('c'),
            ]
        ]);
    }
}

// ========================================
// FILA
// ========================================

function criarItemFila($dados) {
    $id = bin2hex(random_bytes(16));
    $dados['id'] = $id;
    $dados['criado_em'] = date('c');

    $arquivo = FILA_DIR . '/' . $id . '.json';
    file_put_contents($arquivo, json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    return $id;
}

// ========================================
// LOGS
// ========================================

function logAssistente($nivel, $componente, $mensagem, $dados = []) {
    supabaseFetch('assistente_logs', [
        'metodo' => 'POST',
        'corpo' => [
            'nivel' => $nivel,
            'componente' => $componente,
            'mensagem' => $mensagem,
            'dados' => $dados,
        ]
    ]);
}

// ========================================
// CONFIGS
// ========================================

function buscarConfig($chave) {
    $resultado = supabaseFetch('assistente_configs?chave=eq.' . $chave . '&limit=1');
    return ($resultado['ok'] && !empty($resultado['dados'])) ? $resultado['dados'][0]['valor'] : null;
}

// ========================================
// UTILIDADES
// ========================================

function dividirMensagem($texto, $limite = 4096) {
    if (mb_strlen($texto) <= $limite) return [$texto];

    $partes = [];
    while (mb_strlen($texto) > 0) {
        if (mb_strlen($texto) <= $limite) {
            $partes[] = $texto;
            break;
        }

        // Cortar no ultimo \n antes do limite
        $corte = mb_strrpos(mb_substr($texto, 0, $limite), "\n");
        if ($corte === false || $corte < ($limite / 2)) {
            // Sem \n bom, cortar no ultimo espaco
            $corte = mb_strrpos(mb_substr($texto, 0, $limite), ' ');
            if ($corte === false || $corte < ($limite / 2)) {
                $corte = $limite;
            }
        }

        $partes[] = mb_substr($texto, 0, $corte);
        $texto = ltrim(mb_substr($texto, $corte));
    }

    return $partes;
}

function responderJson($dados, $codigo = 200) {
    http_response_code($codigo);
    header('Content-Type: application/json');
    echo json_encode($dados);
    exit;
}

function responderErro($mensagem, $codigo = 400) {
    responderJson(['erro' => $mensagem], $codigo);
}

// ========================================
// FERRAMENTAS (mapeamento grupo/topico → ferramenta)
// ========================================

function carregarFerramentas() {
    $arquivo = '/var/www/html/tools/ferramentas.json';
    if (!file_exists($arquivo)) return ['geral' => ['nome' => 'Geral', 'prompt' => 'sistema-padrao.txt']];
    $dados = json_decode(file_get_contents($arquivo), true);
    return $dados ?: ['geral' => ['nome' => 'Geral', 'prompt' => 'sistema-padrao.txt']];
}

function detectarFerramenta($chatId, $threadId) {
    $mapa = buscarConfig('mapa_topicos');
    if (!$mapa) return 'geral';

    $mapa = json_decode($mapa, true);
    if (!$mapa) return 'geral';

    // Prioridade: chat_id:thread_id > chat_id sozinho
    if ($threadId) {
        $chave = $chatId . ':' . $threadId;
        if (isset($mapa[$chave])) return $mapa[$chave];
    }
    if (isset($mapa[$chatId])) return $mapa[$chatId];

    return 'geral';
}

function mapearFerramenta($chatId, $threadId, $slug) {
    $ferramentas = carregarFerramentas();
    if (!isset($ferramentas[$slug])) {
        $disponiveis = implode(', ', array_keys($ferramentas));
        return "Ferramenta '$slug' nao encontrada.\nDisponiveis: $disponiveis";
    }

    // Buscar mapa atual
    $mapaAtual = buscarConfig('mapa_topicos');
    $mapa = $mapaAtual ? json_decode($mapaAtual, true) : [];
    if (!is_array($mapa)) $mapa = [];

    // Chave: grupo inteiro (chat_id) ou topico especifico (chat_id:thread_id)
    $chave = $threadId ? $chatId . ':' . $threadId : $chatId;
    $mapa[$chave] = $slug;

    // Upsert no configs
    supabaseFetch('assistente_configs?chave=eq.mapa_topicos', [
        'metodo' => 'PATCH',
        'corpo' => ['valor' => json_encode($mapa), 'atualizado_em' => date('c')]
    ]);

    $nome = $ferramentas[$slug]['nome'] ?? $slug;
    $tipo = $threadId ? 'Topico' : 'Grupo';
    return "{$tipo} mapeado para: *{$nome}*\nSlug: `{$slug}`";
}

function desmapearFerramenta($chatId, $threadId) {
    $mapaAtual = buscarConfig('mapa_topicos');
    $mapa = $mapaAtual ? json_decode($mapaAtual, true) : [];
    if (!is_array($mapa)) $mapa = [];

    $chave = $threadId ? $chatId . ':' . $threadId : $chatId;
    $antigo = $mapa[$chave] ?? null;

    if (!$antigo) {
        return 'Este grupo nao tem ferramenta mapeada.';
    }

    unset($mapa[$chave]);

    supabaseFetch('assistente_configs?chave=eq.mapa_topicos', [
        'metodo' => 'PATCH',
        'corpo' => ['valor' => json_encode($mapa), 'atualizado_em' => date('c')]
    ]);

    return "Mapeamento removido (era: `{$antigo}`).\nVoltou para modo *Geral*.";
}

function listarFerramentas() {
    $ferramentas = carregarFerramentas();
    $lista = "Ferramentas disponiveis:\n\n";
    foreach ($ferramentas as $slug => $info) {
        $lista .= "• *{$info['nome']}* (`{$slug}`)\n  {$info['descricao']}\n\n";
    }
    $lista .= "Use `/mapear slug` neste grupo para ativar.";
    return $lista;
}

// ========================================
// INSTAGRAM SCRAPER (oEmbed + Meta OG)
// ========================================

/**
 * Detecta links de Instagram em um texto.
 * Retorna array de URLs encontradas.
 */
function detectarLinksInstagram($texto) {
    $pattern = '#https?://(?:www\.)?instagram\.com/(?:p|reel|reels|tv)/[\w\-]+/?(?:\?[^\s]*)?#i';
    preg_match_all($pattern, $texto, $matches);
    return $matches[0] ?? [];
}

/**
 * Busca dados de um post do Instagram.
 * Metodo 1: Facebook Graph API oEmbed (requer FACEBOOK_APP_TOKEN)
 * Metodo 2: Fallback com meta tags OG (scraping)
 * Retorna array com dados extraidos ou null se falhar.
 */
function buscarDadosInstagram($url) {
    $dados = [
        'url' => $url,
        'tipo' => detectarTipoInstagram($url),
        'autor' => null,
        'autor_url' => null,
        'legenda' => null,
        'thumbnail_url' => null,
    ];

    // Limpar parametros de tracking da URL para o oEmbed
    $urlLimpa = preg_replace('/[?&](igsh|utm_\w+)=[^&]*/', '', $url);
    $urlLimpa = rtrim($urlLimpa, '?&');

    // 1. Metodo principal: Facebook Graph API oEmbed (precisa de token)
    if (defined('FACEBOOK_APP_TOKEN') && FACEBOOK_APP_TOKEN) {
        $oembedUrl = 'https://graph.facebook.com/v21.0/instagram_oembed'
            . '?url=' . urlencode($urlLimpa)
            . '&access_token=' . urlencode(FACEBOOK_APP_TOKEN)
            . '&omitscript=true';

        $oembedDados = fetchUrl($oembedUrl);

        if ($oembedDados) {
            $oembed = json_decode($oembedDados, true);
            if ($oembed && !isset($oembed['error'])) {
                $dados['autor'] = $oembed['author_name'] ?? null;
                $dados['autor_url'] = $oembed['author_url'] ?? null;
                $dados['legenda'] = $oembed['title'] ?? null;
                $dados['thumbnail_url'] = $oembed['thumbnail_url'] ?? null;
                $dados['thumbnail_w'] = $oembed['thumbnail_width'] ?? null;
                $dados['thumbnail_h'] = $oembed['thumbnail_height'] ?? null;

                // Extrair hashtags da legenda (oEmbed retorna legenda completa)
                if ($dados['legenda']) {
                    preg_match_all('/#[\w\p{L}]+/u', $dados['legenda'], $hashMatches);
                    $dados['hashtags'] = $hashMatches[0] ?? [];
                }

                logAssistente('info', 'instagram', 'oEmbed Graph API OK', [
                    'autor' => $dados['autor'],
                    'legenda_len' => mb_strlen($dados['legenda'] ?? ''),
                ]);
            } else {
                $erro = $oembed['error']['message'] ?? 'desconhecido';
                logAssistente('aviso', 'instagram', 'oEmbed Graph API falhou: ' . $erro);
            }
        }
    }

    // 2. Fallback: meta tags OG (scraping da pagina)
    if (!$dados['autor'] && !$dados['legenda']) {
        $html = fetchUrl($urlLimpa);
        if ($html) {
            $ogDesc = extrairMetaTag($html, 'og:description');
            if ($ogDesc) {
                $dados['legenda'] = $ogDesc;
            }

            $ogImage = extrairMetaTag($html, 'og:image');
            if ($ogImage) {
                $dados['thumbnail_url'] = $ogImage;
            }

            $ogTitle = extrairMetaTag($html, 'og:title');
            if ($ogTitle) {
                // og:title geralmente tem "Fulano no Instagram: ..."
                if (preg_match('/^(.+?)\s+(?:no|on)\s+Instagram/i', $ogTitle, $m)) {
                    $dados['autor'] = trim($m[1]);
                }
            }

            // Detectar carrossel/slides pelo HTML
            if (stripos($html, 'sidecar') !== false || stripos($html, 'GraphSidecar') !== false) {
                $dados['tipo'] = 'carrossel';
            }

            if ($dados['autor'] || $dados['legenda']) {
                logAssistente('info', 'instagram', 'Fallback OG tags OK');
            }
        }
    }

    // Se nao conseguiu nada util, retornar null
    if (!$dados['autor'] && !$dados['legenda']) {
        logAssistente('aviso', 'instagram', 'Nenhum dado extraido de: ' . $url);
        return null;
    }

    return $dados;
}

/**
 * Detecta o tipo de post pela URL.
 */
function detectarTipoInstagram($url) {
    if (preg_match('#/reel(s)?/#i', $url)) return 'reel';
    if (preg_match('#/tv/#i', $url)) return 'igtv';
    return 'post'; // /p/ — pode ser foto, carrossel, ou video
}

/**
 * Extrai uma meta tag pelo property (og:xxx).
 */
function extrairMetaTag($html, $property) {
    // Tentar property="..."
    $pattern = '/<meta\s+[^>]*property=["\']' . preg_quote($property, '/') . '["\']\s+[^>]*content=["\']([^"\']*)["\'][^>]*>/i';
    if (preg_match($pattern, $html, $m)) return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

    // Tentar content="..." property="..." (ordem invertida)
    $pattern2 = '/<meta\s+[^>]*content=["\']([^"\']*)["\'][^>]*property=["\']' . preg_quote($property, '/') . '["\']/i';
    if (preg_match($pattern2, $html, $m)) return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

    return null;
}

/**
 * Fetch simples de URL via cURL.
 */
function fetchUrl($url, $timeout = 10) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/json',
            'Accept-Language: pt-BR,pt;q=0.9',
        ],
    ]);
    $resposta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode >= 200 && $httpCode < 400) ? $resposta : null;
}

/**
 * Monta texto descritivo de um post do Instagram para enviar ao Claude.
 */
function montarDescricaoInstagram($dados) {
    $partes = [];
    $partes[] = 'POST ORIGINAL DO INSTAGRAM';
    $partes[] = 'URL: ' . $dados['url'];
    $partes[] = 'Tipo: ' . ($dados['tipo'] ?? 'post');

    if ($dados['autor']) {
        $partes[] = 'Autor: ' . $dados['autor'];
    }
    if ($dados['autor_url']) {
        $partes[] = 'Perfil: ' . $dados['autor_url'];
    }
    if ($dados['legenda']) {
        $partes[] = 'Legenda/descricao: ' . $dados['legenda'];
    }
    if (!empty($dados['hashtags'])) {
        $partes[] = 'Hashtags originais: ' . implode(' ', $dados['hashtags']);
    }
    if ($dados['thumbnail_url']) {
        $dimensao = '';
        if (!empty($dados['thumbnail_w']) && !empty($dados['thumbnail_h'])) {
            $dimensao = ' (' . $dados['thumbnail_w'] . 'x' . $dados['thumbnail_h'] . ')';
        }
        $partes[] = 'Thumbnail: ' . $dados['thumbnail_url'] . $dimensao;
    }

    return implode("\n", $partes);
}

/**
 * Enriquece a mensagem do usuario se contem links de Instagram.
 * Retorna a mensagem original enriquecida com dados dos posts.
 */
function enriquecerMensagemInstagram($texto) {
    $links = detectarLinksInstagram($texto);
    if (empty($links)) return $texto;

    $extras = [];
    foreach ($links as $link) {
        $dados = buscarDadosInstagram($link);
        if ($dados) {
            $extras[] = montarDescricaoInstagram($dados);
        } else {
            $extras[] = "📌 LINK INSTAGRAM (nao foi possivel extrair dados): $link";
        }
    }

    if (empty($extras)) return $texto;

    return $texto . "\n\n---\n" . implode("\n\n---\n", $extras);
}

// ========================================
// COMANDOS INTERNOS
// ========================================

function processarComando($texto, $chatId, $threadId) {
    $extras = [];
    if ($threadId) $extras['message_thread_id'] = (int)$threadId;

    // Em grupos, Telegram envia "/comando@nomedoBot" — remover @bot
    $texto = preg_replace('/@\w+/', '', $texto);

    // /ferramentas — listar ferramentas
    if ($texto === '/ferramentas') {
        enviarTelegram($chatId, listarFerramentas(), $extras);
        return true;
    }

    // /mapear <slug> — mapear grupo/topico a ferramenta
    if (strpos($texto, '/mapear ') === 0) {
        $slug = trim(substr($texto, 8));
        $resultado = mapearFerramenta($chatId, $threadId, $slug);
        enviarTelegram($chatId, $resultado, $extras);
        return true;
    }

    // /desmapear — remover mapeamento
    if ($texto === '/desmapear') {
        $resultado = desmapearFerramenta($chatId, $threadId);
        enviarTelegram($chatId, $resultado, $extras);
        return true;
    }

    // /mapeamentos — mostrar mapeamentos ativos
    if ($texto === '/mapeamentos') {
        $mapa = buscarConfig('mapa_topicos');
        $mapa = $mapa ? json_decode($mapa, true) : [];
        if (empty($mapa)) {
            enviarTelegram($chatId, 'Nenhum mapeamento ativo. Use `/mapear slug` em um grupo.', $extras);
        } else {
            $ferramentas = carregarFerramentas();
            $lista = "Mapeamentos ativos:\n\n";
            foreach ($mapa as $chave => $slug) {
                $nome = $ferramentas[$slug]['nome'] ?? $slug;
                $lista .= "• `{$chave}` → *{$nome}*\n";
            }
            enviarTelegram($chatId, $lista, $extras);
        }
        return true;
    }

    return false; // nao e comando
}