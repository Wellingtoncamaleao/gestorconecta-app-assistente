<?php
/**
 * DEBUG TEMPORARIO - verificar env vars no container
 * REMOVER APOS DEBUG
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// Testar Supabase GET direto
$ch = curl_init(SUPABASE_URL . '/rest/v1/assistente_sessoes?select=id,canal,chat_id,status&status=eq.ativa&limit=3');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 10,
]);
$resposta = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErro = curl_error($ch);
curl_close($ch);

echo json_encode([
    'supabase_url' => SUPABASE_URL ? 'configurado (' . strlen(SUPABASE_URL) . ' chars)' : 'VAZIO',
    'service_key' => SUPABASE_SERVICE_KEY ? 'configurado (' . strlen(SUPABASE_SERVICE_KEY) . ' chars)' : 'VAZIO',
    'service_key_inicio' => SUPABASE_SERVICE_KEY ? substr(SUPABASE_SERVICE_KEY, 0, 20) . '...' : 'VAZIO',
    'teste_get' => [
        'http_code' => $httpCode,
        'curl_erro' => $curlErro ?: null,
        'resposta' => json_decode($resposta, true) ?: $resposta,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
