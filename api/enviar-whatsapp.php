<?php
/**
 * ASSISTENTE - Endpoint interno para enviar mensagem no WhatsApp
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

enviarWhatsApp($body['chat_id'], $body['texto']);

responderJson(['ok' => true]);