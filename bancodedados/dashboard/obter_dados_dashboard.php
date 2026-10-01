<?php
/**
 * Obter Dados do Dashboard
 * Sistema: MustaBarber
 *
 * Mantem os campos antigos e adiciona dados gerenciais:
 * - indicadores de atendimento (taxa de conclusao, cancelamentos, faltas)
 * - lucro de servicos e produtos
 * - historico mensal (6 meses) para graficos
 * - comissoes por funcionario (repasse estimado)
 */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once '../usuarios/sessao.php';

function montarPlaceholdersIds(array $ids, array &$params, string $prefix = 'id') {
    $placeholders = [];
    foreach (array_values($ids) as $idx => $id) {
        $key = ':' . $prefix . $idx;
        $placeholders[] = $key;
        $params[$key] = (int)$id;
    }
    return implode(', ', $placeholders);
}

function scalar(PDO $conexao, string $sql, array $params = []) {
    $stmt = $conexao->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_NUM);
    return $row ? $row[0] : 0;
}

function tabelaExiste(PDO $conexao, string $nomeTabela) {
    $stmt = $conexao->prepare("SELECT to_regclass(:nome_tabela)");
    $stmt->bindValue(':nome_tabela', 'public.' . $nomeTabela, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchColumn() !== null;
}

function montarRespostaBasicaDashboard(PDO $conexao, int $codigoUsuario, string $hoje, string $mesAtual, string $horaAtual) {
    $nomeBarbearia = 'MustaBarber';

    try {
        $stmtBarb = $conexao->prepare("SELECT nome_barbearia FROM barbearia WHERE codigo_usuario = :cod LIMIT 1");
        $stmtBarb->bindValue(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmtBarb->execute();
        $barbearia = $stmtBarb->fetch(PDO::FETCH_ASSOC);
        if ($barbearia && !empty($barbearia['nome_barbearia'])) {
            $nomeBarbearia = $barbearia['nome_barbearia'];
        }
    } catch (Exception $e) {
    }

    $totalClientes = 0;
    $agendamentosHoje = 0;
    $faturamentoHoje = 0.0;
    $faturamentoMes = 0.0;
    $aReceber = 0.0;
    $proximoHorario = null;
    $proximosAgendamentos = [];

    try {
        $stmt = $conexao->prepare("SELECT COUNT(*) FROM clientes WHERE cod_barbeiro = :cod");
        $stmt->bindValue(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmt->execute();
        $totalClientes = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
    }

    try {
        $stmt = $conexao->prepare(
            "SELECT COUNT(*)
             FROM agendamentos
             WHERE codigo_barbeiro = :cod
               AND data_agendamento = :hoje
               AND codigo_status NOT IN (5, 6)"
        );
        $stmt->bindValue(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmt->bindValue(':hoje', $hoje);
        $stmt->execute();
        $agendamentosHoje = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
    }

    try {
        $stmt = $conexao->prepare(
            "SELECT horario
             FROM agendamentos
             WHERE codigo_barbeiro = :cod
               AND (
                   data_agendamento > :hoje
                   OR (data_agendamento = :hoje AND horario >= :hora_atual)
               )
               AND codigo_status NOT IN (4, 5, 6)
             ORDER BY data_agendamento ASC, horario ASC
             LIMIT 1"
        );
        $stmt->bindValue(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmt->bindValue(':hoje', $hoje);
        $stmt->bindValue(':hora_atual', $horaAtual);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $proximoHorario = $row ? substr($row['horario'], 0, 5) : null;
    } catch (Exception $e) {
    }

    try {
        $stmt = $conexao->prepare(
            "SELECT COALESCE(SUM(valor), 0)
             FROM agendamentos
             WHERE codigo_barbeiro = :cod
               AND data_agendamento = :hoje
               AND codigo_status = 4"
        );
        $stmt->bindValue(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmt->bindValue(':hoje', $hoje);
        $stmt->execute();
        $faturamentoHoje = (float)$stmt->fetchColumn();
    } catch (Exception $e) {
    }

    try {
        $stmt = $conexao->prepare(
            "SELECT COALESCE(SUM(valor), 0)
             FROM agendamentos
             WHERE codigo_barbeiro = :cod
               AND TO_CHAR(data_agendamento, 'YYYY-MM') = :mes
               AND codigo_status = 4"
        );
        $stmt->bindValue(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmt->bindValue(':mes', $mesAtual);
        $stmt->execute();
        $faturamentoMes = (float)$stmt->fetchColumn();
    } catch (Exception $e) {
    }

    try {
        $stmt = $conexao->prepare(
            "SELECT COALESCE(SUM(valor), 0)
             FROM agendamentos
             WHERE codigo_barbeiro = :cod
               AND data_agendamento = :hoje
               AND codigo_status NOT IN (4, 5, 6)"
        );
        $stmt->bindValue(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmt->bindValue(':hoje', $hoje);
        $stmt->execute();
        $aReceber = (float)$stmt->fetchColumn();
    } catch (Exception $e) {
    }

    try {
        $stmt = $conexao->prepare(
            "SELECT a.data_agendamento, a.horario, a.cliente_nome, a.valor, s.nome AS servico_nome
             FROM agendamentos a
             LEFT JOIN servicos s ON a.codigo_servico = s.codigo
             WHERE a.codigo_barbeiro = :cod
               AND (
                   a.data_agendamento > :hoje
                   OR (a.data_agendamento = :hoje AND a.horario >= :hora_atual)
               )
               AND a.codigo_status NOT IN (4, 5, 6)
             ORDER BY a.data_agendamento ASC, a.horario ASC
             LIMIT 5"
        );
        $stmt->bindValue(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmt->bindValue(':hoje', $hoje);
        $stmt->bindValue(':hora_atual', $horaAtual);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $proximosAgendamentos = array_map(static function($r) {
            return [
                'data_agendamento' => $r['data_agendamento'],
                'horario' => substr($r['horario'], 0, 5),
                'cliente_nome' => $r['cliente_nome'],
                'servico_nome' => $r['servico_nome'] ?? '',
                'barbeiro_nome' => '',
                'valor' => (float)$r['valor']
            ];
        }, $rows);
    } catch (Exception $e) {
    }

    return [
        'sucesso' => true,
        'nome_barbearia' => $nomeBarbearia,
        'total_clientes' => $totalClientes,
        'agendamentos_hoje' => $agendamentosHoje,
        'proximo_horario' => $proximoHorario,
        'faturamento_hoje' => $faturamentoHoje,
        'faturamento_mes' => $faturamentoMes,
        'a_receber' => $aReceber,
        'proximos_agendamentos' => $proximosAgendamentos,
        'aviso_vencimento' => false,
        'dias_para_vencer' => null,
        'vencimento_data' => null,
        'codigo_barbearia' => null,
        'total_equipe' => 1,
        'atendimento' => [
            'total_mes' => 0,
            'concluidos_mes' => 0,
            'pendentes_mes' => 0,
            'cancelados_mes' => 0,
            'faltas_mes' => 0,
            'taxa_conclusao_mes' => 0
        ],
        'financeiro_gestao' => [
            'receita_servicos_mes' => $faturamentoMes,
            'receita_produtos_mes' => 0,
            'despesas_mes' => 0,
            'lucro_operacional_mes' => $faturamentoMes
        ],
        'historico_mensal' => [],
        'comissoes_funcionarios' => []
    ];
}

try {
    require_once '../conexao.php';

    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuario nao autenticado']);
        exit;
    }

    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = (int)$usuarioLogado['codigo'];

    $hoje = date('Y-m-d');
    $mesAtual = date('Y-m');
    $horaAtual = date('H:i:s');

    $nomeBarbearia = 'MustaBarber';
    $codigoBarbearia = null;

    try {
        $stmtBarb = $conexao->prepare(
            "SELECT codigo, nome_barbearia
             FROM barbearia
             WHERE codigo_usuario = :cod
             LIMIT 1"
        );
        $stmtBarb->bindValue(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmtBarb->execute();
        $barbearia = $stmtBarb->fetch(PDO::FETCH_ASSOC);
        if ($barbearia) {
            $codigoBarbearia = (int)$barbearia['codigo'];
            $nomeBarbearia = $barbearia['nome_barbearia'];
        }
    } catch (Exception $eBarb) {
        // Tabela barbearia pode nao existir em bases antigas.
    }

    $membrosEquipe = [
        [
            'codigo' => $codigoUsuario,
            'nome' => (string)($usuarioLogado['nome'] ?? 'Barbeiro')
        ]
    ];

    if ($codigoBarbearia) {
        try {
            $stmtEquipe = $conexao->prepare(
                "SELECT u.codigo, u.nome
                 FROM usuarios u
                 WHERE u.codigo_barbearia = :codigo_barbearia
                                     AND u.status = 1
                 ORDER BY u.nome ASC"
            );
            $stmtEquipe->bindValue(':codigo_barbearia', $codigoBarbearia, PDO::PARAM_INT);
            $stmtEquipe->execute();
            foreach ($stmtEquipe->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $membrosEquipe[] = [
                    'codigo' => (int)$f['codigo'],
                    'nome' => (string)$f['nome']
                ];
            }
        } catch (Exception $eEquipe) {
            // Coluna codigo_barbearia nao existe ainda.
        }
    }

    $mapUnico = [];
    foreach ($membrosEquipe as $m) {
        $mapUnico[(int)$m['codigo']] = $m;
    }
    $membrosEquipe = array_values($mapUnico);
    $idsEquipe = array_map(static function($m) { return (int)$m['codigo']; }, $membrosEquipe);

    $paramsIds = [];
    $inIds = montarPlaceholdersIds($idsEquipe, $paramsIds, 'e');

    $paramsHoje = $paramsIds;
    $paramsHoje[':hoje'] = $hoje;
    $paramsHoje[':hora_atual'] = $horaAtual;

    $totalClientes = (int)scalar(
        $conexao,
        "SELECT COUNT(*)
         FROM clientes
         WHERE cod_barbeiro IN ($inIds)",
        $paramsIds
    );

    $agendamentosHoje = (int)scalar(
        $conexao,
        "SELECT COUNT(*)
         FROM agendamentos
         WHERE codigo_barbeiro IN ($inIds)
           AND data_agendamento = :hoje
           AND codigo_status NOT IN (5, 6)",
        $paramsHoje
    );

    $stmtProximo = $conexao->prepare(
        "SELECT horario
         FROM agendamentos
         WHERE codigo_barbeiro IN ($inIds)
             AND (
                 data_agendamento > :hoje
                 OR (data_agendamento = :hoje AND horario >= :hora_atual)
             )
           AND codigo_status NOT IN (4, 5, 6)
            ORDER BY data_agendamento ASC, horario ASC
         LIMIT 1"
    );
    foreach ($paramsHoje as $k => $v) {
        $stmtProximo->bindValue($k, $v);
    }
    $stmtProximo->execute();
    $proximoRow = $stmtProximo->fetch(PDO::FETCH_ASSOC);
    $proximoHorario = $proximoRow ? substr($proximoRow['horario'], 0, 5) : null;

    $faturamentoHoje = (float)scalar(
        $conexao,
        "SELECT COALESCE(SUM(valor), 0)
         FROM agendamentos
         WHERE codigo_barbeiro IN ($inIds)
           AND data_agendamento = :hoje
           AND codigo_status = 4",
        $paramsHoje
    );

    $paramsMes = $paramsIds;
    $paramsMes[':mes'] = $mesAtual;

    $faturamentoMes = (float)scalar(
        $conexao,
        "SELECT COALESCE(SUM(valor), 0)
         FROM agendamentos
         WHERE codigo_barbeiro IN ($inIds)
           AND TO_CHAR(data_agendamento, 'YYYY-MM') = :mes
           AND codigo_status = 4",
        $paramsMes
    );

    $aReceber = (float)scalar(
        $conexao,
        "SELECT COALESCE(SUM(valor), 0)
         FROM agendamentos
         WHERE codigo_barbeiro IN ($inIds)
           AND data_agendamento = :hoje
           AND codigo_status NOT IN (4, 5, 6)",
        $paramsHoje
    );

    $stmtProximos = $conexao->prepare(
        "SELECT
              a.data_agendamento,
            a.horario,
            a.cliente_nome,
            a.valor,
            u.nome AS barbeiro_nome,
            s.nome AS servico_nome
         FROM agendamentos a
         INNER JOIN usuarios u ON u.codigo = a.codigo_barbeiro
         LEFT JOIN servicos s ON a.codigo_servico = s.codigo
         WHERE a.codigo_barbeiro IN ($inIds)
             AND (
                 a.data_agendamento > :hoje
                 OR (a.data_agendamento = :hoje AND a.horario >= :hora_atual)
             )
           AND a.codigo_status NOT IN (4, 5, 6)
            ORDER BY a.data_agendamento ASC, a.horario ASC"
    );
    foreach ($paramsHoje as $k => $v) {
        $stmtProximos->bindValue($k, $v);
    }
    $stmtProximos->execute();
    $proximosRows = $stmtProximos->fetchAll(PDO::FETCH_ASSOC);

    $proximosAgendamentos = array_map(static function($row) {
        return [
            'data_agendamento' => $row['data_agendamento'],
            'horario' => substr($row['horario'], 0, 5),
            'cliente_nome' => $row['cliente_nome'],
            'barbeiro_nome' => $row['barbeiro_nome'] ?? '',
            'servico_nome' => $row['servico_nome'] ?? '',
            'valor' => (float)$row['valor']
        ];
    }, $proximosRows);

    $atendimentoConcluidosMes = (int)scalar(
        $conexao,
        "SELECT COUNT(*)
         FROM agendamentos
         WHERE codigo_barbeiro IN ($inIds)
           AND TO_CHAR(data_agendamento, 'YYYY-MM') = :mes
           AND codigo_status = 4",
        $paramsMes
    );

    $atendimentoCanceladosMes = (int)scalar(
        $conexao,
        "SELECT COUNT(*)
         FROM agendamentos
         WHERE codigo_barbeiro IN ($inIds)
           AND TO_CHAR(data_agendamento, 'YYYY-MM') = :mes
           AND codigo_status = 5",
        $paramsMes
    );

    $atendimentoFaltasMes = (int)scalar(
        $conexao,
        "SELECT COUNT(*)
         FROM agendamentos
         WHERE codigo_barbeiro IN ($inIds)
           AND TO_CHAR(data_agendamento, 'YYYY-MM') = :mes
           AND codigo_status = 6",
        $paramsMes
    );

    $atendimentoPendentesMes = (int)scalar(
        $conexao,
        "SELECT COUNT(*)
         FROM agendamentos
         WHERE codigo_barbeiro IN ($inIds)
           AND TO_CHAR(data_agendamento, 'YYYY-MM') = :mes
           AND codigo_status NOT IN (4, 5, 6)",
        $paramsMes
    );

    $atendimentoTotalMes = $atendimentoConcluidosMes + $atendimentoCanceladosMes + $atendimentoFaltasMes + $atendimentoPendentesMes;
    $taxaConclusaoMes = $atendimentoTotalMes > 0
        ? round(($atendimentoConcluidosMes * 100) / $atendimentoTotalMes, 1)
        : 0.0;

    $receitaServicosMes = $faturamentoMes;

    $receitaProdutosMes = 0.0;
    $despesasMes = 0.0;
    $transacoesDisponivel = false;
    try {
        $transacoesDisponivel = tabelaExiste($conexao, 'transacoes');
    } catch (Exception $eTabelaTransacao) {
        $transacoesDisponivel = false;
    }

    if ($transacoesDisponivel) {
        $receitaProdutosMes = (float)scalar(
            $conexao,
            "SELECT COALESCE(SUM(valor), 0)
             FROM transacoes
             WHERE codigo_barbeiro IN ($inIds)
               AND tipo_transacao = 1
               AND categoria = 2
               AND TO_CHAR(data_transacao, 'YYYY-MM') = :mes",
            $paramsMes
        );

        $despesasMes = (float)scalar(
            $conexao,
            "SELECT COALESCE(SUM(valor), 0)
             FROM transacoes
             WHERE codigo_barbeiro IN ($inIds)
               AND tipo_transacao = 2
               AND TO_CHAR(data_transacao, 'YYYY-MM') = :mes",
            $paramsMes
        );
    }

    $lucroOperacionalMes = $receitaServicosMes + $receitaProdutosMes - $despesasMes;

    $historicoMensal = [];
    for ($i = 5; $i >= 0; $i--) {
        $mesRef = date('Y-m', strtotime("-$i month"));
        $paramsMesRef = $paramsIds;
        $paramsMesRef[':mes_ref'] = $mesRef;

        $servicosMesRef = (float)scalar(
            $conexao,
            "SELECT COALESCE(SUM(valor), 0)
             FROM agendamentos
             WHERE codigo_barbeiro IN ($inIds)
               AND TO_CHAR(data_agendamento, 'YYYY-MM') = :mes_ref
               AND codigo_status = 4",
            $paramsMesRef
        );

        $produtosMesRef = 0.0;
        if ($transacoesDisponivel) {
            $produtosMesRef = (float)scalar(
                $conexao,
                "SELECT COALESCE(SUM(valor), 0)
                 FROM transacoes
                 WHERE codigo_barbeiro IN ($inIds)
                   AND tipo_transacao = 1
                   AND categoria = 2
                   AND TO_CHAR(data_transacao, 'YYYY-MM') = :mes_ref",
                $paramsMesRef
            );
        }

        $atendConcluidosMesRef = (int)scalar(
            $conexao,
            "SELECT COUNT(*)
             FROM agendamentos
             WHERE codigo_barbeiro IN ($inIds)
               AND TO_CHAR(data_agendamento, 'YYYY-MM') = :mes_ref
               AND codigo_status = 4",
            $paramsMesRef
        );

        $historicoMensal[] = [
            'mes' => $mesRef,
            'label' => date('m/Y', strtotime($mesRef . '-01')),
            'receita_servicos' => $servicosMesRef,
            'receita_produtos' => $produtosMesRef,
            'atendimentos_concluidos' => $atendConcluidosMesRef
        ];
    }

    $comissoesFuncionarios = [];
    if ($codigoBarbearia) {
        try {
            $comissoesTabelaExiste = tabelaExiste($conexao, 'comissoes_funcionarios');
            if (!$comissoesTabelaExiste) {
                throw new Exception('Tabela de comissoes ainda nao existe.');
            }

            $stmtComissoes = $conexao->prepare(
                "SELECT
                    u.codigo,
                    u.nome,
                    COALESCE(cf.percentual_servicos, 0) AS percentual_servicos,
                    COALESCE(cf.percentual_produtos, 0) AS percentual_produtos,
                    COALESCE((
                        SELECT SUM(a.valor)
                        FROM agendamentos a
                        WHERE a.codigo_barbeiro = u.codigo
                          AND a.codigo_status = 4
                          AND TO_CHAR(a.data_agendamento, 'YYYY-MM') = :mes
                    ), 0) AS receita_servicos_mes,
                    COALESCE((
                        SELECT SUM(t.valor)
                        FROM transacoes t
                        WHERE t.codigo_barbeiro = u.codigo
                          AND t.tipo_transacao = 1
                          AND t.categoria = 2
                          AND TO_CHAR(t.data_transacao, 'YYYY-MM') = :mes2
                    ), 0) AS receita_produtos_mes
                 FROM usuarios u
                 LEFT JOIN comissoes_funcionarios cf
                    ON cf.codigo_funcionario = u.codigo
                   AND cf.codigo_barbearia = :codigo_barbearia
                 WHERE u.codigo_barbearia = :codigo_barbearia2
                                     AND u.status = 1
                 ORDER BY u.nome ASC"
            );
            $stmtComissoes->bindValue(':mes', $mesAtual);
            $stmtComissoes->bindValue(':mes2', $mesAtual);
            $stmtComissoes->bindValue(':codigo_barbearia', $codigoBarbearia, PDO::PARAM_INT);
            $stmtComissoes->bindValue(':codigo_barbearia2', $codigoBarbearia, PDO::PARAM_INT);
            $stmtComissoes->execute();

            foreach ($stmtComissoes->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $percentualServicos = (float)$row['percentual_servicos'];
                $percentualProdutos = (float)$row['percentual_produtos'];
                $receitaServicosFuncionario = (float)$row['receita_servicos_mes'];
                $receitaProdutosFuncionario = (float)$row['receita_produtos_mes'];
                $repasseEstimado =
                    ($receitaServicosFuncionario * ($percentualServicos / 100.0)) +
                    ($receitaProdutosFuncionario * ($percentualProdutos / 100.0));

                $comissoesFuncionarios[] = [
                    'codigo_funcionario' => (int)$row['codigo'],
                    'nome_funcionario' => $row['nome'],
                    'percentual_servicos' => $percentualServicos,
                    'percentual_produtos' => $percentualProdutos,
                    'receita_servicos_mes' => $receitaServicosFuncionario,
                    'receita_produtos_mes' => $receitaProdutosFuncionario,
                    'repasse_estimado_mes' => round($repasseEstimado, 2)
                ];
            }
        } catch (Exception $eComissoes) {
            $comissoesFuncionarios = [];
        }
    }

    $diasParaVencer = null;
    $avisoVencimento = false;
    $vencimentoData = null;

    try {
        $stmtIsentoDash = $conexao->prepare(
            "SELECT COALESCE(isento_pagamento, FALSE) AS isento_pagamento
             FROM usuarios
             WHERE codigo = :cod
             LIMIT 1"
        );
        $stmtIsentoDash->bindValue(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmtIsentoDash->execute();
        $dadosIsentoDash = $stmtIsentoDash->fetch(PDO::FETCH_ASSOC);
        $isentoDash = $dadosIsentoDash && ($dadosIsentoDash['isento_pagamento'] === true || $dadosIsentoDash['isento_pagamento'] === 't');

        if (!$isentoDash) {
            $stmtAssinDash = $conexao->prepare(
                "SELECT status_pagamento, data_vencimento
                 FROM assinaturas
                 WHERE codigo_barbeiro = :cod
                 ORDER BY codigo DESC
                 LIMIT 1"
            );
            $stmtAssinDash->bindValue(':cod', $codigoUsuario, PDO::PARAM_INT);
            $stmtAssinDash->execute();
            $assinDash = $stmtAssinDash->fetch(PDO::FETCH_ASSOC);

            if ($assinDash && in_array((int)$assinDash['status_pagamento'], [1, 2], true)) {
                $hojeD = new DateTime('today');
                $vencD = new DateTime($assinDash['data_vencimento']);
                $diff = (int)$hojeD->diff($vencD)->days * ($vencD >= $hojeD ? 1 : -1);
                $diasParaVencer = $diff;
                $vencimentoData = $vencD->format('d/m/Y');
                if ($diasParaVencer <= 2 && $diasParaVencer >= -1) {
                    $avisoVencimento = true;
                }
            }
        }
    } catch (Exception $eDash) {
        // Nao bloquear resposta por falha no aviso de vencimento.
    }

    echo json_encode([
        'sucesso' => true,

        // Compatibilidade com dashboard atual.
        'nome_barbearia' => $nomeBarbearia,
        'total_clientes' => $totalClientes,
        'agendamentos_hoje' => $agendamentosHoje,
        'proximo_horario' => $proximoHorario,
        'faturamento_hoje' => $faturamentoHoje,
        'faturamento_mes' => $faturamentoMes,
        'a_receber' => $aReceber,
        'proximos_agendamentos' => $proximosAgendamentos,
        'aviso_vencimento' => $avisoVencimento,
        'dias_para_vencer' => $diasParaVencer,
        'vencimento_data' => $vencimentoData,

        // Novos dados gerenciais.
        'codigo_barbearia' => $codigoBarbearia,
        'total_equipe' => count($membrosEquipe),
        'atendimento' => [
            'total_mes' => $atendimentoTotalMes,
            'concluidos_mes' => $atendimentoConcluidosMes,
            'pendentes_mes' => $atendimentoPendentesMes,
            'cancelados_mes' => $atendimentoCanceladosMes,
            'faltas_mes' => $atendimentoFaltasMes,
            'taxa_conclusao_mes' => $taxaConclusaoMes
        ],
        'financeiro_gestao' => [
            'receita_servicos_mes' => $receitaServicosMes,
            'receita_produtos_mes' => $receitaProdutosMes,
            'despesas_mes' => $despesasMes,
            'lucro_operacional_mes' => round($lucroOperacionalMes, 2)
        ],
        'historico_mensal' => $historicoMensal,
        'comissoes_funcionarios' => $comissoesFuncionarios
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('[obter_dados_dashboard] PDOException: ' . $e->getMessage());
    if (isset($conexao) && isset($codigoUsuario) && isset($hoje) && isset($mesAtual) && isset($horaAtual)) {
        $fallback = montarRespostaBasicaDashboard($conexao, (int)$codigoUsuario, $hoje, $mesAtual, $horaAtual);
        $fallback['mensagem'] = 'Carregado em modo basico por falha no dashboard avancado.';
        echo json_encode($fallback, JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro no banco de dados.'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    if (isset($conexao) && isset($codigoUsuario) && isset($hoje) && isset($mesAtual) && isset($horaAtual)) {
        $fallback = montarRespostaBasicaDashboard($conexao, (int)$codigoUsuario, $hoje, $mesAtual, $horaAtual);
        $fallback['mensagem'] = 'Carregado em modo basico por falha no dashboard avancado.';
        echo json_encode($fallback, JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}
?>
