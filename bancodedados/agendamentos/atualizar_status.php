<?php
/**
 * API para Atualizar Status do Agendamento
 * Sistema: MustaBarber
 * Data: 17/12/2025
 */

header('Content-Type: application/json; charset=utf-8');

// Inicializar resposta padr&#65533;o
$resposta = [
    'sucesso' => false,
    'mensagem' => 'Erro desconhecido'
];

// Configurar tratamento de erros para capturar tudo
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    global $resposta;
    $resposta['mensagem'] = "Erro: $errstr (Arquivo: $errfile, Linha: $errline)";
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
    exit;
});

try {
    require_once '../conexao.php';
    require_once '../usuarios/sessao.php';
    require_once '../whatsapp/agendamento_messages.php';
    
    if (!estaLogado()) {
        $resposta['mensagem'] = 'Usu&#65533;rio n&#65533;o autenticado';
        echo json_encode($resposta);
        exit;
    }

    $inputData = file_get_contents('php://input');
    
    if (empty($inputData)) {
        $resposta['mensagem'] = 'Nenhum dado foi enviado';
        echo json_encode($resposta);
        exit;
    }

    $dados = json_decode($inputData, true);
    
    if ($dados === null) {
        $resposta['mensagem'] = 'Dados inv&#65533;lidos (JSON malformado)';
        echo json_encode($resposta);
        exit;
    }
    
    if (!isset($dados['codigo'])) {
        $resposta['mensagem'] = 'C&#65533;digo do agendamento n&#65533;o informado';
        echo json_encode($resposta);
        exit;
    }

    if (!isset($dados['codigo_status'])) {
        $resposta['mensagem'] = 'C&#65533;digo do status n&#65533;o informado';
        echo json_encode($resposta);
        exit;
    }

    $codigo = intval($dados['codigo']);
    $codigoStatus = intval($dados['codigo_status']);
    
    // Obter usu&#65533;rio logado
    $usuarioLogado = obterUsuarioLogado();
    
    if (!$usuarioLogado) {
        $resposta['mensagem'] = 'Usu&#65533;rio logado n&#65533;o encontrado';
        echo json_encode($resposta);
        exit;
    }
    
    // Verificar se o agendamento existe e pertence ao barbeiro
    $sqlCheck = "SELECT codigo_barbeiro, cliente_nome, cliente_telefone, data_agendamento, horario, codigo_servico, valor FROM agendamentos WHERE codigo = :codigo";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->execute([':codigo' => $codigo]);
    $agendamento = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$agendamento) {
        $resposta['mensagem'] = 'Agendamento n&#65533;o encontrado';
        echo json_encode($resposta);
        exit;
    }
    
    if ($agendamento['codigo_barbeiro'] != $usuarioLogado['codigo']) {
        $resposta['mensagem'] = 'Voc&#65533; n&#65533;o tem permiss&#65533;o para atualizar este agendamento';
        echo json_encode($resposta);
        exit;
    }
    
    // Atualizar apenas o status do agendamento.
    // O campo codigo_horario_bloqueado nao existe mais na tabela agendamentos.
    $sql = "UPDATE agendamentos 
            SET codigo_status = :codigo_status,
                data_atualizacao = NOW()
            WHERE codigo = :codigo";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':codigo_status' => $codigoStatus,
        ':codigo' => $codigo
    ]);
    
    // Verificar se a atualiza&#65533;&#65533;o foi bem-sucedida
    $sqlVerify = "SELECT codigo_status FROM agendamentos WHERE codigo = :codigo";
    $stmtVerify = $conn->prepare($sqlVerify);
    $stmtVerify->execute([':codigo' => $codigo]);
    $verify = $stmtVerify->fetch(PDO::FETCH_ASSOC);
    
    if ($verify && $verify['codigo_status'] == $codigoStatus) {
        $resposta['sucesso'] = true;
        $resposta['mensagem'] = 'Status atualizado com sucesso';
        $resposta['status_atual'] = $verify['codigo_status'];
        
        // Se status foi alterado para cancelado (5), enviar mensagem
        if ($codigoStatus == 5) {
            try {
                // Buscar nome do servi�o
                $stmtServico = $conn->prepare("SELECT nome FROM servicos WHERE codigo = :codigo");
                $stmtServico->execute([':codigo' => $agendamento['codigo_servico']]);
                $servicoDados = $stmtServico->fetch(PDO::FETCH_ASSOC);
                $nomeServico = $servicoDados ? $servicoDados['nome'] : 'Servi�o';
                
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
                $resposta['whatsapp_enviado'] = $resultadoWhatsApp['success'] ?? false;
            } catch (Exception $e) {
                // Log do erro mas n�o impede a resposta
                error_log('Erro ao enviar mensagem WhatsApp: ' . $e->getMessage());
                $resposta['whatsapp_erro'] = $e->getMessage();
            }
        }
    } else {
        throw new Exception('Falha ao atualizar o status do agendamento');
    }
    
} catch (Exception $e) {
    $resposta['sucesso'] = false;
    $resposta['mensagem'] = 'Erro ao atualizar status: ' . $e->getMessage();
}

// Sempre retornar JSON
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}
echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
exit;
?>