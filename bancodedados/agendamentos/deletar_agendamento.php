<?php
/**
 * API para Deletar Agendamento
 * Sistema: MustaBarber
 * Data: 13/12/2025
 */

header('Content-Type: application/json; charset=utf-8');

try {
    require_once '../conexao.php';
    require_once '../usuarios/sessao.php';
    require_once '../whatsapp/agendamento_messages.php';
    
    if (!estaLogado()) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usu�rio n�o autenticado'
        ]);
        exit;
    }

    $dados = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($dados['codigo'])) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'C�digo do agendamento n�o informado'
        ]);
        exit;
    }

    $codigo = intval($dados['codigo']);
    
    // Verificar se o agendamento pertence ao barbeiro logado
    $usuarioLogado = obterUsuarioLogado();
    
    $sqlCheck = "SELECT codigo_barbeiro, cliente_nome, cliente_telefone, data_agendamento, horario, codigo_servico, valor FROM agendamentos WHERE codigo = :codigo";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->execute([':codigo' => $codigo]);
    $agendamento = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$agendamento) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Agendamento n�o encontrado'
        ]);
        exit;
    }
    
    if ($agendamento['codigo_barbeiro'] != $usuarioLogado['codigo']) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Voc� n�o tem permiss�o para deletar este agendamento'
        ]);
        exit;
    }
    
    // Buscar nome do servi�o
    $stmtServico = $conn->prepare("SELECT nome FROM servicos WHERE codigo = :codigo");
    $stmtServico->execute([':codigo' => $agendamento['codigo_servico']]);
    $servicoDados = $stmtServico->fetch(PDO::FETCH_ASSOC);
    $nomeServico = $servicoDados ? $servicoDados['nome'] : 'Servi�o';
    
    // Deletar o agendamento
    $sql = "DELETE FROM agendamentos WHERE codigo = :codigo";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':codigo' => $codigo]);
    
    // Preparar dados do agendamento para a mensagem
    $agendamentoParaMensagem = [
        'codigo_barbeiro' => $agendamento['codigo_barbeiro'],
        'cliente_nome' => $agendamento['cliente_nome'],
        'cliente_telefone' => $agendamento['cliente_telefone'],
        'data_agendamento' => $agendamento['data_agendamento'],
        'horario' => $agendamento['horario'],
        'valor' => $agendamento['valor']
    ];
    
    // Enviar mensagem de cancelamento
    $resultadoWhatsApp = enviarCancelamentoAgendamento($agendamentoParaMensagem, $nomeServico, $usuarioLogado['nome'], $agendamento['codigo_barbeiro']);
    
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Agendamento deletado com sucesso',
        'whatsapp_enviado' => $resultadoWhatsApp['success'] ?? false
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao deletar agendamento: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
