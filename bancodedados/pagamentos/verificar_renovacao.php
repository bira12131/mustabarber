<?php
/**
 * Verificar Renovação Antecipada
 * Sistema: MustaBarber
 *
 * Verifica se a assinatura foi renovada comparando o data_vencimento
 * atual com o vencimento anterior informado pelo frontend.
 * Usado pelo polling de confirmação após "Renovar Antecipado".
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(0);

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'renovado' => false, 'mensagem' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario       = obterUsuarioLogado();
    $codigoUsuario = (int) $usuario['codigo'];

    // Vencimento anterior enviado pelo frontend (formato Y-m-d)
    $vencAnterior = trim($_GET['vencimento_anterior'] ?? '');
    if (!$vencAnterior || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vencAnterior)) {
        echo json_encode(['sucesso' => false, 'renovado' => false, 'mensagem' => 'Parâmetro inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $conexao->prepare(
        "SELECT data_vencimento, status_pagamento
         FROM assinaturas
         WHERE codigo_barbeiro = :cod
         ORDER BY codigo DESC LIMIT 1"
    );
    $stmt->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
    $stmt->execute();
    $assinatura = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assinatura) {
        echo json_encode(['sucesso' => true, 'renovado' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $vencAtual = $assinatura['data_vencimento'];
    $renovado  = ($vencAtual > $vencAnterior && (int)$assinatura['status_pagamento'] === 2);

    echo json_encode([
        'sucesso'          => true,
        'renovado'         => $renovado,
        'data_vencimento'  => date('d/m/Y', strtotime($vencAtual)),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[verificar_renovacao] ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'renovado' => false, 'mensagem' => 'Erro interno'], JSON_UNESCAPED_UNICODE);
}
?>
