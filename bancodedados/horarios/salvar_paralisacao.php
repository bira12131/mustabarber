<?php
ini_set('display_errors', 0);
error_reporting(0);

session_start();
ob_clean();
header('Content-Type: application/json; charset=utf-8');

include_once '../conexao.php';

if (!isset($_SESSION['usuario_codigo'])) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Usu&aacute;rio n&atilde;o autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$codigo_barbeiro = $_SESSION['usuario_codigo'];
$dados = json_decode(file_get_contents('php://input'), true);

if (!isset($dados['data_inicio']) || empty($dados['data_inicio'])) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Data de in&iacute;cio &eacute; obrigat&oacute;ria'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data_inicio = $dados['data_inicio'];
$data_fim    = (isset($dados['data_fim']) && !empty($dados['data_fim'])) ? $dados['data_fim'] : null;
$motivo      = (isset($dados['motivo']) && !empty(trim($dados['motivo']))) ? trim($dados['motivo']) : null;

// Validação de data
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_inicio)) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Formato de data inv&aacute;lido'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($data_fim !== null) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Formato de data de fim inv&aacute;lido'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($data_fim < $data_inicio) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'A data de fim n&atilde;o pode ser anterior &agrave; data de in&iacute;cio'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    // Verificar se já existe paralisação ativa que conflite com o período
    $sqlConflito = "SELECT codigo FROM agenda_paralisacao
                    WHERE codigo_barbeiro = :codigo_barbeiro
                      AND ativa = TRUE
                      AND (
                            (data_fim IS NULL) OR
                            (:data_inicio_check <= data_fim)
                          )
                      AND data_inicio <= :data_fim_check";

    $dataFimCheck = $data_fim ?? '9999-12-31';
    $stmt = $conn->prepare($sqlConflito);
    $stmt->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
    $stmt->bindParam(':data_inicio_check', $data_inicio);
    $stmt->bindParam(':data_fim_check', $dataFimCheck);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'J&aacute; existe uma paralisa&ccedil;&atilde;o ativa que conflita com o per&iacute;odo informado. Cancele a paralisa&ccedil;&atilde;o existente antes de criar uma nova.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Inserir a paralisação
    $sql = "INSERT INTO agenda_paralisacao (codigo_barbeiro, data_inicio, data_fim, motivo, ativa)
            VALUES (:codigo_barbeiro, :data_inicio, :data_fim, :motivo, TRUE)";

    $stmtInsert = $conn->prepare($sql);
    $stmtInsert->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
    $stmtInsert->bindParam(':data_inicio', $data_inicio);
    $stmtInsert->bindParam(':data_fim', $data_fim);
    $stmtInsert->bindParam(':motivo', $motivo);
    $stmtInsert->execute();

    $tipo = $data_fim ? 'at&eacute; ' . date('d/m/Y', strtotime($data_fim)) : 'por tempo indeterminado';
    echo json_encode([
        'sucesso' => true,
        'mensagem' => "Agenda fechada com sucesso $tipo!"
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao salvar paralisa&ccedil;&atilde;o: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
