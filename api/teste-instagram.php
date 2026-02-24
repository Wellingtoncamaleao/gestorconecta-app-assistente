<?php
/**
 * TESTE - Scraper Instagram
 * URL: https://assistente.gestorconecta.com.br/api/teste-instagram.php?url=LINK
 * Uso temporario para debug. Remover depois.
 */

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$url = $_GET['url'] ?? '';
if (!$url) {
    echo json_encode(['erro' => 'Parametro ?url= obrigatorio'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Verificar se token esta configurado
$tokenStatus = defined('FACEBOOK_APP_TOKEN') && FACEBOOK_APP_TOKEN
    ? 'configurado (' . strlen(FACEBOOK_APP_TOKEN) . ' chars)'
    : 'AUSENTE';

// Testar deteccao de link
$links = detectarLinksInstagram($url);

// Testar scraping
$dados = buscarDadosInstagram($url);

// Testar oEmbed direto (para debug)
$oembedDebug = null;
if (defined('FACEBOOK_APP_TOKEN') && FACEBOOK_APP_TOKEN) {
    $urlLimpa = preg_replace('/[?&](igsh|utm_\w+)=[^&]*/', '', $url);
    $urlLimpa = rtrim($urlLimpa, '?&');
    $oembedUrl = 'https://graph.facebook.com/v21.0/instagram_oembed'
        . '?url=' . urlencode($urlLimpa)
        . '&access_token=' . urlencode(FACEBOOK_APP_TOKEN)
        . '&omitscript=true';

    $ch = curl_init($oembedUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resposta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErro = curl_error($ch);
    curl_close($ch);

    $oembedDebug = [
        'http_code' => $httpCode,
        'curl_erro' => $curlErro ?: null,
        'resposta' => json_decode($resposta, true) ?: $resposta,
    ];
}

echo json_encode([
    'facebook_token' => $tokenStatus,
    'url_original' => $url,
    'links_detectados' => $links,
    'oembed_debug' => $oembedDebug,
    'dados_extraidos' => $dados,
    'texto_enriquecido' => $dados ? montarDescricaoInstagram($dados) : null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);