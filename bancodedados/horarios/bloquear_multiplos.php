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
    echo json_encode(['sucesso' => false, 'mensagem' => 'Usu&#65533;rio n&#65533;o autenticado']);
    exit;
}

$codigo_barbeiro = $_SESSION['usuario_codigo'];

// Receber dados do POST
$dados = json_decode(file_get_contents('php://input'), true);

if (!isset($dados['data']) || !isset($dados['horarios']) || !is_array($dados['horarios'])) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Data e hor&#65533;rios s&#65533;o obrigat&#65533;rios']);
    exit;
}

$data = $dados['data'];
$horarios = $dados['horarios'];
$motivo = isset($dados['motivo']) ? $dados['motivo'] : null;
$codigo_tipo_bloqueio = isset($dados['codigo_tipo_bloqueio']) ? $dados['codigo_tipo_bloqueio'] : 1;

try {
    $conn->beginTransaction();
    
    $bloqueados = 0;
    $erros = [];
    
    foreach ($horarios as $horario) {
        // Verificar se j&#65533; est&#65533; bloqueado
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
            $erros[] = "Hor&#65533;rio $horario j&#65533; bloqueado";
            continue;
        }
        
        // Verificar se h&#65533; agendamento
        $sql_agendamento = "SELECT codigo FROM agendamentos 
                            WHERE codigo_barbeiro = :codigo_barbeiro 
                            AND data_agendamento = :data_agendamento 
                            AND horario = :horario";
        
        $stmt_agendamento = $conn->prepare($sql_agendamento);
        $stmt_agendamento->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
        $stmt_agendamento->bindParam(':data_agendamento', $data);
        $stmt_agendamento->bindParam(':horario', $horario);
        $stmt_agendamento->execute();
        
        if ($stmt_agendamento->rowCount() > 0) {
            $erros[] = "Hor&#65533;rio $horario possui agendamento";
            continue;
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
        
        $bloqueados++;
    }
    
    $conn->commit();
    
    $mensagem = "$bloqueados hor&#65533;rio(s) bloqueado(s) com sucesso";
    if (!empty($erros)) {
        $mensagem .= ". Avisos: " . implode(", ", $erros);
    }
    
    echo json_encode([
        'sucesso' => true,
        'mensagem' => $mensagem,
        'bloqueados' => $bloqueados,
        'erros' => $erros
    ]);
    
} catch (PDOException $e) {
    $conn->rollBack();
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao bloquear hor&#65533;rios: ' . $e->getMessage()
    ]);
}
?>
