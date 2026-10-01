<?php
/**
 * API de Cadastro de Agendamentos - Cliente
 * Sistema: MustaBarber
 * Data: 20/12/2025
 * 
 * Este arquivo processa agendamentos feitos por clientes via formul&#65533;rio p&#65533;blico
 * Baseado no cadastro_agendamento.php original
 */

header('Content-Type: application/json; charset=utf-8');

// Inclui o arquivo de conex&#65533;o
require_once '../conexao.php';
require_once '../whatsapp/agendamento_messages.php';
require_once '../horarios/recalcular_horarios_dinamicos.php';

// Verifica se a requisi&#65533;&#65533;o &#65533; do tipo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'M&#65533;todo n&#65533;o permitido. Use POST.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Obt&#65533;m o c&#65533;digo do barbeiro do POST (ao inv&#65533;s da sess&#65533;o)
    $codBarbeiro = isset($_POST['cod_barbeiro']) ? intval($_POST['cod_barbeiro']) : null;
    
    // Recebe dados do cliente diretamente do formul&#65533;rio
    $clienteNome = trim($_POST['cliente_nome'] ?? '');
    $clienteTelefone = trim($_POST['cliente_telefone'] ?? '');
        // Remove caracteres especiais do telefone (par&#65533;nteses, tra&#65533;os, espa&#65533;os, etc.)
    $clienteTelefone = preg_replace('/[^0-9]/', '', $clienteTelefone);
        // Recebe os dados do formul&#65533;rio
    $dataAgendamento = trim($_POST['data_agendamento'] ?? '');
    $horaAgendamento = trim($_POST['hora_agendamento'] ?? '');
    $observacoes = trim($_POST['observacoes'] ?? '');
    $servicos = isset($_POST['servicos']) ? json_decode($_POST['servicos'], true) : [];
    
    // Valida&#65533;&#65533;es b&#65533;sicas
    if (empty($clienteNome)) {
        throw new Exception('Nome do cliente &#65533; obrigat&#65533;rio.');
    }
    
    if (empty($clienteTelefone)) {
        throw new Exception('Telefone do cliente &#65533; obrigat&#65533;rio.');
    }
    
    if (empty($codBarbeiro)) {
        throw new Exception('Barbeiro &#65533; obrigat&#65533;rio.');
    }
    
    if (empty($dataAgendamento)) {
        throw new Exception('Data do agendamento &#65533; obrigat&#65533;ria.');
    }
    
    if (empty($horaAgendamento)) {
        throw new Exception('Hora do agendamento &#65533; obrigat&#65533;ria.');
    }
    
    if (empty($servicos) || !is_array($servicos)) {
        throw new Exception('Selecione ao menos um servi&#65533;o.');
    }
    
    // Verifica ou cria o cliente vinculado ao barbeiro selecionado
    $stmtClienteExiste = $conn->prepare("SELECT codigo, nome, COALESCE(bloqueado, FALSE) AS bloqueado, motivo_bloqueio FROM clientes WHERE telefone = :telefone AND cod_barbeiro = :cod_barbeiro");
    $stmtClienteExiste->execute([':telefone' => $clienteTelefone, ':cod_barbeiro' => $codBarbeiro]);
    $clienteExiste = $stmtClienteExiste->fetch(PDO::FETCH_ASSOC);
    
    if ($clienteExiste) {
        $codCliente = $clienteExiste['codigo'];

        // Verificar se o cliente está bloqueado
        $bloqueadoVal = $clienteExiste['bloqueado'] ?? false;
        $clienteBloqueado = ($bloqueadoVal === true || $bloqueadoVal === 't' || $bloqueadoVal == 1);
        if ($clienteBloqueado) {
            $motivo = !empty($clienteExiste['motivo_bloqueio'])
                ? $clienteExiste['motivo_bloqueio']
                : 'Você está bloqueado e não pode realizar agendamentos com este profissional. Entre em contato com a barbearia para mais informações.';
            throw new Exception($motivo);
        }

        // Atualiza o nome se mudou
        $stmtAtualizaCliente = $conn->prepare("UPDATE clientes SET nome = :nome WHERE codigo = :codigo AND cod_barbeiro = :cod_barbeiro");
        $stmtAtualizaCliente->execute([':nome' => $clienteNome, ':codigo' => $codCliente, ':cod_barbeiro' => $codBarbeiro]);
    } else {
        // Cria novo cliente j� vinculado ao barbeiro correto
        $stmtNovoCliente = $conn->prepare("INSERT INTO clientes (nome, telefone, cod_barbeiro, dtnow) VALUES (:nome, :telefone, :cod_barbeiro, CURRENT_TIMESTAMP) RETURNING codigo");
        $stmtNovoCliente->execute([':nome' => $clienteNome, ':telefone' => $clienteTelefone, ':cod_barbeiro' => $codBarbeiro]);
        $codCliente = $stmtNovoCliente->fetchColumn();
    }
    
    // Valida se o barbeiro existe e está ativo
    $stmtBarbeiro = $conn->prepare("SELECT codigo, nome, status, isento_pagamento FROM usuarios WHERE codigo = :codigo");
    $stmtBarbeiro->execute([':codigo' => $codBarbeiro]);
    $barbeiroInfo = $stmtBarbeiro->fetch(PDO::FETCH_ASSOC);
    
    if (!$barbeiroInfo) {
        throw new Exception('Barbeiro não encontrado.');
    }

    if ((int)$barbeiroInfo['status'] !== 1) {
        throw new Exception('Este profissional não está disponível para agendamentos no momento.');
    }

    // Verifica se o plano do barbeiro está em dia
    $isento = ($barbeiroInfo['isento_pagamento'] === true || $barbeiroInfo['isento_pagamento'] === 't');

    if (!$isento) {
        // Verificar isenção por indicações
        $stmtInd = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM indicacoes i
             INNER JOIN usuarios u ON u.codigo = i.codigo_indicado
             WHERE i.codigo_indicador = :codigo
               AND i.convertido = TRUE
               AND u.status = 1"
        );
        $stmtInd->execute([':codigo' => $codBarbeiro]);
        $resInd = $stmtInd->fetch(PDO::FETCH_ASSOC);
        if ((int)$resInd['total'] >= 5) {
            $isento = true;
        }
    }

    if (!$isento) {
        $stmtAs = $conn->prepare(
            "SELECT status_pagamento, data_vencimento
             FROM assinaturas
             WHERE codigo_barbeiro = :codigo
             ORDER BY codigo DESC
             LIMIT 1"
        );
        $stmtAs->execute([':codigo' => $codBarbeiro]);
        $assinatura = $stmtAs->fetch(PDO::FETCH_ASSOC);

        if ($assinatura) {
            $hoje       = new DateTime('today');
            $vencimento = new DateTime($assinatura['data_vencimento']);
            $statusPag  = (int)$assinatura['status_pagamento'];

            if (!($statusPag === 2 && $vencimento >= $hoje)) {
                throw new Exception('Este profissional está com o plano vencido e não está recebendo agendamentos no momento.');
            }
        }
    }
    
    // Converte data para formato do banco (YYYY-MM-DD)
    $dataPartes = explode('/', $dataAgendamento);
    if (count($dataPartes) === 3) {
        $dataFormatada = $dataPartes[2] . '-' . $dataPartes[1] . '-' . $dataPartes[0];
    } else {
        $dataFormatada = $dataAgendamento; // Assume que j&#65533; est&#65533; no formato correto
    }
    
    // Verifica se j&#65533; existe agendamento para este barbeiro nesta data/hora
    $stmtVerifica = $conn->prepare("
        SELECT codigo FROM agendamentos 
        WHERE codigo_barbeiro = :cod_barbeiro 
        AND data_agendamento = :data 
        AND horario = :hora
        AND codigo_status NOT IN (4, 5, 6)
    ");
    $stmtVerifica->execute([
        ':cod_barbeiro' => $codBarbeiro,
        ':data' => $dataFormatada,
        ':hora' => $horaAgendamento
    ]);
    
    if ($stmtVerifica->rowCount() > 0) {
        throw new Exception('J&#65533; existe um agendamento para este barbeiro neste hor&#65533;rio.');
    }
    
    // Calcula o valor total dos servi&#65533;os
    $valorTotal = 0;
    $duracaoTotal = 0;
    $servicosDetalhados = [];
    
    foreach ($servicos as $servico) {
        $codServico = intval($servico['codigo']);
        $stmtServico = $conn->prepare("SELECT preco, duracao FROM servicos WHERE codigo = :codigo");
        $stmtServico->execute([':codigo' => $codServico]);
        $dadosServico = $stmtServico->fetch();
        
        if (!$dadosServico) {
            throw new Exception("Servi&#65533;o com c&#65533;digo {$codServico} n&#65533;o encontrado.");
        }
        
        $precoServico = floatval($dadosServico['preco']);
        $duracaoServico = intval($dadosServico['duracao']);

        $valorTotal += $precoServico;
        $duracaoTotal += $duracaoServico;
        $servicosDetalhados[] = [
            'codigo' => $codServico,
            'nome' => (string)($servico['nome'] ?? ''),
            'valor' => $precoServico,
            'duracao' => $duracaoServico
        ];
    }

    // Completar nomes dos servi&#65533;os caso o frontend n&#65533;o tenha enviado
    if (!empty($servicosDetalhados)) {
        $idsSemNome = [];
        foreach ($servicosDetalhados as $idx => $item) {
            if (trim((string)$item['nome']) === '') {
                $idsSemNome[] = intval($item['codigo']);
            }
        }

        if (!empty($idsSemNome)) {
            $placeholdersNomes = implode(',', array_fill(0, count($idsSemNome), '?'));
            $stmtServicosNome = $conn->prepare("SELECT codigo, nome FROM servicos WHERE codigo IN ($placeholdersNomes)");
            $stmtServicosNome->execute($idsSemNome);
            $mapNomes = [];
            foreach ($stmtServicosNome->fetchAll(PDO::FETCH_ASSOC) as $rowNome) {
                $mapNomes[intval($rowNome['codigo'])] = (string)$rowNome['nome'];
            }

            foreach ($servicosDetalhados as &$item) {
                if (trim((string)$item['nome']) === '' && isset($mapNomes[intval($item['codigo'])])) {
                    $item['nome'] = $mapNomes[intval($item['codigo'])];
                }
            }
            unset($item);
        }
    }

    $observacoesBase = trim((string)$observacoes);
    $metaServicos = [
        'itens' => $servicosDetalhados,
        'total_valor' => $valorTotal,
        'total_duracao' => $duracaoTotal
    ];
    $metaServicosEncoded = base64_encode(json_encode($metaServicos, JSON_UNESCAPED_UNICODE));
    $marcaMetaServicos = '[MB_SERVICOS]' . $metaServicosEncoded;
    $observacoesParaSalvar = $observacoesBase !== ''
        ? ($observacoesBase . "\n\n" . $marcaMetaServicos)
        : $marcaMetaServicos;

    // Validar conflito por sobreposicao considerando a duracao total dos servicos
    $inicioNovo = DateTime::createFromFormat('Y-m-d H:i', $dataFormatada . ' ' . substr($horaAgendamento, 0, 5));
    if (!$inicioNovo) {
        throw new Exception('Horario de agendamento invalido.');
    }

    // Verifica conflito com o horario de almoco configurado
    if (verificarConflitoAlmoco($conn, $codBarbeiro, $dataFormatada, $horaAgendamento, $duracaoTotal)) {
        throw new Exception('Este horario conflita com o horario de almoco do profissional. Escolha outro horario.');
    }

    $fimNovo = clone $inicioNovo;
    $fimNovo->modify('+' . max(1, $duracaoTotal) . ' minutes');

    $stmtConflitos = $conn->prepare(
        "SELECT codigo, horario, COALESCE(duracao_minutos, 30) AS duracao_minutos
         FROM agendamentos
         WHERE codigo_barbeiro = :cod_barbeiro
           AND data_agendamento = :data
           AND codigo_status NOT IN (4, 5, 6)"
    );
    $stmtConflitos->execute([
        ':cod_barbeiro' => $codBarbeiro,
        ':data' => $dataFormatada
    ]);

    $conflitos = $stmtConflitos->fetchAll(PDO::FETCH_ASSOC);
    foreach ($conflitos as $itemConflito) {
        $horaExistente = substr((string)$itemConflito['horario'], 0, 5);
        $inicioExistente = DateTime::createFromFormat('Y-m-d H:i', $dataFormatada . ' ' . $horaExistente);
        if (!$inicioExistente) {
            continue;
        }

        $fimExistente = clone $inicioExistente;
        $fimExistente->modify('+' . max(1, intval($itemConflito['duracao_minutos'])) . ' minutes');

        if ($inicioNovo < $fimExistente && $fimNovo > $inicioExistente) {
            throw new Exception('Este horario conflita com outro atendimento. Escolha outro horario disponivel.');
        }
    }

    // Verificar se o horário está bloqueado pelo profissional
    $stmtBloqueado = $conn->prepare("
        SELECT codigo FROM horarios_bloqueados
        WHERE codigo_barbeiro = :cod_barbeiro
        AND data_bloqueio = :data
        AND horario = :hora
        LIMIT 1
    ");
    $stmtBloqueado->execute([
        ':cod_barbeiro' => $codBarbeiro,
        ':data'         => $dataFormatada,
        ':hora'         => $horaAgendamento
    ]);
    if ($stmtBloqueado->rowCount() > 0) {
        throw new Exception('Este horário não está disponível para agendamento. Por favor, escolha outro horário.');
    }

    // Verificar se a data está em período de paralisação ativa
    $stmtParalisacao = $conn->prepare("
        SELECT codigo FROM agenda_paralisacao
        WHERE codigo_barbeiro = :cod_barbeiro
        AND ativa = TRUE
        AND data_inicio <= :data
        AND (data_fim IS NULL OR data_fim >= :data2)
        LIMIT 1
    ");
    $stmtParalisacao->execute([
        ':cod_barbeiro' => $codBarbeiro,
        ':data'         => $dataFormatada,
        ':data2'        => $dataFormatada
    ]);
    if ($stmtParalisacao->rowCount() > 0) {
        throw new Exception('O profissional não está recebendo agendamentos neste período. Por favor, tente outra data.');
    }

    // Como a tabela suporta apenas 1 servi&#65533;o, usamos o primeiro da lista
    $primeiroServico = intval($servicos[0]['codigo']);
    
    // Inicia transa&#65533;&#65533;o
    $conn->beginTransaction();
    
    // Insere o agendamento
    $stmtAgendamento = $conn->prepare("
        INSERT INTO agendamentos (
            codigo_barbeiro, 
            cliente_nome, 
            cliente_telefone, 
            data_agendamento, 
            horario, 
            codigo_servico,
            valor, 
            duracao_minutos,
            observacoes,
            codigo_status,
            data_criacao
        ) VALUES (
            :cod_barbeiro,
            :cliente_nome,
            :cliente_telefone,
            :data_agendamento,
            :horario,
            :codigo_servico,
            :valor,
            :duracao_minutos,
            :observacoes,
            1,
            CURRENT_TIMESTAMP
        ) RETURNING codigo
    ");
    
    $stmtAgendamento->execute([
        ':cod_barbeiro' => $codBarbeiro,
        ':cliente_nome' => $clienteNome,
        ':cliente_telefone' => $clienteTelefone,
        ':data_agendamento' => $dataFormatada,
        ':horario' => $horaAgendamento,
        ':codigo_servico' => $primeiroServico,
        ':valor' => $valorTotal,
        ':duracao_minutos' => $duracaoTotal,
        ':observacoes' => $observacoesParaSalvar
    ]);
    
    $agendamentoId = $stmtAgendamento->fetchColumn();
    
    // Confirma a transa&#65533;&#65533;o
    $conn->commit();
    
    // Buscar informa&#65533;&#65533;es dos servi&#65533;os para a mensagem
    $idsServicos = array_values(array_unique(array_map(function ($servico) {
        return intval($servico['codigo'] ?? 0);
    }, $servicos)));

    $nomeServico = 'Servi&#65533;o';
    if (count($idsServicos) > 0) {
        $placeholders = implode(',', array_fill(0, count($idsServicos), '?'));
        $stmtNomesServicos = $conn->prepare("SELECT nome FROM servicos WHERE codigo IN ($placeholders) ORDER BY nome ASC");
        $stmtNomesServicos->execute($idsServicos);
        $nomesServicos = $stmtNomesServicos->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($nomesServicos)) {
            $nomeServico = implode(' + ', $nomesServicos);
        }
    }
    
    // Buscar telefone do barbeiro
    $stmtBarbeiroTelefone = $conn->prepare("SELECT telefone FROM usuarios WHERE codigo = :codigo");
    $stmtBarbeiroTelefone->execute([':codigo' => $codBarbeiro]);
    $barbeiroTelefone = $stmtBarbeiroTelefone->fetch();
    $telefoneBarbeiro = $barbeiroTelefone ? $barbeiroTelefone['telefone'] : null;
    
    // Preparar dados do agendamento para a mensagem
    $agendamentoParaMensagem = [
        'codigo_barbeiro' => $codBarbeiro,
        'cliente_nome' => $clienteNome,
        'cliente_telefone' => $clienteTelefone,
        'data_agendamento' => $dataFormatada,
        'horario' => $horaAgendamento,
        'valor' => $valorTotal,
        'observacoes' => $observacoes
    ];
    
    // Enviar mensagem de confirma&#65533;&#65533;o do agendamento para o cliente
    $resultadoWhatsApp = enviarConfirmacaoAgendamento(
        $agendamentoParaMensagem,
        $nomeServico,
        $barbeiroInfo['nome'],
        $barbeiroTelefone ? $barbeiroTelefone['telefone'] : null,
        $codBarbeiro
    );

    error_log('[AG_PUBLICO] confirmacao_cliente cod_barbeiro=' . $codBarbeiro
        . ' agendamento=' . $agendamentoId
        . ' whatsapp_ok=' . (($resultadoWhatsApp['success'] ?? false) ? '1' : '0')
        . ' detalhe=' . ($resultadoWhatsApp['message'] ?? ''));
    
    // Enviar mensagem de confirma&#65533;&#65533;o para o barbeiro
    $resultadoWhatsAppBarbeiro = ['success' => false];
    if ($telefoneBarbeiro) {
        $resultadoWhatsAppBarbeiro = enviarConfirmacaoAgendamentoBarbeiro(
            $agendamentoParaMensagem, 
            $nomeServico, 
            $barbeiroInfo['nome'], 
            $telefoneBarbeiro
        );

        error_log('[AG_PUBLICO] confirmacao_barbeiro cod_barbeiro=' . $codBarbeiro
            . ' agendamento=' . $agendamentoId
            . ' whatsapp_ok=' . (($resultadoWhatsAppBarbeiro['success'] ?? false) ? '1' : '0')
            . ' detalhe=' . ($resultadoWhatsAppBarbeiro['message'] ?? ''));
    }
    
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Agendamento cadastrado com sucesso!',
        'codigo' => $agendamentoId,
        'valor_total' => $valorTotal,
        'duracao_total' => $duracaoTotal,
        'whatsapp_enviado' => $resultadoWhatsApp['success'] ?? false,
        'whatsapp_barbeiro_enviado' => $resultadoWhatsAppBarbeiro['success'] ?? false
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Em caso de erro, desfaz a transa&#65533;&#65533;o
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
