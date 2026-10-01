<?php
/**
 * Helper: Criar agendamentos a partir de uma solicitação de plano aceita
 * Sistema: MustaBarber
 *
 * Contém as funções de apoio para:
 * - Criar agendamentos recorrentes com base no plano
 * - Resolver conflitos de horário (mover agendamento existente)
 * - Enviar notificação WhatsApp ao cliente cujo horário foi movido
 */

/**
 * Cria todos os agendamentos de um plano, resolve conflitos e marca o cliente como assinante.
 *
 * @param PDO    $conexao
 * @param array  $solicitacao  Linha completa de solicitacoes_plano_cliente
 * @param string $nomeBarbeiro
 * @param string $baseUrl      URL base da aplicação (ex: https://dominio.com)
 * @return array ['grupo' => string, 'agendamentos' => int]
 */
function criarAgendamentosParaPlano(PDO $conexao, array $solicitacao, string $nomeBarbeiro, string $baseUrl): array
{
    require_once __DIR__ . '/../whatsapp/agendamento_messages.php';

    $mapDiaSemana = [
        'domingo' => 0, 'segunda' => 1, 'terca' => 2,  'quarta' => 3,
        'quinta'  => 4, 'sexta'   => 5, 'sabado' => 6,
    ];

    $codBarbeiro     = intval($solicitacao['codigo_barbeiro']);
    $clienteNome     = $solicitacao['cliente_nome'];
    $clienteTel      = $solicitacao['cliente_telefone'];
    $diaSemana       = $solicitacao['dia_semana'];
    $horarioPref     = $solicitacao['horario_preferido'];  // HH:MM ou HH:MM:SS
    $periodicidade   = $solicitacao['periodicidade'];
    $quantSessoes    = max(1, intval($solicitacao['quantidade_sessoes']));
    $servicos        = is_array($solicitacao['servicos'])
                        ? $solicitacao['servicos']
                        : (json_decode($solicitacao['servicos'], true) ?? []);
    $dataAjustada    = $solicitacao['data_ajustada']    ?? null;  // YYYY-MM-DD ou null
    $horarioAjustado = $solicitacao['horario_ajustado'] ?? null;  // HH:MM:SS ou null
    $valorFinal      = isset($solicitacao['valor_final']) && $solicitacao['valor_final'] !== null
                        ? floatval($solicitacao['valor_final'])
                        : floatval($solicitacao['valor_total_original']);
    $obsPlano        = $solicitacao['observacoes_barbeiro'] ?? null;

    // Normalizar horário base para HH:MM
    $horarioBase = substr($horarioAjustado ?: $horarioPref, 0, 5);

    // ---- Data inicial ----
    if ($dataAjustada) {
        $dataInicial = new DateTime($dataAjustada);
    } else {
        $dataInicial = new DateTime();
        $dataInicial->setTime(0, 0, 0);
        $numeroDia = $mapDiaSemana[$diaSemana] ?? 1;
        $diaAtual  = (int)$dataInicial->format('w'); // 0 = Domingo
        $diff      = ($numeroDia - $diaAtual + 7) % 7;
        if ($diff === 0) $diff = 7; // Se hoje é o dia, pegar próxima semana
        $dataInicial->modify("+{$diff} days");
    }

    // ---- Segundo dia (opcional) ----
    $diaSemana2   = $solicitacao['dia_semana_2']    ?? null;
    $horarioBase2 = null;
    if (!empty($solicitacao['horario_preferido_2'])) {
        $horarioBase2 = substr($solicitacao['horario_preferido_2'], 0, 5);
    }

    // ---- Gerar lista de slots (data + horário) ----
    // Cada elemento: ['date' => DateTime, 'horario' => 'HH:MM']
    $dateSlots = [];

    if (!empty($diaSemana2) && !empty($horarioBase2) && isset($mapDiaSemana[$diaSemana2])) {
        // Modo dois dias: intercalar dia1 e dia2 a cada semana
        $numeroDia2   = $mapDiaSemana[$diaSemana2];
        $dataInicial2 = clone $dataInicial;
        $dataInicial2->modify('+1 day');
        $diaAtual2 = (int)$dataInicial2->format('w');
        $diff2     = ($numeroDia2 - $diaAtual2 + 7) % 7;
        $dataInicial2->modify("+{$diff2} days");

        for ($w = 0; count($dateSlots) < $quantSessoes; $w++) {
            $d1 = clone $dataInicial;
            $d1->modify("+{$w} weeks");
            if (count($dateSlots) < $quantSessoes) {
                $dateSlots[] = ['date' => $d1, 'horario' => $horarioBase];
            }
            $d2 = clone $dataInicial2;
            $d2->modify("+{$w} weeks");
            if (count($dateSlots) < $quantSessoes) {
                $dateSlots[] = ['date' => $d2, 'horario' => $horarioBase2];
            }
        }
    } else {
        // Modo dia único (original)
        $dateSlots[] = ['date' => $dataInicial, 'horario' => $horarioBase];
        for ($i = 1; $i < $quantSessoes; $i++) {
            $proxData = clone $dateSlots[count($dateSlots) - 1]['date'];
            switch ($periodicidade) {
                case 'semanal':    $proxData->modify('+7 days');   break;
                case 'quinzenal':  $proxData->modify('+14 days');  break;
                case 'mensal':     $proxData->modify('+1 month');  break;
                case 'bimestral':  $proxData->modify('+2 months'); break;
                case 'trimestral': $proxData->modify('+3 months'); break;
                default:           $proxData->modify('+1 month');
            }
            $dateSlots[] = ['date' => $proxData, 'horario' => $horarioBase];
        }
    }

    // ---- Calcular duração e serviço principal ----
    // Cada slot de atendimento realiza UMA unidade de cada tipo de serviço do plano.
    // A quantidade indica em quantas semanas o serviço aparece — não quantos por sessão.
    // Portanto: duracao_por_sessao = soma(duracao_unitaria_de_cada_servico_distinto).
    $duracaoTotal       = 0;
    $primeiroServicoCod = 0;
    foreach ($servicos as $sv) {
        $codSv = intval($sv['codigo'] ?? 0);
        if ($codSv > 0) {
            if (!$primeiroServicoCod) $primeiroServicoCod = $codSv;
            $stmtSv = $conexao->prepare("SELECT duracao FROM servicos WHERE codigo = :cod LIMIT 1");
            $stmtSv->execute([':cod' => $codSv]);
            $svData = $stmtSv->fetch(PDO::FETCH_ASSOC);
            if ($svData) {
                // Soma apenas 1x a duração — nunca multiplica pela quantidade
                $duracaoTotal += intval($svData['duracao']);
            }
        }
    }
    if ($duracaoTotal < 30) $duracaoTotal = 30;

    // ---- Identificador único do grupo ----
    $grupoId = 'plano_' . uniqid('', true);

    // ---- Criar agendamentos ----
    $agendamentosCriados = 0;
    $linkAgendamentos    = rtrim($baseUrl, '/') . '/paginas/agendamentoclientes/meus-agendamentos.html';

    foreach ($dateSlots as $slot) {
        $data         = $slot['date'];
        $dataStr      = $data->format('Y-m-d');
        $horarioFinal = $slot['horario'];

        // Verificar conflito com detecção de sobreposição baseada na duração do novo serviço
        $conflito = verificarConflitoAgendamento($conexao, $codBarbeiro, $dataStr, $horarioFinal, $duracaoTotal);

        if ($conflito) {
            // Verificar se o conflito é com um agendamento de PLANO (cliente assinante)
            $isConflitoDePlano = !empty($conflito['grupo_recorrente'])
                && strpos($conflito['grupo_recorrente'], 'plano_') === 0;

            if ($isConflitoDePlano) {
                // Não mover — lançar exceção para o barbeiro escolher outro horário
                $horConflito = substr((string)$conflito['horario'], 0, 5);
                $horNovo     = substr($horarioFinal, 0, 5);
                $dataExib    = (new \DateTime($dataStr))->format('d/m/Y');
                throw new \Exception(
                    "Conflito de horário em {$dataExib}: o horário {$horNovo} ultrapassa o início do "
                    . "atendimento de {$conflito['cliente_nome']} (cliente assinante) às {$horConflito}. "
                    . "Por favor, escolha outro horário para este plano."
                );
            }

            // Agendamento comum → mover para imediatamente após o término do serviço do plano.
            // Ex: plano 18:00 com 50 min termina às 18:50 → cliente conflitante vai para 18:50.
            $duracaoConflito  = max(30, intval($conflito['duracao_minutos']));
            $terminoDoPlano   = planoAdicionarMinutos($horarioFinal, $duracaoTotal);
            // Passa o código do agendamento sendo movido para não conflitar consigo mesmo
            $novoSlot = encontrarSlotApartirDe($conexao, $codBarbeiro, $dataStr, $terminoDoPlano, $duracaoConflito, intval($conflito['codigo']));

            if ($novoSlot !== null) {
                // Mover agendamento existente
                $stmtMove = $conexao->prepare(
                    "UPDATE agendamentos SET horario = :novo_hor, data_atualizacao = NOW()
                     WHERE codigo = :cod"
                );
                $stmtMove->execute([':novo_hor' => $novoSlot, ':cod' => $conflito['codigo']]);

                // Notificar cliente cujo horário foi alterado
                if (!empty($conflito['cliente_telefone'])) {
                    $linkComTel = $linkAgendamentos . '?telefone=' . urlencode($conflito['cliente_telefone']);
                    enviarNotificacaoHorarioAlterado(
                        $conflito['cliente_telefone'],
                        $conflito['cliente_nome'],
                        $dataStr,
                        $conflito['horario'],  // horário original
                        $novoSlot,             // novo horário
                        $conflito['servico_nome'] ?? 'Serviço',
                        $nomeBarbeiro,
                        $linkComTel,
                        $codBarbeiro
                    );
                }
            }
            // Se não encontrou slot livre disponível, o INSERT falhará pela UNIQUE constraint
        }

        // Inserir o novo agendamento do plano
        try {
            $stmtIns = $conexao->prepare("
                INSERT INTO agendamentos (
                    codigo_barbeiro, cliente_nome, cliente_telefone,
                    data_agendamento, horario, codigo_servico,
                    valor, duracao_minutos, observacoes, codigo_status,
                    is_recorrente, grupo_recorrente, data_original_recorrencia, data_criacao
                ) VALUES (
                    :barb, :nome, :tel, :data, :hora, :servico,
                    :valor, :dur, :obs, 1,
                    TRUE, :grupo, :data_orig, CURRENT_TIMESTAMP
                )
            ");
            $stmtIns->execute([
                ':barb'      => $codBarbeiro,
                ':nome'      => $clienteNome,
                ':tel'       => $clienteTel,
                ':data'      => $dataStr,
                ':hora'      => $horarioFinal,
                ':servico'   => $primeiroServicoCod ?: null,
                ':valor'     => $valorFinal,
                ':dur'       => $duracaoTotal,
                ':obs'       => $obsPlano,
                ':grupo'     => $grupoId,
                ':data_orig' => $dateSlots[0]['date']->format('Y-m-d'),
            ]);
            $agendamentosCriados++;
        } catch (Exception $e) {
            error_log('[criarAgendamentosParaPlano] Erro ao inserir agendamento: ' . $e->getMessage());
        }
    }

    // ---- Marcar cliente como assinante ----
    // Comparar apenas dígitos do telefone para evitar problemas de formatação
    $stmtAssin = $conexao->prepare(
        "UPDATE clientes SET is_assinante = TRUE
         WHERE REGEXP_REPLACE(telefone, '[^0-9]', '', 'g') = REGEXP_REPLACE(:tel, '[^0-9]', '', 'g')
           AND cod_barbeiro = :barb"
    );
    $stmtAssin->execute([':tel' => $clienteTel, ':barb' => $codBarbeiro]);

    return ['grupo' => $grupoId, 'agendamentos' => $agendamentosCriados];
}

/**
 * Verifica se há agendamento ativo que SOBREPÕE o intervalo [horario, horario+duracaoNova].
 * Retorna os dados do conflito ou null.
 *
 * @param int $excludeCodigo  Código de agendamento a ignorar na busca (ex: o que está sendo movido).
 */
function verificarConflitoAgendamento(PDO $conexao, int $codBarbeiro, string $data, string $horario, int $duracaoNova = 30, int $excludeCodigo = 0): ?array
{
    $stmt = $conexao->prepare("
        SELECT a.codigo, a.cliente_nome, a.cliente_telefone, a.horario, a.duracao_minutos,
               a.is_recorrente, a.grupo_recorrente,
               s.nome AS servico_nome
        FROM agendamentos a
        LEFT JOIN servicos s ON a.codigo_servico = s.codigo
        WHERE a.codigo_barbeiro = :barb
          AND a.data_agendamento = :data
          AND a.codigo_status NOT IN (4, 5, 6)
          AND (:exclude = 0 OR a.codigo != :exclude)
          AND :hora::time < (a.horario + (COALESCE(a.duracao_minutos, 30) || ' minutes')::interval)
          AND a.horario        < (:hora::time + (:duracao || ' minutes')::interval)
        ORDER BY a.horario ASC
        LIMIT 1
    ");
    $stmt->execute([':barb' => $codBarbeiro, ':data' => $data, ':hora' => $horario, ':duracao' => $duracaoNova, ':exclude' => $excludeCodigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Encontra o primeiro slot livre a partir de um horário específico, buscando APENAS para frente.
 * Usado para mover um agendamento para logo após o término exato de um serviço de plano.
 *
 * @param int $excludeCodigo  Ignora este agendamento ao verificar conflitos (o próprio que será movido).
 */
function encontrarSlotApartirDe(PDO $conexao, int $codBarbeiro, string $data, string $fromHorario, int $duracao = 30, int $excludeCodigo = 0): ?string
{
    $duracao = max(30, $duracao);
    for ($delta = 0; $delta <= 16; $delta++) {
        $candidato = $delta === 0
            ? $fromHorario
            : planoAdicionarMinutos($fromHorario, $duracao * $delta);
        if (!verificarConflitoAgendamento($conexao, $codBarbeiro, $data, $candidato, $duracao, $excludeCodigo)) {
            return $candidato;
        }
    }
    return null;
}

/**
 * Encontra o slot livre mais próximo ao horário informado (busca antes e depois).
 * Mantido como fallback genérico.
 */
function encontrarSlotLivre(PDO $conexao, int $codBarbeiro, string $data, string $horario, int $duracao = 30): ?string
{
    $duracao = max(30, $duracao);
    for ($delta = 1; $delta <= 16; $delta++) {
        $depois = planoAdicionarMinutos($horario, $duracao * $delta);
        if (!verificarConflitoAgendamento($conexao, $codBarbeiro, $data, $depois, $duracao)) {
            return $depois;
        }
        $antes = planoSubtrairMinutos($horario, $duracao * $delta);
        if ($antes !== null && !verificarConflitoAgendamento($conexao, $codBarbeiro, $data, $antes, $duracao)) {
            return $antes;
        }
    }
    return null;
}

/** Soma minutos a um horário HH:MM, com máximo 23:59. */
function planoAdicionarMinutos(string $horario, int $minutos): string
{
    $h     = (int)substr($horario, 0, 2);
    $m     = (int)substr($horario, 3, 2);
    $total = min($h * 60 + $m + $minutos, 23 * 60 + 59);
    return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
}

/** Subtrai minutos de um horário HH:MM. Retorna null se resultado < 00:00. */
function planoSubtrairMinutos(string $horario, int $minutos): ?string
{
    $h     = (int)substr($horario, 0, 2);
    $m     = (int)substr($horario, 3, 2);
    $total = $h * 60 + $m - $minutos;
    if ($total < 0) return null;
    return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
}
