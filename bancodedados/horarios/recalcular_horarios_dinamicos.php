<?php
/**
 * Fun&#65533;&#65533;o de Rec&#65533;lculo Din&#65533;mico de Hor&#65533;rios
 * Sistema: MustaBarber
 * Data: 22/01/2026
 * 
 * Esta fun&#65533;&#65533;o recalcula os hor&#65533;rios dispon&#65533;veis de um dia espec&#65533;fico
 * baseado na dura&#65533;&#65533;o real dos servi&#65533;os agendados.
 * 
 * L&#65533;gica:
 * - Busca todos os agendamentos do dia em ordem cronol&#65533;gica
 * - Para cada agendamento, calcula a dura&#65533;&#65533;o total dos servi&#65533;os
 * - Se um servi&#65533;o ultrapassa o intervalo padr&#65533;o, os pr&#65533;ximos hor&#65533;rios
 *   s&#65533;o ajustados para come&#65533;ar ap&#65533;s o t&#65533;rmino do servi&#65533;o
 * - Apenas os hor&#65533;rios daquele dia espec&#65533;fico s&#65533;o afetados
 * 
 * @param PDO $conn - Conex&#65533;o com banco de dados
 * @param int $codigo_barbeiro - C&#65533;digo do barbeiro
 * @param string $data - Data no formato YYYY-MM-DD
 * @param array $config - Configura&#65533;&#65533;o do dia (horario_inicio, horario_fim, intervalo_minutos)
 * @return array - Lista de hor&#65533;rios recalculados
 */
function recalcularHorariosDinamicos($conn, $codigo_barbeiro, $data, $config) {
    // 1. Buscar todos os agendamentos confirmados do dia com suas dura&#65533;&#65533;es
    $sql_agendamentos = "
        SELECT 
            a.horario,
            a.codigo,
            a.cliente_nome,
            a.codigo_status,
            COALESCE(NULLIF(a.duracao_minutos, 0), s.duracao, 30) as duracao_total_minutos
        FROM agendamentos a
        LEFT JOIN servicos s ON a.codigo_servico = s.codigo
        WHERE a.codigo_barbeiro = :codigo_barbeiro
        AND a.data_agendamento = :data_agendamento
        AND a.codigo_status IN (1, 2)
        GROUP BY a.codigo, a.horario, a.cliente_nome, a.codigo_status, a.duracao_minutos, s.duracao
        ORDER BY a.horario ASC
    ";
    
    $stmt = $conn->prepare($sql_agendamentos);
    $stmt->execute([
        ':codigo_barbeiro' => $codigo_barbeiro,
        ':data_agendamento' => $data
    ]);
    
    $agendamentos_confirmados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Gerar hor&#65533;rios base (sem considerar agendamentos)
    $horarios_base = [];
    $inicio = strtotime($data . ' ' . $config['horario_inicio']);
    $fim = strtotime($data . ' ' . $config['horario_fim']);
    $intervalo_padrao = intval($config['intervalo_minutos']);
    $intervalo_segundos = $intervalo_padrao * 60;
    
    for ($time = $inicio; $time <= $fim; $time += $intervalo_segundos) {
        $horarios_base[] = date('H:i', $time);
    }
    
    // 3. Se n&#65533;o h&#65533; agendamentos, retornar hor&#65533;rios base
    if (empty($agendamentos_confirmados)) {
        return [
            'horarios' => $horarios_base,
            'horarios_base' => $horarios_base,
            'agendamentos' => [],
            'recalculado' => false
        ];
    }
    
    // 4. Processar agendamentos e recalcular hor&#65533;rios
    $horarios_recalculados = [];
    $agendamentos_info = [];
    $horaAtual = $inicio;
    $indiceAgendamento = 0;
    $totalAgendamentos = count($agendamentos_confirmados);
    
    while ($horaAtual <= $fim) {
        $horarioFormatado = date('H:i', $horaAtual);
        
        // Verificar se existe agendamento neste hor&#65533;rio
        $temAgendamento = false;
        $agendamento = null;
        
        foreach ($agendamentos_confirmados as $ag) {
            if (substr($ag['horario'], 0, 5) === $horarioFormatado) {
                $temAgendamento = true;
                $agendamento = $ag;
                break;
            }
        }
        
        if ($temAgendamento) {
            // Adicionar hor&#65533;rio agendado
            $horarios_recalculados[] = $horarioFormatado;
            $agendamentos_info[$horarioFormatado] = [
                'cliente_nome' => $agendamento['cliente_nome'],
                'codigo_status' => $agendamento['codigo_status'],
                'duracao_minutos' => intval($agendamento['duracao_total_minutos'])
            ];
            
            // Calcular dura&#65533;&#65533;o do servi&#65533;o
            $duracaoMinutos = intval($agendamento['duracao_total_minutos']);
            
            // Se a dura&#65533;&#65533;o ultrapassa o intervalo padr&#65533;o, ajustar pr&#65533;ximo hor&#65533;rio
            if ($duracaoMinutos > $intervalo_padrao) {
                // Pr&#65533;ximo hor&#65533;rio dispon&#65533;vel ser&#65533; ap&#65533;s o t&#65533;rmino do servi&#65533;o
                $horaAtual = strtotime($data . ' ' . $horarioFormatado) + ($duracaoMinutos * 60);
            } else {
                // Usar intervalo padr&#65533;o
                $horaAtual += $intervalo_segundos;
            }
        } else {
            // Hor&#65533;rio dispon&#65533;vel - adicionar apenas se n&#65533;o conflita com agendamentos existentes
            $proximoHorario = $horaAtual + $intervalo_segundos;
            $conflito = false;
            
            // Verificar se algum agendamento existente ocupa este hor&#65533;rio
            foreach ($agendamentos_confirmados as $ag) {
                $horarioAgendamento = substr((string)$ag['horario'], 0, 5);
                $inicioAgendamento = strtotime($data . ' ' . $horarioAgendamento);
                $duracaoAgendamento = intval($ag['duracao_total_minutos']) * 60;
                $fimAgendamento = $inicioAgendamento + $duracaoAgendamento;
                
                // Se o hor&#65533;rio atual est&#65533; dentro do per&#65533;odo de um agendamento, h&#65533; conflito
                if ($horaAtual >= $inicioAgendamento && $horaAtual < $fimAgendamento) {
                    $conflito = true;
                    // Pular para o fim deste agendamento
                    $horaAtual = $fimAgendamento;
                    break;
                }
            }
            
            if (!$conflito) {
                // Adicionar hor&#65533;rio dispon&#65533;vel
                $horarios_recalculados[] = $horarioFormatado;
                $horaAtual += $intervalo_segundos;
            }
        }
    }
    
    return [
        'horarios' => $horarios_recalculados,
        'horarios_base' => $horarios_base,
        'agendamentos' => $agendamentos_info,
        'recalculado' => true
    ];
}

/**
 * Verifica se um hor&#65533;rio + dura&#65533;&#65533;o (em minutos) conflita com o
 * hor&#65533;rio de almo&#65533;o configurado para o dia da semana da data informada.
 *
 * @param PDO $conn
 * @param int $codigo_barbeiro
 * @param string $data - Data no formato YYYY-MM-DD
 * @param string $horaInicio - Hor&#65533;rio no formato HH:MM (ou HH:MM:SS)
 * @param int $duracaoMinutos - Dura&#65533;&#65533;o total do(s) servi&#65533;o(s) em minutos
 * @return bool - true se houver conflito com o hor&#65533;rio de almo&#65533;o
 */
function verificarConflitoAlmoco($conn, $codigo_barbeiro, $data, $horaInicio, $duracaoMinutos) {
    $dia_semana = (int) date('w', strtotime($data));

    $stmt = $conn->prepare(
        "SELECT almoco_ativo, almoco_inicio, almoco_fim
         FROM horarios_configuracao
         WHERE codigo_barbeiro = :barbeiro AND dia_semana = :dia"
    );
    $stmt->execute([':barbeiro' => $codigo_barbeiro, ':dia' => $dia_semana]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config || empty($config['almoco_ativo']) || empty($config['almoco_inicio']) || empty($config['almoco_fim'])) {
        return false;
    }

    $toMinutes = function ($hora) {
        $partes = explode(':', substr((string) $hora, 0, 5));
        if (count($partes) !== 2) return null;
        return (intval($partes[0]) * 60) + intval($partes[1]);
    };

    $inicioMin  = $toMinutes($horaInicio);
    $almocoIni  = $toMinutes($config['almoco_inicio']);
    $almocoFim  = $toMinutes($config['almoco_fim']);

    if ($inicioMin === null || $almocoIni === null || $almocoFim === null) {
        return false;
    }

    $fimMin = $inicioMin + max(1, (int) $duracaoMinutos);

    return $inicioMin < $almocoFim && $fimMin > $almocoIni;
}

/**
 * Busca hor&#65533;rios bloqueados para uma data espec&#65533;fica
 */
function buscarHorariosBloqueados($conn, $codigo_barbeiro, $data) {
    $sql = "SELECT TO_CHAR(horario, 'HH24:MI') as horario 
            FROM horarios_bloqueados 
            WHERE codigo_barbeiro = :codigo_barbeiro 
            AND data_bloqueio = :data_bloqueio";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':codigo_barbeiro' => $codigo_barbeiro,
        ':data_bloqueio' => $data
    ]);
    
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Monta a resposta final com status de cada hor&#65533;rio
 */
function montarRespostaHorarios($horarios, $bloqueados, $agendamentos, $config = []) {
    $horarios_completos = [];

    $toMinutes = function ($hora) {
        $horaLimpa = substr((string)$hora, 0, 5);
        $partes = explode(':', $horaLimpa);
        if (count($partes) !== 2) return null;
        return (intval($partes[0]) * 60) + intval($partes[1]);
    };

    $intervalosOcupados = [];
    foreach ($agendamentos as $horaInicio => $infoAgendamento) {
        $inicioMin = $toMinutes($horaInicio);
        if ($inicioMin === null) {
            continue;
        }

        $duracao = max(1, intval($infoAgendamento['duracao_minutos'] ?? 30));
        $intervalosOcupados[] = [
            'inicio' => $inicioMin,
            'fim' => $inicioMin + $duracao
        ];
    }

    // Intervalo de almo&#65533;o (se configurado), tratado como hor&#65533;rio bloqueado
    $almocoInicioMin = null;
    $almocoFimMin = null;
    if (!empty($config['almoco_ativo']) && !empty($config['almoco_inicio']) && !empty($config['almoco_fim'])) {
        $almocoInicioMin = $toMinutes($config['almoco_inicio']);
        $almocoFimMin = $toMinutes($config['almoco_fim']);
    }
    
    foreach ($horarios as $horario) {
        $status = 'disponivel';
        $info = null;
        $horarioMin = $toMinutes($horario);

        $ocupadoPorIntervalo = false;
        if ($horarioMin !== null) {
            foreach ($intervalosOcupados as $intervalo) {
                if ($horarioMin >= $intervalo['inicio'] && $horarioMin < $intervalo['fim']) {
                    $ocupadoPorIntervalo = true;
                    break;
                }
            }
        }

        $noHorarioAlmoco = ($almocoInicioMin !== null && $horarioMin !== null
            && $horarioMin >= $almocoInicioMin && $horarioMin < $almocoFimMin);

        $motivoBloqueio = null;
        if (in_array($horario, $bloqueados) || $noHorarioAlmoco) {
            $status = 'bloqueado';
            $motivoBloqueio = $noHorarioAlmoco ? 'almoco' : 'manual';
        } elseif (isset($agendamentos[$horario]) || $ocupadoPorIntervalo) {
            $status = 'agendado';
            if (isset($agendamentos[$horario])) {
                $info = $agendamentos[$horario];
            }
        }
        
        $horarios_completos[] = [
            'horario' => $horario,
            'status' => $status,
            'motivo_bloqueio' => $motivoBloqueio,
            'info' => $info
        ];
    }
    
    return $horarios_completos;
}
?>
