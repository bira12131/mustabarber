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

// Incluir arquivo de conex&#65533;o e fun&#65533;&#65533;es de rec&#65533;lculo
include_once '../conexao.php';
include_once 'recalcular_horarios_dinamicos.php';
if (!isset($_SESSION['usuario_codigo'])) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Usu&aacute;rio n&atilde;o autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$codigo_barbeiro = (isset($_GET['cod_barbeiro']) && $_GET['cod_barbeiro'] !== '')
    ? intval($_GET['cod_barbeiro'])
    : $_SESSION['usuario_codigo'];

// Receber dados do GET
$data = isset($_GET['data']) ? $_GET['data'] : date('Y-m-d');

try {
    // Buscar dia da semana da data
    $dia_semana = date('w', strtotime($data));
    
    // Buscar configura&#65533;&#65533;o do dia
    $sql_config = "SELECT horario_inicio, horario_fim, intervalo_minutos,
                          almoco_ativo, almoco_inicio, almoco_fim
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
    
    // NOVA L&#65533;GICA: Usar rec&#65533;lculo din&#65533;mico de hor&#65533;rios
    $resultado = recalcularHorariosDinamicos($conn, $codigo_barbeiro, $data, $config);
    // Usa a grade completa (todos os hor&#65533;rios do dia), sem remover os que
    // est&#65533;o ocupados por dura&#65533;&#65533;o de outro agendamento, pois esta tela
    // (Altera&#65533;&#65533;o de hor&#65533;rio) precisa exibir e permitir escolher
    // qualquer hor&#65533;rio do dia, mesmo os bloqueados/ocupados.
    $horarios = $resultado['horarios_base'];
    $agendamentos = $resultado['agendamentos'];
    
    // Buscar hor&#65533;rios bloqueados para esta data
    $bloqueados = buscarHorariosBloqueados($conn, $codigo_barbeiro, $data);
    
    // Montar resposta com status de cada hor&#65533;rio
    $horarios_completos = montarRespostaHorarios($horarios, $bloqueados, $agendamentos, $config);
    
    echo json_encode([
        'sucesso' => true,
        'data' => $data,
        'dia_semana' => $dia_semana,
        'horarios' => $horarios_completos,
        'configuracao' => $config,
        'recalculado' => $resultado['recalculado']
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao buscar hor&aacute;rios: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
