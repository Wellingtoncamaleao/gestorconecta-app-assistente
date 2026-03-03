<?php
/**
 * ASSISTENTE - API do Painel Web
 * Substitui as chamadas diretas ao Supabase REST
 * Endpoints: sessoes, mensagens, memoria, logs
 */

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$acao = $_GET['acao'] ?? '';
$db = getDb();

switch ($acao) {
    case 'sessoes':
        $stmt = $db->query(
            'SELECT id, canal, chat_id, claude_session_id, titulo, status,
                    ferramenta, total_mensagens, ultima_mensagem_em, criado_em
             FROM assistente_sessoes
             ORDER BY ultima_mensagem_em DESC NULLS LAST
             LIMIT 20'
        );
        echo json_encode($stmt->fetchAll());
        break;

    case 'mensagens':
        $sessaoId = $_GET['sessao_id'] ?? '';
        if (!$sessaoId) responderErro('sessao_id obrigatorio');
        $stmt = $db->prepare(
            'SELECT id, direcao, conteudo, tipo, modelo, tokens_entrada, tokens_saida,
                    tempo_resposta_ms, criado_em
             FROM assistente_mensagens
             WHERE sessao_id = :sessao_id
             ORDER BY criado_em ASC
             LIMIT 100'
        );
        $stmt->execute([':sessao_id' => $sessaoId]);
        echo json_encode($stmt->fetchAll());
        break;

    case 'memoria':
        $stmt = $db->query(
            'SELECT id, categoria, chave, valor, relevancia, criado_em
             FROM assistente_memoria
             ORDER BY categoria ASC, chave ASC'
        );
        echo json_encode($stmt->fetchAll());
        break;

    case 'logs':
        $stmt = $db->query(
            'SELECT id, nivel, componente, mensagem, dados, criado_em
             FROM assistente_logs
             ORDER BY criado_em DESC
             LIMIT 50'
        );
        echo json_encode($stmt->fetchAll());
        break;

    default:
        responderErro('acao invalida: use sessoes, mensagens, memoria ou logs');
}
