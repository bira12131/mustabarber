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
$motivo = isset($dados['motivo']) ? $dados['motivo'] : null;

try {
    // Bloquear horário para os próximos 365 dias a partir da data selecionada
    $conn->beginTransaction();
    
    $bloqueados = 0;
    $ja_bloqueados = 0;
    $com_agendamento = 0;
    
    for ($i = 0; $i < 365; $i++) {
        $data_atual = date('Y-m-d', strtotime($data_inicio . " +$i days"));
        
        // Verificar se já está bloqueado
        $sql_check = "SELECT codigo FROM horarios_bloqueados 
                      WHERE codigo_barbeiro = :codigo_barbeiro 
                      AND data_bloqueio = :data_bloqueio 
                      AND horario = :horario";
        
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
        $stmt_check->bindParam(':data_bloqueio', $data_atual);
        $stmt_check->bindParam(':horario', $horario);
        $stmt_check->execute();
        
        if ($stmt_check->rowCount() > 0) {
            $ja_bloqueados++;
            continue;
        }
        
        // Verificar se já existe agendamento
        $sql_agendamento = "SELECT codigo FROM agendamentos 
                            WHERE codigo_barbeiro = :codigo_barbeiro 
                            AND data_agendamento = :data_agendamento 
                            AND horario = :horario 
                            AND codigo_status IN (1, 2)";
        
        $stmt_agendamento = $conn->prepare($sql_agendamento);
        $stmt_agendamento->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
        $stmt_agendamento->bindParam(':data_agendamento', $data_atual);
        $stmt_agendamento->bindParam(':horario', $horario);
        $stmt_agendamento->execute();
        
        if ($stmt_agendamento->rowCount() > 0) {
            $com_agendamento++;
            continue;
        }
        
        // Inserir bloqueio
        $sql_insert = "INSERT INTO horarios_bloqueados 
                       (codigo_barbeiro, data_bloqueio, horario, motivo, codigo_tipo_bloqueio, criado_por) 
                       VALUES (:codigo_barbeiro, :data_bloqueio, :horario, :motivo, 1, :criado_por)";
        
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
        $stmt_insert->bindParam(':data_bloqueio', $data_atual);
        $stmt_insert->bindParam(':horario', $horario);
        $stmt_insert->bindParam(':motivo', $motivo);
        $stmt_insert->bindParam(':criado_por', $codigo_barbeiro, PDO::PARAM_INT);
        $stmt_insert->execute();
        
        $bloqueados++;
    }
    
    $conn->commit();
    
    $mensagem = "Hor&aacute;rio bloqueado com sucesso!<br>";
    $mensagem .= "- Bloqueados: $bloqueados dias<br>";
    if ($ja_bloqueados > 0) {
        $mensagem .= "- J&aacute; bloqueados: $ja_bloqueados dias<br>";
    }
    if ($com_agendamento > 0) {
        $mensagem .= "- Com agendamento (n&atilde;o bloqueados): $com_agendamento dias";
    }
    
    echo json_encode([
        'sucesso' => true,
        'mensagem' => $mensagem,
        'detalhes' => [
            'bloqueados' => $bloqueados,
            'ja_bloqueados' => $ja_bloqueados,
            'com_agendamento' => $com_agendamento
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao bloquear hor&aacute;rio: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
