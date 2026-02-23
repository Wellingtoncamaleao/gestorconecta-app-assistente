<?php
/**
 * ASSISTENTE - Endpoint interno para enviar mensagem no Telegram
 * Chamado pelo worker Node.js via HTTP local
 */

require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderErro('Metodo nao permitido', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || empty($body['chat_id']) || empty($body['texto'])) {
    responderErro('chat_id e texto obrigatorios', 400);
}

$chatId = $body['chat_id'];
$texto = $body['texto'];
$extras = [];

// Reply ao message_id original
if (!empty($body['message_id'])) {
    $extras['reply_to_message_id'] = $body['message_id'];
}

enviarTelegram($chatId, $texto, $extras);

responderJson(['ok' => true]);