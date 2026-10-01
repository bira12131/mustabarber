<?php
// Desabilitar exibi&#65533;&#65533;o de erros para n&#65533;o corromper JSON
ini_set('display_errors', 0);
error_reporting(0);

// Verificar se h&#65533; sess&#65533;o ativa
session_start();

// Limpar qualquer sa&#65533;da anterior
ob_clean();

// Definir header JSON
header('Content-Type: application/json; charset=utf-8');

// Incluir arquivo de conex&#65533;o
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
$motivo = isset($dados['motivo']) ? $dados['motivo'] : null;
$codigo_tipo_bloqueio = isset($dados['codigo_tipo_bloqueio']) ? $dados['codigo_tipo_bloqueio'] : 1; // 1 = individual

try {
    // Verificar se o hor&#65533;rio j&#65533; est&#65533; bloqueado
    $sql_check = "SELECT codigo FROM horarios_bloqueados 
                  WHERE codigo_barbeiro = :codigo_barbeiro 
                  AND data_bloqueio = :data_bloqueio 
                  AND horario = :horario";
    
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
    $stmt_check->bindParam(':data_bloqueio', $data);
    $stmt_check->bindParam(':horario', $horario);
    $stmt_check->execute();
    
    if ($stmt_check->rowCount() > 0) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Este hor&aacute;rio j&aacute; est&aacute; bloqueado'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Inserir bloqueio
    $sql = "INSERT INTO horarios_bloqueados 
            (codigo_barbeiro, data_bloqueio, horario, motivo, codigo_tipo_bloqueio, criado_por)
            VALUES (:codigo_barbeiro, :data_bloqueio, :horario, :motivo, :codigo_tipo_bloqueio, :criado_por)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
    $stmt->bindParam(':data_bloqueio', $data);
    $stmt->bindParam(':horario', $horario);
    $stmt->bindParam(':motivo', $motivo);
    $stmt->bindParam(':codigo_tipo_bloqueio', $codigo_tipo_bloqueio, PDO::PARAM_INT);
    $stmt->bindParam(':criado_por', $codigo_barbeiro, PDO::PARAM_INT);
    $stmt->execute();
    
    // Obter o c&#65533;digo do bloqueio rec&#65533;m-criado
    $codigoBloqueio = $conn->lastInsertId();
    
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Hor&aacute;rio bloqueado com sucesso',
        'codigo_bloqueio' => $codigoBloqueio
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao bloquear hor&aacute;rio: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
