<?php
/**
 * API para Voltar Agendamento (Reabrir agendamento finalizado ou cancelado)
 * Sistema: MustaBarber
 * Data: 18/12/2025
 */

// Iniciar buffer de sa&#65533;da para evitar sa&#65533;das inesperadas
ob_start();

header('Content-Type: application/json; charset=utf-8');

// Inicializar resposta padr&#65533;o
$resposta = [
    'sucesso' => false,
    'mensagem' => 'Erro desconhecido'
];

// Configurar tratamento de erros para capturar tudo
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    global $resposta;
    ob_get_clean();
    $resposta['mensagem'] = "Erro: $errstr (Arquivo: $errfile, Linha: $errline)";
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
    exit;
});

try {
    require_once '../conexao.php';
    require_once '../usuarios/sessao.php';
    
    if (!estaLogado()) {
        ob_get_clean();
        $resposta['mensagem'] = 'Usu&#65533;rio n&#65533;o autenticado';
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $inputData = file_get_contents('php://input');
    
    if (empty($inputData)) {
        ob_get_clean();
        $resposta['mensagem'] = 'Nenhum dado foi enviado';
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dados = json_decode($inputData, true);
    
    if ($dados === null) {
        ob_get_clean();
        $resposta['mensagem'] = 'Dados inv&#65533;lidos (JSON malformado)';
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (!isset($dados['codigo'])) {
        ob_get_clean();
        $resposta['mensagem'] = 'C&#65533;digo do agendamento n&#65533;o informado';
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $codigo = intval($dados['codigo']);
    $reabrirHorario = isset($dados['reabrir_horario']) ? boolval($dados['reabrir_horario']) : false;
    $desbloquearPermanente = isset($dados['desbloquear_permanente']) ? boolval($dados['desbloquear_permanente']) : false;
    
    // Obter usu&#65533;rio logado
    $usuarioLogado = obterUsuarioLogado();
    
    if (!$usuarioLogado) {
        ob_get_clean();
        $resposta['mensagem'] = 'Usu&#65533;rio logado n&#65533;o encontrado';
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Verificar se o agendamento existe e obter informa&#65533;&#65533;es
    $sqlCheck = "SELECT codigo_barbeiro, codigo_status, data_agendamento, horario, cliente_nome, cliente_telefone, codigo_servico, valor 
                 FROM agendamentos 
                 WHERE codigo = :codigo";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->execute([':codigo' => $codigo]);
    $agendamento = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$agendamento) {
        ob_get_clean();
        $resposta['mensagem'] = 'Agendamento n&#65533;o encontrado';
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if ($agendamento['codigo_barbeiro'] != $usuarioLogado['codigo']) {
        ob_get_clean();
        $resposta['mensagem'] = 'Voc&#65533; n&#65533;o tem permiss&#65533;o para alterar este agendamento';
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $statusAtual = intval($agendamento['codigo_status']);
    
    // Verificar se o status &#65533; 4 (finalizado) ou 5 (cancelado)
    if ($statusAtual != 4 && $statusAtual != 5) {
        ob_get_clean();
        $resposta['mensagem'] = 'Apenas agendamentos finalizados ou cancelados podem ser reabertos';
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Iniciar transa&#65533;&#65533;o
    $conn->beginTransaction();
    
    try {
        // Se status for 4 (finalizado), voltar para status 1 e apagar transa&#65533;&#65533;o
        if ($statusAtual == 4) {
            // Voltar status para 1 (agendado)
            $sqlUpdate = "UPDATE agendamentos 
                         SET codigo_status = 1,
                             data_atualizacao = NOW()
                         WHERE codigo = :codigo";
            
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->execute([':codigo' => $codigo]);
            
            // Apagar registro de transa&#65533;&#65533;o referente a esse agendamento
            $observacaoBusca = "Agendamento #" . $codigo;
            
            // Primeiro tentar busca exata
            $sqlDeleteTransacao = "DELETE FROM transacoes 
                                  WHERE observacoes = :observacao";
            
            $stmtDeleteTransacao = $conn->prepare($sqlDeleteTransacao);
            $resultado = $stmtDeleteTransacao->execute([':observacao' => $observacaoBusca]);
            
            // Se n&#65533;o encontrou, tentar com LIKE (para casos antigos ou com formata&#65533;&#65533;o diferente)
            if ($stmtDeleteTransacao->rowCount() == 0) {
                $sqlDeleteTransacaoLike = "DELETE FROM transacoes 
                                          WHERE observacoes LIKE :observacao";
                
                $stmtDeleteTransacaoLike = $conn->prepare($sqlDeleteTransacaoLike);
                $stmtDeleteTransacaoLike->execute([':observacao' => "%Agendamento #" . $codigo . "%"]);
            }
            
            $resposta['mensagem'] = 'Agendamento reaberto com sucesso. Transa&#65533;&#65533;o financeira removida.';
            
        } 
        // Se status for 5 (cancelado), voltar para status 1
        else if ($statusAtual == 5) {
            // Voltar status para 1 (agendado)
            $sqlUpdate = "UPDATE agendamentos 
                         SET codigo_status = 1,
                             data_atualizacao = NOW()
                         WHERE codigo = :codigo";
            
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->execute([':codigo' => $codigo]);
            
            // Se usu&#65533;rio optou por reabrir o hor&#65533;rio, desbloquear
            if ($reabrirHorario) {
                if ($desbloquearPermanente) {
                    // Desbloquear hor&#65533;rio em todos os dias futuros (usando >= ao inv&#65533;s de =)
                    $sqlDesbloquear = "DELETE FROM horarios_bloqueados 
                                      WHERE codigo_barbeiro = :codigo_barbeiro 
                                      AND horario = :horario
                                      AND data_bloqueio >= :data_bloqueio";
                    
                    $stmtDesbloquear = $conn->prepare($sqlDesbloquear);
                    $stmtDesbloquear->execute([
                        ':codigo_barbeiro' => $usuarioLogado['codigo'],
                        ':data_bloqueio' => $agendamento['data_agendamento'],
                        ':horario' => $agendamento['horario']
                    ]);
                    
                    $linhasAfetadas = $stmtDesbloquear->rowCount();
                    
                    if ($linhasAfetadas > 0) {
                        $resposta['mensagem'] = "Agendamento reaberto e hor&#65533;rio desbloqueado em $linhasAfetadas dia(s) futuro(s).";
                        $resposta['horario_desbloqueado'] = true;
                        $resposta['linhas_afetadas'] = $linhasAfetadas;
                    } else {
                        $resposta['mensagem'] = 'Agendamento reaberto com sucesso. Hor&#65533;rio n&#65533;o estava bloqueado.';
                        $resposta['horario_desbloqueado'] = false;
                    }
                } else {
                    // Desbloquear apenas no dia espec&#65533;fico
                    $sqlDesbloquear = "DELETE FROM horarios_bloqueados 
                                      WHERE codigo_barbeiro = :codigo_barbeiro 
                                      AND data_bloqueio = :data_bloqueio 
                                      AND horario = :horario";
                    
                    $stmtDesbloquear = $conn->prepare($sqlDesbloquear);
                    $stmtDesbloquear->execute([
                        ':codigo_barbeiro' => $usuarioLogado['codigo'],
                        ':data_bloqueio' => $agendamento['data_agendamento'],
                        ':horario' => $agendamento['horario']
                    ]);
                    
                    if ($stmtDesbloquear->rowCount() > 0) {
                        $resposta['mensagem'] = 'Agendamento reaberto e hor&#65533;rio desbloqueado com sucesso.';
                        $resposta['horario_desbloqueado'] = true;
                    } else {
                        $resposta['mensagem'] = 'Agendamento reaberto com sucesso. Hor&#65533;rio n&#65533;o estava bloqueado.';
                        $resposta['horario_desbloqueado'] = false;
                    }
                }
            } else {
                $resposta['mensagem'] = 'Agendamento reaberto com sucesso.';
            }
            
        }
        
        // Commit da transa&#65533;&#65533;o
        $conn->commit();
        
        // Verificar se a atualiza&#65533;&#65533;o foi bem-sucedida
        $sqlVerify = "SELECT codigo_status FROM agendamentos WHERE codigo = :codigo";
        $stmtVerify = $conn->prepare($sqlVerify);
        $stmtVerify->execute([':codigo' => $codigo]);
        $verify = $stmtVerify->fetch(PDO::FETCH_ASSOC);
        
        if ($verify && $verify['codigo_status'] == 1) {
            $resposta['sucesso'] = true;
            $resposta['status_atual'] = 1;
            $resposta['status_anterior'] = $statusAtual;
            

        } else {
            throw new Exception('Falha ao verificar o status do agendamento ap&#65533;s a atualiza&#65533;&#65533;o');
        }
        
    } catch (Exception $e) {
        if ($conn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    
} catch (Exception $e) {
    $resposta['sucesso'] = false;
    $resposta['mensagem'] = 'Erro ao voltar agendamento: ' . $e->getMessage();
}

// Limpar qualquer sa&#65533;da indesejada do buffer e enviar apenas JSON
$output = ob_get_clean();

// Garantir que apenas JSON seja enviado
header('Content-Type: application/json; charset=utf-8');
echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
exit;
?>
