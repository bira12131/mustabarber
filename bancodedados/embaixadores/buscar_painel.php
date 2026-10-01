<?php
/**
 * API: Buscar dados do embaixador logado
 * Sistema: MustaBarber
 *
 * Retorna dados do painel com cálculos mensais:
 *  - conta_ativa: assinatura do indicado está ativa (paga ou dentro do prazo)
 *  - pagou_mes_atual: o indicado pagou a mensalidade do mês corrente
 *  - comissão calculada APENAS sobre pagamentos do mês atual
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

requererLogin();

$usuario = obterUsuarioLogado();
$codigoUsuario = (int) ($usuario['codigo'] ?? 0);

if ($codigoUsuario === 0) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não identificado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Verificar se é embaixador e obter slug
    $stmtEmb = $conexao->prepare(
        "SELECT embaixador, referral_slug, nome, email
         FROM usuarios
         WHERE codigo = :cod LIMIT 1"
    );
    $stmtEmb->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
    $stmtEmb->execute();
    $dadosEmb = $stmtEmb->fetch(PDO::FETCH_ASSOC);

    if (!$dadosEmb || !($dadosEmb['embaixador'] === true || $dadosEmb['embaixador'] === 't')) {
        echo json_encode([
            'sucesso' => false,
            'embaixador' => false,
            'mensagem' => 'Você não possui o perfil de embaixador.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $slug = $dadosEmb['referral_slug'];

    // 2. Gerar slug caso ainda não exista
    if (empty($slug)) {
        $slug = gerarSlugUnico($conexao);
        $stmtSlug = $conexao->prepare(
            "UPDATE usuarios SET referral_slug = :slug WHERE codigo = :cod"
        );
        $stmtSlug->bindParam(':slug', $slug, PDO::PARAM_STR);
        $stmtSlug->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmtSlug->execute();
    }

    // 3. Buscar indicações com última assinatura e assinatura do mês atual (LATERAL join)
    $stmtInd = $conexao->prepare(
        "SELECT
            u.codigo           AS codigo_indicado,
            u.nome             AS nome_indicado,
            u.email            AS email_indicado,
            u.status           AS status_indicado,
            i.data_cadastro    AS data_indicacao,
            i.convertido,
            i.data_conversao,
            i.desconto_aplicado,
            p.nome             AS nome_plano,
            -- Última assinatura (determina se conta está ativa)
            ault.valor_mensalidade  AS valor_mensalidade,
            ault.valor_sem_desconto AS valor_sem_desconto,
            ault.status_pagamento   AS status_pagamento,
            ault.data_vencimento    AS data_vencimento,
            -- Assinatura do mês atual (para verificar pagamento e base da comissão)
            ames.codigo             AS cod_ass_mes,
            ames.valor_mensalidade  AS valor_mes_atual,
            ames.status_pagamento   AS status_mes_atual,
            ames.data_pagamento     AS data_pagamento_mes,
            ames.data_vencimento    AS vencimento_mes_atual
         FROM indicacoes i
         INNER JOIN usuarios u ON u.codigo = i.codigo_indicado
         LEFT JOIN LATERAL (
             SELECT valor_mensalidade, valor_sem_desconto, status_pagamento, data_vencimento
             FROM assinaturas
             WHERE codigo_barbeiro = u.codigo
             ORDER BY codigo DESC
             LIMIT 1
         ) ault ON true
         LEFT JOIN LATERAL (
             SELECT codigo, valor_mensalidade, status_pagamento, data_pagamento, data_vencimento
             FROM assinaturas
             WHERE codigo_barbeiro = u.codigo
               AND date_trunc('month', data_vencimento) = date_trunc('month', CURRENT_DATE)
             ORDER BY codigo DESC
             LIMIT 1
         ) ames ON true
         LEFT JOIN LATERAL (
             SELECT perfil FROM usuarios WHERE codigo = u.codigo LIMIT 1
         ) up ON true
         LEFT JOIN perfil p ON p.codigo = up.perfil
         WHERE i.codigo_embaixador = :cod
         ORDER BY i.data_cadastro DESC"
    );
    $stmtInd->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
    $stmtInd->execute();
    $indicacoes = $stmtInd->fetchAll(PDO::FETCH_ASSOC);

    // 4. Processar indicações e calcular totais do mês atual
    $hoje         = date('Y-m-d');
    $totalPagoMes = 0.00;

    foreach ($indicacoes as &$ind) {
        // Normalizar booleano do PostgreSQL
        $convertido = ($ind['convertido'] === 't' || $ind['convertido'] === true || $ind['convertido'] === 1);

        $valor     = (float)($ind['valor_mensalidade'] ?? 0);
        $valorMes  = (float)($ind['valor_mes_atual'] ?? 0);
        $statusUlt = (int)($ind['status_pagamento'] ?? 0);
        $statusMes = (int)($ind['status_mes_atual'] ?? 0);
        $vencimento = $ind['data_vencimento'] ?? null;

        // Conta ativa = convertido + última assinatura não cancelada + (paga OU vencimento no futuro/hoje)
        $contaAtiva = $convertido
            && $statusUlt !== 4
            && ($statusUlt === 2 || ($vencimento && $vencimento >= $hoje));

        // Pagou o mês atual = existe registro do mês corrente com status pago (2)
        $pagouMesAtual = ($ind['cod_ass_mes'] !== null && $statusMes === 2);

        // Somar apenas pagamentos efetuados no mês atual para base da comissão
        if ($pagouMesAtual && $valorMes > 0) {
            $totalPagoMes += $valorMes;
        }

        // Formatar e incluir campos calculados
        // Período gratuito ativo = não convertido E cadastro há menos de 7 dias
        $dataCadastro = $ind['data_indicacao'] ?? null;
        $periodoGratuitoAtivo = !$convertido
            && $dataCadastro
            && (strtotime($dataCadastro) >= strtotime('-7 days'));

        $ind['convertido']             = $convertido;
        $ind['conta_ativa']            = $contaAtiva;
        $ind['periodo_gratuito_ativo'] = $periodoGratuitoAtivo;
        $ind['pagou_mes_atual']        = $pagouMesAtual;
        $ind['valor_mensalidade_fmt']  = $valor > 0 ? number_format($valor, 2, ',', '.') : null;
        $ind['valor_mes_atual_fmt']    = $valorMes > 0 ? number_format($valorMes, 2, ',', '.') : null;
        $ind['valor_sem_desconto_fmt'] = $ind['valor_sem_desconto']
            ? number_format((float)$ind['valor_sem_desconto'], 2, ',', '.') : null;
        $ind['data_indicacao_fmt']     = $ind['data_indicacao']
            ? date('d/m/Y', strtotime($ind['data_indicacao'])) : null;
        $ind['data_conversao_fmt']     = $ind['data_conversao']
            ? date('d/m/Y', strtotime($ind['data_conversao'])) : null;
        $ind['data_pagamento_mes_fmt'] = $ind['data_pagamento_mes']
            ? date('d/m/Y', strtotime($ind['data_pagamento_mes'])) : null;
        $ind['vencimento_mes_fmt']     = $ind['vencimento_mes_atual']
            ? date('d/m/Y', strtotime($ind['vencimento_mes_atual'])) : null;
        $ind['desconto_aplicado']      = (float)$ind['desconto_aplicado'];

        unset($ind['cod_ass_mes']);
    }
    unset($ind);

    $percentualComissao = 15.0;
    $comissaoMes        = round($totalPagoMes * ($percentualComissao / 100), 2);

    // Mês de referência em português
    $mesesPt  = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
                 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    $mesRef   = $mesesPt[(int)date('n')] . '/' . date('Y');

    // Construir link de referral
    $protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $linkReferral = $protocolo . '://' . $host . '/paginas/usuarios/cadastro-barbeiro.html?ref=' . urlencode($slug);

    echo json_encode([
        'sucesso'              => true,
        'embaixador'           => true,
        'nome'                 => $dadosEmb['nome'],
        'email'                => $dadosEmb['email'],
        'referral_slug'        => $slug,
        'link_referral'        => $linkReferral,
        'mes_referencia'       => $mesRef,
        'percentual_comissao'  => $percentualComissao,
        'total_pago_mes'       => $totalPagoMes,
        'total_pago_mes_fmt'   => 'R$ ' . number_format($totalPagoMes, 2, ',', '.'),
        'comissao_mes'         => $comissaoMes,
        'comissao_mes_fmt'     => 'R$ ' . number_format($comissaoMes, 2, ',', '.'),
        'total_indicacoes'     => count($indicacoes),
        'total_convertidos'    => count(array_filter($indicacoes, fn($i) => $i['convertido'])),
        'total_pagaram_mes'    => count(array_filter($indicacoes, fn($i) => $i['pagou_mes_atual'])),
        'indicacoes'           => $indicacoes,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('[embaixador/buscar_painel.php] ' . $e->getMessage());
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao buscar dados do embaixador.'
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Gera um slug único de 8 caracteres alfanuméricos para o referral.
 */
function gerarSlugUnico(PDO $conexao): string {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    do {
        $slug = '';
        for ($i = 0; $i < 8; $i++) {
            $slug .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $stmtCheck = $conexao->prepare("SELECT 1 FROM usuarios WHERE referral_slug = :slug LIMIT 1");
        $stmtCheck->bindParam(':slug', $slug, PDO::PARAM_STR);
        $stmtCheck->execute();
    } while ($stmtCheck->fetchColumn() !== false);

    return $slug;
}
