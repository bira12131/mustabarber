<?php
ini_set('display_errors', 0);
error_reporting(0);

session_start();
ob_clean();
header('Content-Type: application/json; charset=utf-8');

include_once '../conexao.php';

if (!isset($_SESSION['usuario_codigo'])) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$codigo_barbeiro = $_SESSION['usuario_codigo'];
$dados = json_decode(file_get_contents('php://input'), true);

if (!isset($dados['codigo']) || !is_numeric($dados['codigo'])) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Código da paralisação é obrigatório'], JSON_UNESCAPED_UNICODE);
    exit;
}

$codigo = (int)$dados['codigo'];

try {
    // Verificar se pertence ao barbeiro logado
    $sqlCheck = "SELECT codigo FROM agenda_paralisacao
                 WHERE codigo = :codigo AND codigo_barbeiro = :codigo_barbeiro";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->bindParam(':codigo', $codigo, PDO::PARAM_INT);
    $stmtCheck->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
    $stmtCheck->execute();

    if ($stmtCheck->rowCount() === 0) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Paralisação não encontrada'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Cancelar (desativar)
    $sql = "UPDATE agenda_paralisacao SET ativa = FALSE WHERE codigo = :codigo";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':codigo', $codigo, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Paralisação cancelada. Agenda reaberta com sucesso!'
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao cancelar paralisação: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
