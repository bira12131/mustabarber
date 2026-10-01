<?php
/**
 * Cancelar solicitação de plano pelo cliente
 * Sistema: MustaBarber
 * Endpoint público — identificação por telefone
 * POST JSON: { codigo, cliente_telefone }
 */

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Dados inválidos'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $codigo    = isset($input['codigo'])           ? intval($input['codigo'])         : 0;
    $telefone  = isset($input['cliente_telefone']) ? trim($input['cliente_telefone']) : '';

    if ($codigo <= 0 || empty($telefone)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Dados insuficientes'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Verificar que a solicitação pertence ao telefone e ainda está pendente
    $stmtCheck = $conexao->prepare(
        "SELECT codigo, status FROM solicitacoes_plano_cliente
         WHERE codigo = :cod AND cliente_telefone = :tel LIMIT 1"
    );
    $stmtCheck->bindParam(':cod', $codigo, PDO::PARAM_INT);
    $stmtCheck->bindParam(':tel', $telefone);
    $stmtCheck->execute();
    $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Solicitação não encontrada'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!in_array($row['status'], ['pendente', 'aguardando_confirmacao_cliente'])) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Apenas solicitações pendentes ou aguardando confirmação podem ser canceladas'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $conexao->prepare(
        "UPDATE solicitacoes_plano_cliente
         SET status = 'cancelado_cliente', data_resposta = NOW()
         WHERE codigo = :cod AND cliente_telefone = :tel"
    );
    $stmt->bindParam(':cod', $codigo, PDO::PARAM_INT);
    $stmt->bindParam(':tel', $telefone);
    $stmt->execute();

    echo json_encode(['sucesso' => true, 'mensagem' => 'Solicitação cancelada com sucesso'], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[cancelar_solicitacao_plano] ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno'], JSON_UNESCAPED_UNICODE);
}
?>
