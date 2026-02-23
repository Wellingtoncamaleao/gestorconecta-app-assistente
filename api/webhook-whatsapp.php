<?php
/**
 * ASSISTENTE - Webhook WhatsApp via Evolution API
 * URL: https://assistente.gestorconecta.com.br/api/webhook-whatsapp.php
 * Configurar webhook na Evolution API apontando para esta URL
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

// Evolution API envia varios tipos de evento
$evento = $payload['event'] ?? '';

// Processar apenas mensagens recebidas
if ($evento !== 'messages.upsert') {
    responderJson(['ok' => true]);
}

$dados = $payload['data'] ?? [];
$chave = $dados['key'] ?? [];

// Ignorar mensagens enviadas por nos (fromMe)
if ($chave['fromMe'] ?? false) {
    responderJson(['ok' => true]);
}

$chatId = $chave['remoteJid'] ?? '';
$texto = $dados['message']['conversation']
    ?? $dados['message']['extendedTextMessage']['text']
    ?? '';
$messageId = $chave['id'] ?? null;
$nome = $dados['pushName'] ?? 'Usuario';

// Seguranca: whitelist
if (!in_array($chatId, WHATSAPP_CHAT_IDS)) {
    logAssistente('aviso', 'webhook-whatsapp', 'Chat ID nao autorizado: ' . $chatId);
    responderJson(['ok' => true]);
}

// Ignorar mensagens vazias
if (empty(trim($texto))) {
    responderJson(['ok' => true]);
}

// Buscar ou criar sessao
$sessao = buscarOuCriarSessao('whatsapp', $chatId);

// Salvar mensagem recebida
salvarMensagem($sessao['id'], 'whatsapp', $chatId, 'recebida', $texto, [
    'whatsapp_message_id' => $messageId,
    'nome' => $nome,
]);

// Criar item na fila
criarItemFila([
    'canal' => 'whatsapp',
    'chat_id' => $chatId,
    'mensagem' => $texto,
    'sessao_id' => $sessao['id'],
    'claude_session_id' => $sessao['claude_session_id'],
    'message_id' => $messageId,
]);

responderJson(['ok' => true]);