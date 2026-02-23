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

// Extrair mensagem
$mensagem = $payload['message'] ?? $payload['edited_message'] ?? null;
if (!$mensagem) {
    responderJson(['ok' => true]); // callback_query, etc — ignorar
}

$chatId = (string)($mensagem['chat']['id'] ?? '');
$texto = $mensagem['text'] ?? '';
$messageId = $mensagem['message_id'] ?? null;
$nome = $mensagem['from']['first_name'] ?? 'Usuario';

// Seguranca: whitelist
if (!in_array($chatId, TELEGRAM_CHAT_IDS)) {
    logAssistente('aviso', 'webhook-telegram', 'Chat ID nao autorizado: ' . $chatId);
    enviarTelegram($chatId, 'Acesso nao autorizado.');
    responderJson(['ok' => true]);
}

// Ignorar mensagens vazias (stickers, midia sem legenda, etc.)
if (empty(trim($texto))) {
    responderJson(['ok' => true]);
}

// Indicar que esta processando
telegramDigitando($chatId);

// Buscar ou criar sessao
$sessao = buscarOuCriarSessao('telegram', $chatId);

// Salvar mensagem recebida
salvarMensagem($sessao['id'], 'telegram', $chatId, 'recebida', $texto, [
    'telegram_message_id' => $messageId,
    'nome' => $nome,
]);

// Criar item na fila para o worker processar
criarItemFila([
    'canal' => 'telegram',
    'chat_id' => $chatId,
    'mensagem' => $texto,
    'sessao_id' => $sessao['id'],
    'claude_session_id' => $sessao['claude_session_id'],
    'message_id' => $messageId,
]);

// Responder 200 imediatamente (processamento assincrono)
responderJson(['ok' => true]);