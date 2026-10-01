<?php
/**
 * API para Finalizar Agendamento
 * Sistema: MustaBarber
 * Data: 13/12/2025
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

    $codigo = intval($dados['codigo']);
    $codigoStatus = intval($dados['codigo_status'] ?? 3);
    $formaPagamento = $dados['forma_pagamento'] ?? 'dinheiro';
    $produtos = $dados['produtos'] ?? [];
    $valorServico = isset($dados['valor_servico']) ? floatval($dados['valor_servico']) : null;
    $valorTotal = floatval($dados['valor_total'] ?? 0);
    
    // Obter usu&#65533;rio logado
    $usuarioLogado = obterUsuarioLogado();
    
    if (!$usuarioLogado) {
        $resposta['mensagem'] = 'Usu&#65533;rio logado n&#65533;o encontrado';
        echo json_encode($resposta);
        exit;
    }
    
    // Verificar se o agendamento existe e pertence ao barbeiro
    $sqlCheck = "SELECT codigo_barbeiro, valor, codigo_status, grupo_recorrente FROM agendamentos WHERE codigo = :codigo";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->execute([':codigo' => $codigo]);
    $agendamento = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$agendamento) {
        $resposta['mensagem'] = 'Agendamento não encontrado';
        echo json_encode($resposta);
        exit;
    }
    
    if ($agendamento['codigo_barbeiro'] != $usuarioLogado['codigo']) {
        $resposta['mensagem'] = 'Você não tem permissão para finalizar este agendamento';
        echo json_encode($resposta);
        exit;
    }

    if ($valorServico !== null && $valorServico < 0) {
        $resposta['mensagem'] = 'Valor do serviço inválido';
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($valorTotal < 0) {
        $resposta['mensagem'] = 'Valor total inválido';
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($valorServico !== null && $valorTotal < $valorServico) {
        $valorTotal = $valorServico;
    }

    // PROTEÇÃO CONTRA DUPLICIDADE: verificar se agendamento já foi finalizado
    $statusJaFinalizado = [3, 4]; // 3 = concluído, 4 = finalizado
    if (in_array((int)$agendamento['codigo_status'], $statusJaFinalizado)) {
        $resposta['sucesso'] = true;
        $resposta['mensagem'] = 'Agendamento já estava finalizado';
        $resposta['ja_finalizado'] = true;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Buscar informa&#65533;&#65533;es do agendamento
    $sqlAgendamento = "SELECT a.codigo_barbeiro, a.data_agendamento, a.horario, a.cliente_nome, a.cliente_telefone,
                              a.valor, a.grupo_recorrente, s.nome as servico_nome 
                       FROM agendamentos a 
                       LEFT JOIN servicos s ON a.codigo_servico = s.codigo 
                       WHERE a.codigo = :codigo";
    $stmtAg = $conn->prepare($sqlAgendamento);
    $stmtAg->execute([':codigo' => $codigo]);
    $agInfo = $stmtAg->fetch(PDO::FETCH_ASSOC);
    
    if (!$agInfo) {
        $resposta['mensagem'] = 'N&#65533;o foi poss&#65533;vel carregar informa&#65533;&#65533;es do agendamento';
        echo json_encode($resposta);
        exit;
    }
    
    // Iniciar transa&#65533;&#65533;o
    $conn->beginTransaction();
    
    try {
        // Atualizar status do agendamento
        $sql = "UPDATE agendamentos 
                SET codigo_status = :codigo_status, 
                    valor = :valor_total,
                    data_atualizacao = NOW()
                WHERE codigo = :codigo";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':codigo_status' => $codigoStatus,
            ':valor_total' => $valorTotal,
            ':codigo' => $codigo
        ]);
        
        // Mapear forma de pagamento
        $formasPagamento = [
            'dinheiro' => 1,
            'debito' => 2,
            'credito' => 3,
            'pix' => 4
        ];
        $codigoFormaPagamento = $formasPagamento[$formaPagamento] ?? 1;
        
        $grupoRecorrente = trim((string)($agInfo['grupo_recorrente'] ?? ''));
        $ehPlanoRecorrente = ($grupoRecorrente !== '' && strpos($grupoRecorrente, 'plano_') === 0);

        // Montar descri&#65533;&#65533;o da transa&#65533;&#65533;o
        $descricaoTransacao = $ehPlanoRecorrente ? "Plano recorrente" : "Atendimento";
        if ($agInfo && !empty($agInfo['servico_nome'])) {
            $descricaoTransacao .= " - " . $agInfo['servico_nome'];
        }
        if ($agInfo && !empty($agInfo['data_agendamento'])) {
            $dataFormatada = date('d/m/Y', strtotime($agInfo['data_agendamento']));
            $horaFormatada = substr($agInfo['horario'] ?? '00:00', 0, 5);
            $descricaoTransacao .= " (" . $dataFormatada . " &#65533;s " . $horaFormatada . ")";
        }
        
        // PROTEÇÃO CONTRA DUPLICIDADE:
        // - Agendamento comum: 1 transação por agendamento
        // - Plano recorrente: 1 transação por grupo de plano
        $referencia = $ehPlanoRecorrente ? ("Plano #" . $grupoRecorrente) : ("Agendamento #" . $codigo);
        $sqlDupCheck = "SELECT COUNT(*) FROM transacoes WHERE observacoes = :ref AND codigo_barbeiro = :barbeiro";
        $stmtDup = $conn->prepare($sqlDupCheck);
        $stmtDup->execute([':ref' => $referencia, ':barbeiro' => $usuarioLogado['codigo']]);
        $jaExiste = (int) $stmtDup->fetchColumn();

        if ($jaExiste > 0) {
            if ($ehPlanoRecorrente) {
                // Plano base já lançado. Sessões seguintes podem lançar apenas extras.
                $valorExtra = max(0, $valorTotal);
                $referenciaExtra = "Extra Plano #" . $grupoRecorrente . " - Agendamento #" . $codigo;

                if ($valorExtra > 0) {
                    $stmtDupExtra = $conn->prepare("SELECT COUNT(*) FROM transacoes WHERE observacoes = :ref AND codigo_barbeiro = :barbeiro");
                    $stmtDupExtra->execute([':ref' => $referenciaExtra, ':barbeiro' => $usuarioLogado['codigo']]);
                    $jaExisteExtra = (int)$stmtDupExtra->fetchColumn();

                    if ($jaExisteExtra === 0) {
                        $descricaoExtra = "Extra de plano";
                        if (!empty($agInfo['servico_nome'])) {
                            $descricaoExtra .= " - " . $agInfo['servico_nome'];
                        }

                        $stmtTransacaoExtra = $conn->prepare(
                            "INSERT INTO transacoes
                             (tipo_transacao, descricao, valor, data_transacao, forma_pagamento,
                              categoria, codigo_barbeiro, status_pagamento, observacoes)
                             VALUES
                             (:tipo_transacao, :descricao, :valor, :data_transacao, :forma_pagamento,
                              :categoria, :codigo_barbeiro, :status_pagamento, :observacoes)"
                        );

                        $stmtTransacaoExtra->execute([
                            ':tipo_transacao' => 1,
                            ':descricao' => $descricaoExtra,
                            ':valor' => $valorExtra,
                            ':data_transacao' => $agInfo['data_agendamento'],
                            ':forma_pagamento' => $codigoFormaPagamento,
                            ':categoria' => 1,
                            ':codigo_barbeiro' => $usuarioLogado['codigo'],
                            ':status_pagamento' => 2,
                            ':observacoes' => $referenciaExtra
                        ]);
                    }
                }

                $conn->commit();
                $resposta['sucesso'] = true;
                $resposta['mensagem'] = 'Agendamento finalizado com sucesso';
                $resposta['plano_base_ja_lancado'] = true;
                $resposta['valor_extra_lancado'] = $valorExtra;
                echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Agendamento comum: transação já registrada — não inserir novamente
            $conn->commit();
            $resposta['sucesso'] = true;
            $resposta['mensagem'] = 'Agendamento finalizado com sucesso';
            $resposta['transacao_ja_registrada'] = true;
            echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Para plano recorrente, o valor contabilizado é o valor total do plano
        // e é lançado apenas uma vez (na primeira finalização do grupo).
        $valorTransacao = $valorTotal;
        if ($ehPlanoRecorrente) {
            if ($valorTransacao <= 0) {
                $valorTransacao = floatval($agInfo['valor'] ?? 0);
            }

            if ($valorTransacao < 0) {
                $valorTransacao = 0;
            }
        }

        // Inserir transação financeira (receita)
        $sqlTransacao = "INSERT INTO transacoes 
                        (tipo_transacao, descricao, valor, data_transacao, forma_pagamento, 
                         categoria, codigo_barbeiro, status_pagamento, observacoes) 
                        VALUES 
                        (:tipo_transacao, :descricao, :valor, :data_transacao, :forma_pagamento, 
                         :categoria, :codigo_barbeiro, :status_pagamento, :observacoes)";
        
        $stmtTransacao = $conn->prepare($sqlTransacao);
        $stmtTransacao->execute([
            ':tipo_transacao' => 1,
            ':descricao' => $descricaoTransacao,
            ':valor' => $valorTransacao,
            ':data_transacao' => $agInfo['data_agendamento'],
            ':forma_pagamento' => $codigoFormaPagamento,
            ':categoria' => 1,
            ':codigo_barbeiro' => $usuarioLogado['codigo'],
            ':status_pagamento' => 2,
            ':observacoes' => $referencia
        ]);
        
        // Commit da transa&#65533;&#65533;o
        $conn->commit();
        
        // Verificar se a atualiza&#65533;&#65533;o foi bem-sucedida
        $sqlVerify = "SELECT codigo_status FROM agendamentos WHERE codigo = :codigo";
        $stmtVerify = $conn->prepare($sqlVerify);
        $stmtVerify->execute([':codigo' => $codigo]);
        $verify = $stmtVerify->fetch(PDO::FETCH_ASSOC);
        
        if ($verify && $verify['codigo_status'] == $codigoStatus) {
            $resposta['sucesso'] = true;
            $resposta['mensagem'] = 'Agendamento finalizado com sucesso';
            $resposta['status_atual'] = $verify['codigo_status'];
        } else {
            throw new Exception('Falha ao atualizar o status do agendamento');
        }
        
    } catch (Exception $e) {
        if ($conn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        $resposta['sucesso'] = false;
        $resposta['mensagem'] = 'Erro na transa&#65533;&#65533;o: ' . $e->getMessage();
    }
    
} catch (Exception $e) {
    $resposta['sucesso'] = false;
    $resposta['mensagem'] = 'Erro ao finalizar agendamento: ' . $e->getMessage();
}

// Sempre retornar JSON
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}
echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
exit;
?>
