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

$data_inicio = $dados['data'];
$horario = $dados['horario'];

try {
    // Desbloquear horário para todas as datas futuras a partir da data selecionada
    $sql_delete = "DELETE FROM horarios_bloqueados 
                   WHERE codigo_barbeiro = :codigo_barbeiro 
                   AND horario = :horario 
                   AND data_bloqueio >= :data_inicio";
    
    $stmt_delete = $conn->prepare($sql_delete);
    $stmt_delete->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
    $stmt_delete->bindParam(':horario', $horario);
    $stmt_delete->bindParam(':data_inicio', $data_inicio);
    $stmt_delete->execute();
    
    $linhas_afetadas = $stmt_delete->rowCount();
    
    if ($linhas_afetadas > 0) {
        $mensagem = "Hor&aacute;rio desbloqueado com sucesso!<br>";
        $mensagem .= "Total de $linhas_afetadas dia(s) desbloqueado(s) a partir de " . date('d/m/Y', strtotime($data_inicio));
        
        echo json_encode([
            'sucesso' => true,
            'mensagem' => $mensagem,
            'linhas_afetadas' => $linhas_afetadas
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Nenhum bloqueio encontrado para este hor&aacute;rio nas datas futuras'
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao desbloquear hor&aacute;rio: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
