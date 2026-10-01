<?php
/**
 * Verificar Status da Assinatura
 * Sistema: MustaBarber
 * Endpoint consultado pelo frontend para saber se o pagamento j� foi confirmado
 * e se o usu�rio � isento por indica��es.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'assinatura_ok' => false, 'mensagem' => 'N�o autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario        = obterUsuarioLogado();
    $codigoUsuario  = (int) $usuario['codigo'];

    //  Verificar se � isento de pagamento
    $stmtUser = $conexao->prepare(
        "SELECT COALESCE(isento_pagamento, FALSE) AS isento_pagamento,
                data_cadastro
         FROM usuarios
         WHERE codigo = :codigo
         LIMIT 1"
    );
    $stmtUser->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmtUser->execute();
    $dadosUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
    $isento = false;
    if ($dadosUser) {
        $isento = ($dadosUser['isento_pagamento'] === true || $dadosUser['isento_pagamento'] === 't');
    }

    // Se n�o est� marcado como isento, verificar se tem 5 indicados ativos e pagantes
    if (!$isento) {
        $stmtCheck = $conexao->prepare(
            "SELECT COUNT(*) AS total
             FROM indicacoes i
             INNER JOIN usuarios u ON u.codigo = i.codigo_indicado
             WHERE i.codigo_indicador = :codigo
               AND i.convertido = TRUE
               AND u.status = 1"
        );
        $stmtCheck->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
        $stmtCheck->execute();
        $check = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if ((int) $check['total'] >= 5) {
            $isento = true;
            // Corrigir a flag no banco
            $stmtFix = $conexao->prepare(
                "UPDATE usuarios SET isento_pagamento = TRUE WHERE codigo = :codigo AND isento_pagamento = FALSE"
            );
            $stmtFix->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
            $stmtFix->execute();
        }
    }

    $stmt = $conexao->prepare(
        "SELECT data_vencimento, valor_mensalidade
         FROM assinaturas
         WHERE codigo_barbeiro = :cod
         ORDER BY codigo DESC LIMIT 1"
    );
    $stmt->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
    $stmt->execute();
    $assinatura = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assinatura) {
        $assinaturaOk = false;
        if ($isento) {
            $assinaturaOk = true;
        } else {
            $dataCadastro = $dadosUser['data_cadastro'] ?? null;
            if ($dataCadastro) {
                $hoje = new DateTime('today');
                $cadastro = new DateTime((string)$dataCadastro);
                $cadastro->setTime(0, 0, 0);
                $diasDecorridos = (int)$hoje->diff($cadastro)->days;
                $assinaturaOk = ($cadastro > $hoje) ? true : ($diasDecorridos <= 7);
            }
        }

        echo json_encode([
            'sucesso' => true,
            'assinatura_ok' => $assinaturaOk,
            'isento_pagamento' => $isento
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $hoje      = new DateTime('today');
    $vencimento = new DateTime($assinatura['data_vencimento']);

    // Assinatura valida somente pela data de vencimento.
    $assinaturaOk = ($vencimento >= $hoje);

    // Calcular dias para vencimento (positivo = falta, negativo = vencido h� X dias)
    $diff = (int) $hoje->diff($vencimento)->days;
    if ($vencimento < $hoje) $diff = -$diff;

    echo json_encode([
        'sucesso'           => true,
        'assinatura_ok'     => $assinaturaOk,
        'data_vencimento'   => date('d/m/Y', strtotime($assinatura['data_vencimento'])),
        'valor'             => number_format((float)$assinatura['valor_mensalidade'], 2, ',', '.'),
        'dias'              => $diff,
        'isento_pagamento'  => $isento,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[VerificarAssinatura] ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'assinatura_ok' => false, 'mensagem' => 'Erro interno'], JSON_UNESCAPED_UNICODE);
}
?>
