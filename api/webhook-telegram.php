<?php
/**
 * ASSISTENTE - Webhook Telegram Bot API
 * URL: https://assistente.gestorconecta.com.br/api/webhook-telegram.php
 * Configurar: https://api.telegram.org/bot{TOKEN}/setWebhook?url={URL}
 */

require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderErro('Metodo nao permitido', 405);
}

$body = file_get_contents('php://input');
$payload = json_decode($body, true);

if (!$payload) {
    responderErro('Payload invalido', 400);
}

// Deduplicacao: ignorar update_id ja processado
$updateId = $payload['update_id'] ?? null;
if ($updateId) {
    $dedup_dir = '/var/assistente/dedup';
    if (!is_dir($dedup_dir)) mkdir($dedup_dir, 0755, true);
    $dedup_file = $dedup_dir . '/' . $updateId;
    if (file_exists($dedup_file)) {
        responderJson(['ok' => true]); // ja processado
    }
    file_put_contents($dedup_file, '1');
    // Limpar dedup antigos (mais de 1h)
    foreach (glob($dedup_dir . '/*') as $f) {
        if (filemtime($f) < time() - 3600) unlink($f);
    }
}

// Extrair mensagem
$mensagem = $payload['message'] ?? $payload['edited_message'] ?? null;
if (!$mensagem) {
    responderJson(['ok' => true]); // callback_query, etc — ignorar
}

$chatId = (string)($mensagem['chat']['id'] ?? '');
$texto = $mensagem['text'] ?? '';
$messageId = $mensagem['message_id'] ?? null;
$nome = $mensagem['from']['first_name'] ?? 'Usuario';
$threadId = isset($mensagem['message_thread_id']) ? (string)$mensagem['message_thread_id'] : null;
$chatType = $mensagem['chat']['type'] ?? 'private';

// Seguranca: whitelist (aceita chat_id direto OU grupo)
if (!in_array($chatId, TELEGRAM_CHAT_IDS)) {
    // Em grupos, verificar se o remetente esta na whitelist
    $userId = (string)($mensagem['from']['id'] ?? '');
    if (!in_array($userId, TELEGRAM_CHAT_IDS)) {
        logAssistente('aviso', 'webhook-telegram', 'Nao autorizado: chat=' . $chatId . ' user=' . $userId);
        responderJson(['ok' => true]); // Ignorar silenciosamente em grupos
    }
}

// Ignorar mensagens vazias (stickers, midia sem legenda, etc.)
if (empty(trim($texto))) {
    responderJson(['ok' => true]);
}

// Processar comandos internos (/ferramentas, /mapear, /desmapear, /topicos)
if (strpos($texto, '/') === 0 && processarComando($texto, $chatId, $threadId)) {
    responderJson(['ok' => true]);
}

// Chave da sessao: chat_id + thread_id (para Topics)
// Em chat direto: thread_id = null, sessao por chat_id
// Em grupo com Topics: cada topico = sessao separada
$sessaoKey = $threadId ? $chatId . ':' . $threadId : $chatId;

// Detectar ferramenta pelo mapeamento do topico
$ferramenta = detectarFerramenta($chatId, $threadId);

// Enriquecer mensagem com dados de Instagram (se tiver links + ferramenta compativel)
$textoEnriquecido = $texto;
$mediaPath = null;
if ($ferramenta === 'instagram-replicar') {
    $linksInsta = detectarLinksInstagram($texto);
    if (!empty($linksInsta)) {
        $textoEnriquecido = enriquecerMensagemInstagram($texto);
        logAssistente('info', 'webhook-telegram', 'Instagram: ' . count($linksInsta) . ' link(s) enriquecido(s)');

        // Baixar midia + aplicar marca dagua + enviar ao Telegram
        $midia = processarMidiaInstagram($linksInsta[0]);
        if ($midia) {
            $extrasMedia = [];
            if ($threadId) $extrasMedia['message_thread_id'] = (int)$threadId;
            if ($midia['tipo'] === 'video') {
                telegramEnviarVideo($chatId, $midia['path'], MARCA_DAGUA_TEXTO, $extrasMedia);
            } else {
                telegramEnviarFoto($chatId, $midia['path'], MARCA_DAGUA_TEXTO, $extrasMedia);
            }
            logAssistente('info', 'webhook-telegram', $midia['tipo'] . ' com marca dagua enviado');
            limparMidiaAntiga();
        }
    }
}

// Indicar que esta processando
telegramDigitando($chatId, $threadId);

// Buscar ou criar sessao
$sessao = buscarOuCriarSessao('telegram', $sessaoKey);

// Salvar mensagem recebida
salvarMensagem($sessao['id'], 'telegram', $sessaoKey, 'recebida', $texto, [
    'telegram_message_id' => $messageId,
    'thread_id' => $threadId,
    'nome' => $nome,
    'ferramenta' => $ferramenta,
]);

// Criar item na fila para o worker processar (mensagem enriquecida com dados de scraping)
criarItemFila([
    'canal' => 'telegram',
    'chat_id' => $chatId,
    'thread_id' => $threadId,
    'ferramenta' => $ferramenta,
    'mensagem' => $textoEnriquecido,
    'sessao_id' => $sessao['id'],
    'claude_session_id' => $sessao['claude_session_id'],
    'message_id' => $messageId,
]);

// Responder 200 imediatamente (processamento assincrono)
responderJson(['ok' => true]);