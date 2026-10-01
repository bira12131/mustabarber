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

// Receber dados do GET
$data = isset($_GET['data']) ? $_GET['data'] : null;

try {
    // Se n�o houver data, retornar TODAS as configura��es dos 7 dias
    if ($data === null) {
        $sql_config = "SELECT dia_semana, horario_inicio, horario_fim, intervalo_minutos, ativo,
                              almoco_ativo, almoco_inicio, almoco_fim
                       FROM horarios_configuracao 
                       WHERE codigo_barbeiro = :codigo_barbeiro 
                       ORDER BY dia_semana";
        
        $stmt_config = $conn->prepare($sql_config);
        $stmt_config->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
        $stmt_config->execute();
        
        $configuracoes = $stmt_config->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'sucesso' => true,
            'configuracoes' => $configuracoes
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Se houver data, continuar com o fluxo original (buscar hor�rios de um dia espec�fico)
    $dia_semana = date('w', strtotime($data));
    
    // Buscar configura&#65533;&#65533;o do dia
    $sql_config = "SELECT horario_inicio, horario_fim, intervalo_minutos 
                   FROM horarios_configuracao 
                   WHERE codigo_barbeiro = :codigo_barbeiro 
                   AND dia_semana = :dia_semana 
                   AND ativo = true";
    
    $stmt_config = $conn->prepare($sql_config);
    $stmt_config->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
    $stmt_config->bindParam(':dia_semana', $dia_semana, PDO::PARAM_INT);
    $stmt_config->execute();
    
    $config = $stmt_config->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        echo json_encode([
            'sucesso' => true,
            'horarios' => [],
            'mensagem' => 'Nenhuma configura&ccedil;&atilde;o encontrada para este dia'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Gerar hor&#65533;rios do dia
    $horarios = [];
    $inicio = strtotime($config['horario_inicio']);
    $fim = strtotime($config['horario_fim']);
    $intervalo = $config['intervalo_minutos'] * 60; // Converter para segundos
    
    // IMPORTANTE: Incluir o hor&#65533;rio final se ele for exatamente no limite
    for ($time = $inicio; $time <= $fim; $time += $intervalo) {
        $horarios[] = date('H:i', $time);
    }
    
    // Buscar hor&#65533;rios bloqueados para esta data
    $sql_bloqueados = "SELECT horario 
                       FROM horarios_bloqueados 
                       WHERE codigo_barbeiro = :codigo_barbeiro 
                       AND data_bloqueio = :data_bloqueio";
    
    $stmt_bloqueados = $conn->prepare($sql_bloqueados);
    $stmt_bloqueados->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
    $stmt_bloqueados->bindParam(':data_bloqueio', $data);
    $stmt_bloqueados->execute();
    
    $bloqueados = $stmt_bloqueados->fetchAll(PDO::FETCH_COLUMN);
    
    // Buscar agendamentos para esta data (apenas confirmados - status 1 ou 2)
    $sql_agendamentos = "SELECT horario, cliente_nome, codigo_status
                         FROM agendamentos 
                         WHERE codigo_barbeiro = :codigo_barbeiro 
                         AND data_agendamento = :data_agendamento
                         AND codigo_status IN (1, 2)";
    
    $stmt_agendamentos = $conn->prepare($sql_agendamentos);
    $stmt_agendamentos->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
    $stmt_agendamentos->bindParam(':data_agendamento', $data);
    $stmt_agendamentos->execute();
    
    $agendamentos = [];
    while ($row = $stmt_agendamentos->fetch(PDO::FETCH_ASSOC)) {
        $agendamentos[$row['horario']] = [
            'cliente_nome' => $row['cliente_nome'],
            'codigo_status' => $row['codigo_status']
        ];
    }
    
    // Montar resposta com status de cada hor&#65533;rio
    $horarios_completos = [];
    foreach ($horarios as $horario) {
        $status = 'disponivel';
        $info = null;
        
        if (in_array($horario, $bloqueados)) {
            $status = 'bloqueado';
        } elseif (isset($agendamentos[$horario])) {
            $status = 'agendado';
            $info = $agendamentos[$horario];
        }
        
        $horarios_completos[] = [
            'horario' => $horario,
            'status' => $status,
            'info' => $info
        ];
    }
    
    echo json_encode([
        'sucesso' => true,
        'data' => $data,
        'dia_semana' => $dia_semana,
        'horarios' => $horarios_completos,
        'configuracao' => $config
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao buscar hor&aacute;rios: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
