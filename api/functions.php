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
// MIDIA INSTAGRAM (yt-dlp + GD + ffmpeg)
// ========================================

define('MEDIA_DIR', '/tmp/assistente/media');
define('MARCA_DAGUA_TEXTO', '@wellington_camaleao');

/**
 * Baixa midia de um post Instagram via yt-dlp.
 * Retorna array ['path' => string, 'tipo' => 'imagem'|'video'] ou null.
 */
function baixarMidiaInstagram($url) {
    if (!is_dir(MEDIA_DIR)) mkdir(MEDIA_DIR, 0775, true);

    // Limpar parametros de tracking
    $urlLimpa = preg_replace('/[?&](igsh|utm_\w+)=[^&]*/', '', $url);
    $urlLimpa = rtrim($urlLimpa, '?&');

    $hash = md5($urlLimpa . time());
    $outputTemplate = MEDIA_DIR . '/' . $hash . '.%(ext)s';

    // yt-dlp: baixar melhor qualidade, max 50MB (limite Telegram video)
    $cmd = sprintf(
        'yt-dlp --no-playlist --max-filesize 50m -o %s %s 2>&1',
        escapeshellarg($outputTemplate),
        escapeshellarg($urlLimpa)
    );

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    $outputStr = implode("\n", $output);

    if ($exitCode !== 0) {
        logAssistente('aviso', 'midia', 'yt-dlp falhou (exit ' . $exitCode . ')', [
            'url' => $urlLimpa,
            'output' => mb_substr($outputStr, 0, 500),
        ]);
        // Fallback: tentar baixar thumbnail via og:image
        return baixarThumbnailFallback($url);
    }

    // Encontrar arquivo baixado (yt-dlp substitui %(ext)s pela extensao real)
    $arquivos = glob(MEDIA_DIR . '/' . $hash . '.*');
    if (empty($arquivos)) {
        logAssistente('aviso', 'midia', 'yt-dlp: nenhum arquivo gerado');
        return baixarThumbnailFallback($url);
    }

    $arquivo = $arquivos[0];
    $ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
    $tipo = in_array($ext, ['mp4', 'webm', 'mkv', 'mov']) ? 'video' : 'imagem';

    $tamanho = filesize($arquivo);
    logAssistente('info', 'midia', "yt-dlp OK: {$tipo} ({$ext}, " . round($tamanho / 1024) . "KB)", [
        'path' => $arquivo,
    ]);

    return ['path' => $arquivo, 'tipo' => $tipo];
}

/**
 * Fallback: baixar thumbnail via og:image quando yt-dlp falha.
 */
function baixarThumbnailFallback($url) {
    $dados = buscarDadosInstagram($url);
    $imageUrl = $dados['thumbnail_url'] ?? null;
    if (!$imageUrl) return null;

    $hash = md5($url . time());
    $ch = curl_init($imageUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    ]);
    $binario = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 400 || !$binario) return null;

    $path = MEDIA_DIR . '/' . $hash . '.jpg';
    file_put_contents($path, $binario);

    logAssistente('info', 'midia', 'Fallback thumbnail OK: ' . strlen($binario) . ' bytes');
    return ['path' => $path, 'tipo' => 'imagem'];
}

/**
 * Aplica marca dagua de texto em uma imagem usando GD.
 * Retorna path da imagem processada ou o original se falhar.
 */
function aplicarMarcaDaguaImagem($imagemPath, $texto = null) {
    if (!$texto) $texto = MARCA_DAGUA_TEXTO;
    if (!file_exists($imagemPath)) return null;
    if (!function_exists('imagecreatefromjpeg')) {
        logAssistente('aviso', 'midia', 'GD nao disponivel, enviando sem marca dagua');
        return $imagemPath;
    }

    $ext = strtolower(pathinfo($imagemPath, PATHINFO_EXTENSION));

    switch ($ext) {
        case 'png':  $img = @imagecreatefrompng($imagemPath); break;
        case 'webp': $img = @imagecreatefromwebp($imagemPath); break;
        default:     $img = @imagecreatefromjpeg($imagemPath); break;
    }

    if (!$img) {
        logAssistente('aviso', 'midia', 'Falha ao carregar imagem: ' . $imagemPath);
        return $imagemPath;
    }

    $largura = imagesx($img);
    $altura = imagesy($img);

    $fonteInterna = 5;
    $charW = imagefontwidth($fonteInterna);
    $charH = imagefontheight($fonteInterna);
    $textoW = $charW * strlen($texto);
    $textoH = $charH;

    $margem = (int)($largura * 0.03);
    $x = $largura - $textoW - $margem;
    $y = $altura - $textoH - $margem;

    // Fundo semi-transparente preto
    $bgColor = imagecolorallocatealpha($img, 0, 0, 0, 60);
    imagefilledrectangle($img, $x - 8, $y - 4, $x + $textoW + 8, $y + $textoH + 4, $bgColor);

    // Texto branco
    $textColor = imagecolorallocate($img, 255, 255, 255);
    imagestring($img, $fonteInterna, $x, $y, $texto, $textColor);

    $outputPath = preg_replace('/\.\w+$/', '_marca.jpg', $imagemPath);
    imagejpeg($img, $outputPath, 92);
    imagedestroy($img);

    return $outputPath;
}

/**
 * Aplica marca dagua em video usando ffmpeg.
 * Retorna path do video processado ou o original se falhar.
 */
function aplicarMarcaDaguaVideo($videoPath, $texto = null) {
    if (!$texto) $texto = MARCA_DAGUA_TEXTO;
    if (!file_exists($videoPath)) return null;

    $outputPath = preg_replace('/\.\w+$/', '_marca.mp4', $videoPath);

    // ffmpeg: texto branco com fundo semi-transparente no canto inferior direito
    $drawtext = sprintf(
        "drawtext=text='%s':fontsize=24:fontcolor=white:borderw=2:bordercolor=black"
        . ":x=w-tw-20:y=h-th-20"
        . ":box=1:boxcolor=black@0.4:boxborderw=8",
        addcslashes($texto, "'\\")
    );

    $cmd = sprintf(
        'ffmpeg -y -i %s -vf %s -c:a copy -movflags +faststart %s 2>&1',
        escapeshellarg($videoPath),
        escapeshellarg($drawtext),
        escapeshellarg($outputPath)
    );

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0 || !file_exists($outputPath)) {
        logAssistente('aviso', 'midia', 'ffmpeg falhou, enviando video original', [
            'exit' => $exitCode,
            'output' => mb_substr(implode("\n", $output), -500),
        ]);
        return $videoPath;
    }

    logAssistente('info', 'midia', 'Marca dagua video OK: ' . round(filesize($outputPath) / 1024) . 'KB');
    return $outputPath;
}

/**
 * Envia foto via Telegram Bot API (sendPhoto com multipart upload).
 */
function telegramEnviarFoto($chatId, $fotoPath, $legenda = '', $extras = []) {
    if (!file_exists($fotoPath)) return false;

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendPhoto';
    $payload = array_merge([
        'chat_id' => $chatId,
        'photo' => new CURLFile($fotoPath, 'image/jpeg', 'instagram.jpg'),
    ], $extras);

    if ($legenda) {
        $payload['caption'] = mb_substr($legenda, 0, 1024);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resultado = curl_exec($ch);
    curl_close($ch);

    $resposta = json_decode($resultado, true);
    if (!($resposta['ok'] ?? false)) {
        logAssistente('aviso', 'telegram', 'Falha sendPhoto', [
            'erro' => $resposta['description'] ?? $resultado,
        ]);
        return false;
    }
    return true;
}

/**
 * Envia video via Telegram Bot API (sendVideo com multipart upload).
 */
function telegramEnviarVideo($chatId, $videoPath, $legenda = '', $extras = []) {
    if (!file_exists($videoPath)) return false;

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendVideo';

    // Detectar mime type
    $ext = strtolower(pathinfo($videoPath, PATHINFO_EXTENSION));
    $mime = ($ext === 'webm') ? 'video/webm' : 'video/mp4';

    $payload = array_merge([
        'chat_id' => $chatId,
        'video' => new CURLFile($videoPath, $mime, 'instagram.' . $ext),
        'supports_streaming' => 'true',
    ], $extras);

    if ($legenda) {
        $payload['caption'] = mb_substr($legenda, 0, 1024);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120, // videos maiores
    ]);
    $resultado = curl_exec($ch);
    curl_close($ch);

    $resposta = json_decode($resultado, true);
    if (!($resposta['ok'] ?? false)) {
        logAssistente('aviso', 'telegram', 'Falha sendVideo', [
            'erro' => $resposta['description'] ?? $resultado,
        ]);
        return false;
    }
    return true;
}

/**
 * Processa midia de um post Instagram: baixa + aplica marca dagua.
 * Retorna array ['path', 'tipo'] da midia processada ou null.
 */
function processarMidiaInstagram($url) {
    $midia = baixarMidiaInstagram($url);
    if (!$midia) return null;

    $path = $midia['path'];
    $tipo = $midia['tipo'];

    if ($tipo === 'video') {
        $processada = aplicarMarcaDaguaVideo($path);
    } else {
        $processada = aplicarMarcaDaguaImagem($path);
    }

    // Limpar original se gerou arquivo com marca
    if ($processada !== $path && file_exists($path)) {
        @unlink($path);
    }

    return ['path' => $processada, 'tipo' => $tipo];
}

/**
 * Limpa arquivos de midia antigos (mais de 1h).
 */
function limparMidiaAntiga() {
    if (!is_dir(MEDIA_DIR)) return;
    foreach (glob(MEDIA_DIR . '/*') as $f) {
        if (filemtime($f) < time() - 3600) @unlink($f);
    }
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

    // /reset — limpar sessao do topico/chat atual
    if ($texto === '/reset') {
        $sessaoKey = $threadId ? $chatId . ':' . $threadId : $chatId;
        // 1. Buscar sessoes ativas por GET
        $busca = supabaseFetch(
            'assistente_sessoes?canal=eq.telegram&chat_id=eq.' . urlencode($sessaoKey) . '&status=eq.ativa&select=id'
        );
        if ($busca['ok'] && !empty($busca['dados'])) {
            // 2. Encerrar cada sessao pelo id (PATCH por UUID — confiavel)
            $qtd = 0;
            foreach ($busca['dados'] as $sessao) {
                $patch = supabaseFetch('assistente_sessoes?id=eq.' . $sessao['id'], [
                    'metodo' => 'PATCH',
                    'corpo' => ['status' => 'encerrada', 'atualizado_em' => date('c')]
                ]);
                if ($patch['ok']) $qtd++;
            }
            $msg = "Sessao resetada ($qtd encerrada). Proxima mensagem inicia conversa nova.";
        } elseif ($busca['ok']) {
            $msg = "Nenhuma sessao ativa encontrada. Proxima mensagem ja inicia conversa nova.";
        } else {
            $msg = "Erro ao buscar sessao (HTTP {$busca['status']}). Tente novamente.";
            logAssistente('erro', 'reset', 'Falha GET sessao', [
                'sessaoKey' => $sessaoKey,
                'status' => $busca['status'],
                'resposta' => $busca['dados'],
            ]);
        }
        enviarTelegram($chatId, $msg, $extras);
        return true;
    }

    return false; // nao e comando
}