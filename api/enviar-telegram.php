<?php
/**
 * ASSISTENTE - Endpoint interno para enviar mensagem/midia no Telegram
 * Chamado pelo worker Node.js via HTTP local
 * Suporta: texto (sendMessage), foto (sendPhoto)
 */

require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderErro('Metodo nao permitido', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || empty($body['chat_id'])) {
    responderErro('chat_id obrigatorio', 400);
}

$chatId = $body['chat_id'];
$tipo = $body['tipo'] ?? 'texto';
$extras = [];

// Responder no topico correto (Topics/Forum)
if (!empty($body['thread_id'])) {
    $extras['message_thread_id'] = (int)$body['thread_id'];
}

// Reply ao message_id original
if (!empty($body['message_id'])) {
    $extras['reply_to_message_id'] = $body['message_id'];
}

if ($tipo === 'foto' && !empty($body['media_path'])) {
    $legenda = $body['legenda'] ?? '';
    telegramEnviarFoto($chatId, $body['media_path'], $legenda, $extras);
} elseif ($tipo === 'video' && !empty($body['media_path'])) {
    $legenda = $body['legenda'] ?? '';
    telegramEnviarVideo($chatId, $body['media_path'], $legenda, $extras);
} else {
    if (empty($body['texto'])) {
        responderErro('texto obrigatorio para tipo texto', 400);
    }
    enviarTelegram($chatId, $body['texto'], $extras);
}

responderJson(['ok' => true]);
