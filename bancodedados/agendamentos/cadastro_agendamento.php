<?php
/**
 * API de Cadastro de Agendamentos
 * Sistema: MustaBarber
 * Data: 11/12/2025
 * 
 * Este arquivo processa o cadastro de novos agendamentos no sistema
 */

header('Content-Type: application/json; charset=utf-8');

// Inclui o arquivo de conex&#65533;o e sess&#65533;o
require_once '../conexao.php';
require_once '../usuarios/sessao.php';require_once '../whatsapp/agendamento_messages.php';
require_once '../horarios/recalcular_horarios_dinamicos.php';
// Verifica se o usu&#65533;rio est&#65533; logado
if (!estaLogado()) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Usu&#65533;rio n&#65533;o autenticado. Fa&#65533;a login novamente.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Verifica se a requisi&#65533;&#65533;o &#65533; do tipo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'M&#65533;todo n&#65533;o permitido. Use POST.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Obt&#65533;m o c&#65533;digo do barbeiro da sess&#65533;o
    $usuarioLogado = obterUsuarioLogado();
    $codBarbeiro = $usuarioLogado['codigo'];
    
    // Recebe os dados do formul&#65533;rio
    $codCliente = isset($_POST['cod_cliente']) ? intval($_POST['cod_cliente']) : null;
    $dataAgendamento = trim($_POST['data_agendamento'] ?? '');
    $horaAgendamento = trim($_POST['hora_agendamento'] ?? '');
    $observacoes = trim($_POST['observacoes'] ?? '');
    $servicos = isset($_POST['servicos']) ? json_decode($_POST['servicos'], true) : [];
    $confirmarHorarioBloqueado = isset($_POST['confirmar_horario_bloqueado']) && $_POST['confirmar_horario_bloqueado'] === '1';
    $permitirEncaixe = isset($_POST['permitir_encaixe']) && $_POST['permitir_encaixe'] === '1';
    
    // Dados de agendamento recorrente
    $isRecorrente = isset($_POST['is_recorrente']) && $_POST['is_recorrente'] === '1';
    $duracaoSemanas = isset($_POST['duracao_semanas']) ? intval($_POST['duracao_semanas']) : 0;
    
    // Valida&#65533;&#65533;es b&#65533;sicas
    if (empty($codCliente)) {
        throw new Exception('Cliente &#65533; obrigat&#65533;rio.');
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
    
    // Valida&#65533;&#65533;o adicional para agendamentos recorrentes
    if ($isRecorrente) {
        if ($duracaoSemanas < 1 || $duracaoSemanas > 52) {
            throw new Exception('A dura\u00e7\u00e3o do agendamento fixo deve ser entre 1 e 52 semanas.');
        }
    }
    
    // Valida se o cliente existe
    $stmtCliente = $conn->prepare("SELECT codigo, COALESCE(bloqueado, FALSE) AS bloqueado, motivo_bloqueio FROM clientes WHERE codigo = :codigo");
    $stmtCliente->execute([':codigo' => $codCliente]);
    $clienteInfo = $stmtCliente->fetch(PDO::FETCH_ASSOC);
    if (!$clienteInfo) {
        throw new Exception('Cliente não encontrado.');
    }

    // Verifica se o cliente está bloqueado
    $clienteBloqueado = ($clienteInfo['bloqueado'] === true || $clienteInfo['bloqueado'] === 't' || $clienteInfo['bloqueado'] == 1);
    if ($clienteBloqueado) {
        $motivo = !empty($clienteInfo['motivo_bloqueio'])
            ? $clienteInfo['motivo_bloqueio']
            : 'Este cliente está bloqueado e não pode realizar agendamentos.';
        throw new Exception($motivo);
    }
    
    // Valida se o barbeiro existe
    $stmtBarbeiro = $conn->prepare("SELECT codigo FROM usuarios WHERE codigo = :codigo");
    $stmtBarbeiro->execute([':codigo' => $codBarbeiro]);
    if ($stmtBarbeiro->rowCount() === 0) {
        throw new Exception('Barbeiro n&#65533;o encontrado.');
    }
    
    // Converte data para formato do banco (YYYY-MM-DD)
    $dataPartes = explode('/', $dataAgendamento);
    if (count($dataPartes) === 3) {
        $dataFormatada = $dataPartes[2] . '-' . $dataPartes[1] . '-' . $dataPartes[0];
    } else {
        $dataFormatada = $dataAgendamento; // Assume que j&#65533; est&#65533; no formato correto
    }
    
    // Se for agendamento recorrente, verificar todos os hor\u00e1rios
    $datasParaVerificar = [$dataFormatada];
    if ($isRecorrente) {
        for ($i = 1; $i < $duracaoSemanas; $i++) {
            $proximaData = date('Y-m-d', strtotime($dataFormatada . " +{$i} weeks"));
            $datasParaVerificar[] = $proximaData;
        }
    }
    
    // Verifica se j&#65533; existe agendamento para alguma das datas
    $datasComConflitoExato = [];
    foreach ($datasParaVerificar as $dataVerificar) {
        $stmtVerifica = $conn->prepare("
            SELECT codigo FROM agendamentos 
            WHERE codigo_barbeiro = :cod_barbeiro 
            AND data_agendamento = :data 
            AND horario = :hora
            AND codigo_status IN (1, 2)
        ");
        $stmtVerifica->execute([
            ':cod_barbeiro' => $codBarbeiro,
            ':data' => $dataVerificar,
            ':hora' => $horaAgendamento
        ]);
        
        if ($stmtVerifica->rowCount() > 0) {
            $datasComConflitoExato[] = date('d/m/Y', strtotime($dataVerificar));
        }
    }

    if (count($datasComConflitoExato) > 0) {
        $datasTexto = implode(', ', $datasComConflitoExato);
        if (count($datasComConflitoExato) === 1) {
            throw new Exception('Já existe um agendamento nesse mesmo horário na data ' . $datasTexto . '. Para encaixe, selecione um horário diferente do já ocupado.');
        } else {
            throw new Exception('Já existem agendamentos nesse mesmo horário nas datas: ' . $datasTexto . '. Para encaixe, selecione um horário diferente do já ocupado.');
        }
    }
    
    // Calcula o valor total dos servi&#65533;os
    $valorTotal = 0;
    $duracaoTotal = 0;
    
    foreach ($servicos as $servico) {
        $codServico = intval($servico['codigo']);
        $stmtServico = $conn->prepare("SELECT preco, duracao FROM servicos WHERE codigo = :codigo");
        $stmtServico->execute([':codigo' => $codServico]);
        $dadosServico = $stmtServico->fetch();
        
        if (!$dadosServico) {
            throw new Exception("Servi&#65533;o com c&#65533;digo {$codServico} n&#65533;o encontrado.");
        }
        
        $valorTotal += floatval($dadosServico['preco']);
        $duracaoTotal += intval($dadosServico['duracao']);
    }

    // Verifica bloqueios (manual e almoço) e exige confirmação explícita para permitir override.
    $datasBloqueadas = [];
    foreach ($datasParaVerificar as $dataVerificar) {
        $motivosData = [];

        $stmtBloqueioManual = $conn->prepare(
            "SELECT 1
             FROM horarios_bloqueados
             WHERE codigo_barbeiro = :cod_barbeiro
               AND data_bloqueio = :data
               AND TO_CHAR(horario, 'HH24:MI') = :hora
             LIMIT 1"
        );
        $stmtBloqueioManual->execute([
            ':cod_barbeiro' => $codBarbeiro,
            ':data' => $dataVerificar,
            ':hora' => substr($horaAgendamento, 0, 5),
        ]);

        if ($stmtBloqueioManual->fetchColumn()) {
            $motivosData[] = 'Bloqueio manual';
        }

        if (verificarConflitoAlmoco($conn, $codBarbeiro, $dataVerificar, $horaAgendamento, $duracaoTotal)) {
            $motivosData[] = 'Horário de almoço';
        }

        if (!empty($motivosData)) {
            $datasBloqueadas[] = [
                'data' => date('d/m/Y', strtotime($dataVerificar)),
                'motivos' => $motivosData,
            ];
        }
    }

    if (!empty($datasBloqueadas) && !$confirmarHorarioBloqueado) {
        if (count($datasBloqueadas) === 1) {
            $item = $datasBloqueadas[0];
            $motivos = implode(' e ', $item['motivos']);
            throw new Exception('__CONFIRMAR_HORARIO_BLOQUEADO__O horário escolhido está bloqueado em ' . $item['data'] . ' (' . $motivos . '). Confirme para agendar mesmo assim.');
        }

        $datasTexto = implode(', ', array_map(function ($item) {
            return $item['data'];
        }, $datasBloqueadas));

        throw new Exception('__CONFIRMAR_HORARIO_BLOQUEADO__O horário escolhido está bloqueado nas datas: ' . $datasTexto . '. Confirme para agendar mesmo assim.');
    }
    
    // Verificação de sobreposição de horários considerando a duração total do serviço.
    // Detecta conflitos mesmo quando o horário escolhido não é o exato, mas o serviço
    // invade o horário de outro agendamento existente (ou vice-versa).
    $datasComSobreposicao = [];
    foreach ($datasParaVerificar as $dataVerificar) {
        // Pula datas que já tiveram conflito exato detectado acima
        if (in_array(date('d/m/Y', strtotime($dataVerificar)), $datasComConflitoExato)) {
            continue;
        }
        $stmtOverlap = $conn->prepare("
            SELECT codigo FROM agendamentos
            WHERE codigo_barbeiro = :cod_barbeiro
              AND data_agendamento = :data
                            AND codigo_status IN (1, 2)
              AND :hora::time < (horario + (COALESCE(duracao_minutos, 30) || ' minutes')::interval)
              AND horario        < (:hora::time + (:dur || ' minutes')::interval)
            LIMIT 1
        ");
        $stmtOverlap->execute([
            ':cod_barbeiro' => $codBarbeiro,
            ':data'         => $dataVerificar,
            ':hora'         => $horaAgendamento,
            ':dur'          => $duracaoTotal,
        ]);
        if ($stmtOverlap->rowCount() > 0) {
            $datasComSobreposicao[] = date('d/m/Y', strtotime($dataVerificar));
        }
    }

    if (count($datasComSobreposicao) > 0 && !$permitirEncaixe) {
        $datasTexto = implode(', ', $datasComSobreposicao);
        if (count($datasComSobreposicao) === 1) {
            throw new Exception('__CONFIRMAR_ENCAIXE__O serviço de ' . $duracaoTotal . ' min às ' . $horaAgendamento . ' sobrepõe outro atendimento na data ' . $datasTexto . '. Deseja confirmar o encaixe mesmo assim?');
        } else {
            throw new Exception('__CONFIRMAR_ENCAIXE__O serviço de ' . $duracaoTotal . ' min sobrepõe atendimentos existentes nas datas: ' . $datasTexto . '. Deseja confirmar o encaixe mesmo assim?');
        }
    }
        // Como a tabela suporta apenas 1 servi&#65533;o, usamos o primeiro da lista
    $primeiroServico = intval($servicos[0]['codigo']);
        // Inicia transa&#65533;&#65533;o
    $conn->beginTransaction();
    
    // Buscar informa&#65533;&#65533;es do cliente
    $stmtClienteInfo = $conn->prepare("SELECT nome, telefone FROM clientes WHERE codigo = :codigo");
    $stmtClienteInfo->execute([':codigo' => $codCliente]);
    $clienteInfo = $stmtClienteInfo->fetch();
    
    // Gerar identificador &#65533;nico para grupo de agendamentos recorrentes (se aplic&#65533;vel)
    $grupoRecorrente = null;
    if ($isRecorrente) {
        $grupoRecorrente = uniqid('recorrente_', true);
    }
    
    // Array para armazenar os IDs dos agendamentos criados
    $agendamentosCriados = [];
    
    // Criar agendamentos (um ou m&#65533;ltiplos se recorrente)
    $totalAgendamentos = $isRecorrente ? $duracaoSemanas : 1;
    
    for ($i = 0; $i < $totalAgendamentos; $i++) {
        // Calcular a data do agendamento
        $dataAtual = $i === 0 ? $dataFormatada : date('Y-m-d', strtotime($dataFormatada . " +{$i} weeks"));
        
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
                is_recorrente,
                grupo_recorrente,
                data_original_recorrencia,
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
                :is_recorrente,
                :grupo_recorrente,
                :data_original,
                CURRENT_TIMESTAMP
            ) RETURNING codigo
        ");
        
        $stmtAgendamento->execute([
            ':cod_barbeiro' => $codBarbeiro,
            ':cliente_nome' => $clienteInfo['nome'],
            ':cliente_telefone' => $clienteInfo['telefone'],
            ':data_agendamento' => $dataAtual,
            ':horario' => $horaAgendamento,
            ':codigo_servico' => $primeiroServico,
            ':valor' => $valorTotal,
            ':duracao_minutos' => $duracaoTotal,
            ':observacoes' => $observacoes,
            ':is_recorrente' => $isRecorrente ? 'true' : 'false',
            ':grupo_recorrente' => $grupoRecorrente,
            ':data_original' => $isRecorrente ? $dataFormatada : null
        ]);
        
        $agendamentoId = $stmtAgendamento->fetchColumn();
        $agendamentosCriados[] = $agendamentoId;
    }
    
    // O primeiro agendamento ser&#65533; usado como refer&#65533;ncia
    $primeiroAgendamentoId = $agendamentosCriados[0];
    
    // Confirma a transa&#65533;&#65533;o
    $conn->commit();
    
    // Buscar informa&#65533;&#65533;es do servi&#65533;o para a mensagem
    $stmtNomeServico = $conn->prepare("SELECT nome FROM servicos WHERE codigo = :codigo");
    $stmtNomeServico->execute([':codigo' => $primeiroServico]);
    $nomeServicoDados = $stmtNomeServico->fetch();
    $nomeServico = $nomeServicoDados ? $nomeServicoDados['nome'] : 'Servi&#65533;o';
    
    // Buscar telefone do barbeiro
    $stmtBarbeiroInfo = $conn->prepare("SELECT telefone FROM usuarios WHERE codigo = :codigo");
    $stmtBarbeiroInfo->execute([':codigo' => $codBarbeiro]);
    $barbeiroInfo = $stmtBarbeiroInfo->fetch();
    $telefoneBarbeiro = $barbeiroInfo ? $barbeiroInfo['telefone'] : null;
    
    // Preparar dados do agendamento para a mensagem
    $agendamentoParaMensagem = [
        'codigo_barbeiro' => $codBarbeiro,
        'cliente_nome' => $clienteInfo['nome'],
        'cliente_telefone' => $clienteInfo['telefone'],
        'data_agendamento' => $dataFormatada,
        'horario' => $horaAgendamento,
        'valor' => $valorTotal,
        'observacoes' => $observacoes
    ];
    
    // Enviar mensagem de confirma&#65533;&#65533;o do agendamento para o cliente (apenas para o primeiro, se recorrente)
    $resultadoWhatsApp = enviarConfirmacaoAgendamento($agendamentoParaMensagem, $nomeServico, $usuarioLogado['nome'], null, $codBarbeiro);
    
    // Enviar mensagem de confirma&#65533;&#65533;o para o barbeiro
    $resultadoWhatsAppBarbeiro = ['success' => false];
    if ($telefoneBarbeiro) {
        $resultadoWhatsAppBarbeiro = enviarConfirmacaoAgendamentoBarbeiro(
            $agendamentoParaMensagem, 
            $nomeServico, 
            $usuarioLogado['nome'], 
            $telefoneBarbeiro
        );
    }
    
    // Preparar resposta
    $resposta = [
        'sucesso' => true,
        'mensagem' => $isRecorrente ? "{$totalAgendamentos} agendamentos fixos cadastrados com sucesso!" : 'Agendamento cadastrado com sucesso!',
        'codigo' => $primeiroAgendamentoId,
        'valor_total' => $valorTotal,
        'duracao_total' => $duracaoTotal,
        'whatsapp_enviado' => $resultadoWhatsApp['success'] ?? false,
        'whatsapp_barbeiro_enviado' => $resultadoWhatsAppBarbeiro['success'] ?? false
    ];
    
    // Adicionar informa&#65533;&#65533;es sobre agendamentos recorrentes
    if ($isRecorrente) {
        $resposta['agendamentos_criados'] = count($agendamentosCriados);
        $resposta['grupo_recorrente'] = $grupoRecorrente;
        $resposta['duracao_semanas'] = $duracaoSemanas;
    }
    
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Em caso de erro, desfaz a transa&#65533;&#65533;o
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }

    $mensagem = $e->getMessage();
    if ($e instanceof PDOException && $e->getCode() === '23505' && strpos($mensagem, 'unique_agendamento_ativo') !== false) {
        $mensagem = 'Já existe um agendamento ativo neste mesmo horário. Para encaixe, selecione um horário diferente do já ocupado.';
    }
    $requerConfirmacaoHorarioBloqueado = false;
    $requerConfirmacaoEncaixe = false;
    $prefixoConfirmacao = '__CONFIRMAR_HORARIO_BLOQUEADO__';
    $prefixoConfirmacaoEncaixe = '__CONFIRMAR_ENCAIXE__';
    if (strpos($mensagem, $prefixoConfirmacao) === 0) {
        $requerConfirmacaoHorarioBloqueado = true;
        $mensagem = substr($mensagem, strlen($prefixoConfirmacao));
    } elseif (strpos($mensagem, $prefixoConfirmacaoEncaixe) === 0) {
        $requerConfirmacaoEncaixe = true;
        $mensagem = substr($mensagem, strlen($prefixoConfirmacaoEncaixe));
    }
    
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $mensagem,
        'requer_confirmacao_horario_bloqueado' => $requerConfirmacaoHorarioBloqueado,
        'requer_confirmacao_encaixe' => $requerConfirmacaoEncaixe
    ], JSON_UNESCAPED_UNICODE);
}
