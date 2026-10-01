<?php
// Desabilitar exibição de erros para não corromper JSON
ini_set('display_errors', 0);
error_reporting(0);

// Verificar se há sessão ativa
session_start();

// Limpar qualquer saída anterior
ob_clean();

// Definir header JSON
header('Content-Type: application/json; charset=utf-8');

// Incluir arquivo de conexão
include_once '../conexao.php';
if (!isset($_SESSION['usuario_codigo'])) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Usu&aacute;rio n&atilde;o autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$codigo_barbeiro = $_SESSION['usuario_codigo'];

// Receber dados do POST
$dados = json_decode(file_get_contents('php://input'), true);

if (!isset($dados['data']) || !isset($dados['horario'])) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Data e hor&aacute;rio s&atilde;o obrigat&oacute;rios'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = $dados['data'];
$horario = $dados['horario'];

try {
    // Deletar bloqueio
    $sql = "DELETE FROM horarios_bloqueados 
            WHERE codigo_barbeiro = :codigo_barbeiro 
            AND data_bloqueio = :data_bloqueio 
            AND horario = :horario";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
    $stmt->bindParam(':data_bloqueio', $data);
    $stmt->bindParam(':horario', $horario);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Hor&aacute;rio desbloqueado com sucesso'
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Hor&aacute;rio n&atilde;o estava bloqueado'
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao desbloquear hor&aacute;rio: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
