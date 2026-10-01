<?php
/**
 * API para buscar hor&#65533;rios dispon&#65533;veis para agendamento
 * Sistema: MustaBarber
 * Data: 22/01/2026
 * 
 * Retorna hor&#65533;rios livres baseado na data selecionada
 * Pode ser chamado com ou sem autentica&#65533;&#65533;o
 * Agora com rec&#65533;lculo din&#65533;mico de hor&#65533;rios baseado na dura&#65533;&#65533;o dos servi&#65533;os
 */

header('Content-Type: application/json; charset=utf-8');

// Inclui conex&#65533;o e fun&#65533;&#65533;es de rec&#65533;lculo
require_once '../conexao.php';
require_once '../horarios/recalcular_horarios_dinamicos.php';

try {
    // Par&#65533;metros
    $data = $_GET['data'] ?? null;
    $barbeiro = $_GET['barbeiro'] ?? null;
    $duracaoTotal = isset($_GET['duracao_total']) ? intval($_GET['duracao_total']) : null;
    $origem = isset($_GET['origem']) ? strtolower(trim((string)$_GET['origem'])) : '';
    $incluirPassados = isset($_GET['incluir_passados']) && $_GET['incluir_passados'] === '1';
    $aplicarFiltroDuracaoCliente = ($origem === 'cliente');
    if ($duracaoTotal !== null && $duracaoTotal <= 0) {
        $duracaoTotal = null;
    }
    
    if (!$data) {
        throw new Exception('Data &#65533; obrigat&#65533;ria.');
    }
    
    // Se n&#65533;o tiver barbeiro, tenta pegar do usu&#65533;rio logado
    if (!$barbeiro) {
        require_once '../usuarios/sessao.php';
        if (!estaLogado()) {
            throw new Exception('Barbeiro n&#65533;o fornecido e usu&#65533;rio n&#65533;o autenticado.');
        }
        $usuarioLogado = obterUsuarioLogado();
        $barbeiro = $usuarioLogado['codigo'];
    }
    
    // Obt&#65533;m dia da semana (0=Domingo, 6=S&#65533;bado)
    $dataObj = new DateTime($data);
    $diaSemana = (int)$dataObj->format('w');
    
    // Busca configura&#65533;&#65533;o de hor&#65533;rios do barbeiro
    $stmtConfig = $conn->prepare("
        SELECT horario_inicio, horario_fim, intervalo_minutos,
               almoco_ativo, almoco_inicio, almoco_fim
        FROM horarios_configuracao
        WHERE codigo_barbeiro = :barbeiro AND dia_semana = :dia AND ativo = TRUE
    ");
    $stmtConfig->execute([':barbeiro' => $barbeiro, ':dia' => $diaSemana]);
    $config = $stmtConfig->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        echo json_encode([
            'sucesso' => true,
            'horarios' => [],
            'mensagem' => 'N&#65533;o h&#65533; expediente neste dia da semana.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Verificar se a data está em período de paralisação ativa do barbeiro
    $sqlParalisacao = "SELECT data_inicio, data_fim, motivo
                       FROM agenda_paralisacao
                       WHERE codigo_barbeiro = :barbeiro
                         AND ativa = TRUE
                         AND data_inicio <= :data
                         AND (data_fim IS NULL OR data_fim >= :data2)
                       LIMIT 1";
    $stmtParalisacao = $conn->prepare($sqlParalisacao);
    $stmtParalisacao->execute([':barbeiro' => $barbeiro, ':data' => $data, ':data2' => $data]);
    $paralisacao = $stmtParalisacao->fetch(PDO::FETCH_ASSOC);

    if ($paralisacao) {
        $dataFimTexto = $paralisacao['data_fim']
            ? 'até ' . date('d/m/Y', strtotime($paralisacao['data_fim']))
            : 'por tempo indeterminado';
        $motivoTexto = $paralisacao['motivo'] ? ' Motivo: ' . $paralisacao['motivo'] . '.' : '';
        echo json_encode([
            'sucesso' => true,
            'horarios' => [],
            'paralisacao' => true,
            'mensagem' => 'Agenda fechada ' . $dataFimTexto . '.' . $motivoTexto
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // NOVA L&#65533;GICA: Usar rec&#65533;lculo din&#65533;mico de hor&#65533;rios
    $resultado = recalcularHorariosDinamicos($conn, $barbeiro, $data, $config);
    $horarios = $resultado['horarios'];
    $agendamentos = $resultado['agendamentos'];
    
    // Buscar TODOS os agendamentos do dia (n&#65533;o apenas os retornados pelo rec&#65533;lculo)
    // para calcular corretamente os minutos at&#65533; o pr&#65533;ximo agendamento
    $sqlTodosAgendamentos = "
        SELECT horario, codigo_status, COALESCE(NULLIF(duracao_minutos, 0), 30) AS duracao_minutos
        FROM agendamentos
        WHERE codigo_barbeiro = :barbeiro
        AND data_agendamento = :data
        AND codigo_status IN (1, 2)
        ORDER BY horario ASC
    ";
    $stmtAgendamentos = $conn->prepare($sqlTodosAgendamentos);
    $stmtAgendamentos->execute([':barbeiro' => $barbeiro, ':data' => $data]);
    $todosAgendamentos = $stmtAgendamentos->fetchAll(PDO::FETCH_ASSOC);

    $intervalosOcupados = [];
    foreach ($todosAgendamentos as $agendamentoDia) {
        $horaInicio = substr((string)$agendamentoDia['horario'], 0, 5);
        $duracaoMinutos = max(1, intval($agendamentoDia['duracao_minutos'] ?? 30));
        $inicioTs = strtotime($data . ' ' . $horaInicio);
        if ($inicioTs === false) {
            continue;
        }

        $intervalosOcupados[] = [
            'inicio_ts' => $inicioTs,
            'fim_ts' => $inicioTs + ($duracaoMinutos * 60),
            'horario_inicio' => $horaInicio
        ];
    }

    // Intervalo de almo&#65533;o (se configurado para este dia da semana)
    $temAlmoco = !empty($config['almoco_ativo']) && !empty($config['almoco_inicio']) && !empty($config['almoco_fim']);
    $almocoInicioTs = $temAlmoco ? strtotime($data . ' ' . substr($config['almoco_inicio'], 0, 5)) : null;
    $almocoFimTs = $temAlmoco ? strtotime($data . ' ' . substr($config['almoco_fim'], 0, 5)) : null;
    
    // Buscar TODOS os hor&#65533;rios bloqueados do dia
    $sqlTodosBloqueados = "
        SELECT TO_CHAR(horario, 'HH24:MI') AS horario
        FROM horarios_bloqueados
        WHERE codigo_barbeiro = :barbeiro
        AND data_bloqueio = :data
        ORDER BY horario ASC
    ";

    try {
        $stmtBloqueados = $conn->prepare($sqlTodosBloqueados);
        $stmtBloqueados->execute([':barbeiro' => $barbeiro, ':data' => $data]);
        $bloqueados = $stmtBloqueados->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log("ERRO ao buscar bloqueados: " . $e->getMessage());
        $bloqueados = [];
    }
    
    // Classifica hor&#65533;rios por status
    $horariosComStatus = [];
    foreach ($horarios as $horario) {
        $horarioLimpo = substr($horario, 0, 5);
        
        // Remove hor&#65533;rios passados se for hoje
        if (!$incluirPassados && $data === date('Y-m-d') && $horarioLimpo <= date('H:i')) {
            continue;
        }

        $horarioAtualTimestamp = strtotime($data . ' ' . $horarioLimpo);
        
        $status = 'disponivel'; // Verde
        
        // Verifica se est&#65533; no hor&#65533;rio de almo&#65533;o
        $noHorarioAlmoco = ($temAlmoco && $horarioAtualTimestamp >= $almocoInicioTs && $horarioAtualTimestamp < $almocoFimTs);

        // Verifica se está bloqueado
        $motivoBloqueio = null;
        $intervaloSegundos = intval($config['intervalo_minutos']) * 60;

        if ($noHorarioAlmoco) {
            $status = 'bloqueado'; // Vermelho
            $motivoBloqueio = 'almoco';
        } elseif (in_array($horarioLimpo, $bloqueados)) {
            $status = 'bloqueado'; // Vermelho
            $motivoBloqueio = 'manual';
        } else {
            // Verifica se algum horário bloqueado cai DENTRO da janela deste slot
            // Ex: slot em 10:00 com intervalo de 40 min → bloqueia se houver bloqueio em (10:00, 10:40)
            foreach ($bloqueados as $horarioBloqueado) {
                $horarioBloqueadoTs = strtotime($data . ' ' . $horarioBloqueado);
                if ($horarioBloqueadoTs > $horarioAtualTimestamp &&
                    $horarioBloqueadoTs < $horarioAtualTimestamp + $intervaloSegundos) {
                    $status = 'bloqueado'; // Vermelho
                    $motivoBloqueio = 'manual';
                    break;
                }
            }
        }
        
        // Verifica se est&#65533; em intervalo ocupado por agendamento
        $ocupadoPorIntervalo = false;
        foreach ($intervalosOcupados as $intervalo) {
            if ($horarioAtualTimestamp >= $intervalo['inicio_ts'] && $horarioAtualTimestamp < $intervalo['fim_ts']) {
                $ocupadoPorIntervalo = true;
                break;
            }
        }

        // Verifica se est&#65533; agendado (inicio ou dentro do intervalo de duracao)
        if ($status === 'disponivel' && (isset($agendamentos[$horarioLimpo]) || $ocupadoPorIntervalo)) {
            $status = 'agendado'; // Azul
        }
        
        // Calcular minutos at&#65533; pr&#65533;ximo obst&#65533;culo (agendamento OU bloqueio)
        $minutosAteProximo = null;
        
        // DEBUG: Log para verificar bloqueios (comentar em produ&#65533;&#65533;o)
        // error_log("DEBUG - Hor&#65533;rio: $horarioLimpo, Bloqueados no dia: " . json_encode($bloqueados));
        
        // 1. Buscar pr&#65533;ximo hor&#65533;rio AGENDADO ap&#65533;s este hor&#65533;rio
        $minutosAteProximoAgendamento = null;
        foreach ($todosAgendamentos as $agendamentoDia) {
            $horarioAgendadoLimpo = substr((string)$agendamentoDia['horario'], 0, 5);
            $horarioAgendadoTimestamp = strtotime($data . ' ' . $horarioAgendadoLimpo);
            
            // Se o hor&#65533;rio agendado &#65533; posterior ao hor&#65533;rio atual
            if ($horarioAgendadoTimestamp > $horarioAtualTimestamp) {
                $minutosAteProximoAgendamento = ($horarioAgendadoTimestamp - $horarioAtualTimestamp) / 60;
                break; // Pegar apenas o primeiro (mais pr&#65533;ximo)
            }
        }
        
        // 2. Buscar pr&#65533;ximo hor&#65533;rio BLOQUEADO ap&#65533;s este hor&#65533;rio (SOMENTE ap&#65533;s!)
        $minutosAteProximoBloqueio = null;
        foreach ($bloqueados as $horarioBloqueado) {
            $horarioBloqueadoTimestamp = strtotime($data . ' ' . $horarioBloqueado);
            
            // Se o hor&#65533;rio bloqueado &#65533; posterior ao hor&#65533;rio atual (n&#65533;o aplica para bloqueios anteriores)
            if ($horarioBloqueadoTimestamp > $horarioAtualTimestamp) {
                $minutosAteProximoBloqueio = ($horarioBloqueadoTimestamp - $horarioAtualTimestamp) / 60;
                // error_log("DEBUG - Hor&#65533;rio $horarioLimpo tem bloqueio em $horarioBloqueado ($minutosAteProximoBloqueio minutos)");
                break; // Pegar apenas o primeiro (mais pr&#65533;ximo)
            }
        }

        // 3. In&#65533;cio do hor&#65533;rio de almo&#65533;o, se ainda estiver &#65533; frente
        $minutosAteProximoAlmoco = null;
        if ($temAlmoco && $almocoInicioTs > $horarioAtualTimestamp) {
            $minutosAteProximoAlmoco = ($almocoInicioTs - $horarioAtualTimestamp) / 60;
        }
        
        // 4. Usar o MENOR tempo entre agendamento, bloqueio e almo&#65533;o (o que vier primeiro)
        $candidatos = array_filter(
            [$minutosAteProximoAgendamento, $minutosAteProximoBloqueio, $minutosAteProximoAlmoco],
            function ($v) { return $v !== null; }
        );
        if (!empty($candidatos)) {
            $minutosAteProximo = min($candidatos);
        }
        // Se n&#65533;o houver nenhum obst&#65533;culo, $minutosAteProximo permanece null
        
        // Se a duracao do servico foi informada, so exibe como disponivel
        // quando houver tempo suficiente ate o proximo obstaculo.
        if ($aplicarFiltroDuracaoCliente && $status === 'disponivel' && $duracaoTotal !== null && $minutosAteProximo !== null && $duracaoTotal > $minutosAteProximo) {
            $status = 'indisponivel_duracao';
        }

        $horariosComStatus[] = [
            'horario' => $horarioLimpo,
            'status' => $status,
            'motivo_bloqueio' => $motivoBloqueio,
            'minutos_ate_proximo' => $minutosAteProximo
        ];
    }
    
    echo json_encode([
        'sucesso' => true,
        'horarios' => $horariosComStatus,
        'debug' => [
            'total_agendamentos' => count($todosAgendamentos),
            'agendamentos' => array_map(function ($item) {
                return substr((string)$item['horario'], 0, 5);
            }, $todosAgendamentos),
            'total_bloqueados' => count($bloqueados),
            'bloqueados' => $bloqueados,
            'bloqueados_type' => gettype($bloqueados),
            'bloqueados_is_array' => is_array($bloqueados),
            'data' => $data,
            'barbeiro' => $barbeiro,
            'funcao_existe' => function_exists('buscarHorariosBloqueados')
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
