<?php
/**
 * Renovar Assinatura Gratuitamente
 * Sistema: MustaBarber
 * Data: 28/03/2026
 *
 * Permite que barbeiros isentos de pagamento (5 indica��es convertidas)
 * renovem sua assinatura sem gerar cobran�a.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usu�rio n�o autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario = obterUsuarioLogado();
    $codigoUsuario = (int) $usuario['codigo'];

    //  Verificar se o usu�rio realmente est� isento
    $stmtUser = $conexao->prepare(
        "SELECT COALESCE(isento_pagamento, FALSE) AS isento_pagamento
         FROM usuarios
         WHERE codigo = :codigo
         LIMIT 1"
    );
    $stmtUser->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmtUser->execute();
    $dadosUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$dadosUser) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usu�rio n�o encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $isento = ($dadosUser['isento_pagamento'] === true || $dadosUser['isento_pagamento'] === 't');

    // Sempre verificar em tempo real se os 5 indicados continuam ativos e pagantes
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

    if ((int) $check['total'] < 5) {
        // Suspender isen��o se os indicados n�o est�o mais ativos
        if ($isento) {
            $conexao->prepare("UPDATE usuarios SET isento_pagamento = FALSE WHERE codigo = :cod")
                ->execute([':cod' => $codigoUsuario]);
        }
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Voc� n�o possui 5 indicados ativos e pagantes. N�o � poss�vel renovar gratuitamente.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Garantir que a flag esteja marcada
    if (!$isento) {
        $conexao->prepare("UPDATE usuarios SET isento_pagamento = TRUE WHERE codigo = :cod")
            ->execute([':cod' => $codigoUsuario]);
    }

    // Buscar assinatura atual
    $stmtAssin = $conexao->prepare(
        "SELECT codigo, data_vencimento FROM assinaturas
         WHERE codigo_barbeiro = :codigo
         ORDER BY codigo DESC LIMIT 1"
    );
    $stmtAssin->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmtAssin->execute();
    $assinatura = $stmtAssin->fetch(PDO::FETCH_ASSOC);

    if (!$assinatura) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Nenhuma assinatura encontrada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Calcular novo vencimento respeitando o dia preferido do barbeiro
    $sqlDia = "SELECT COALESCE(dia_vencimento, 1) AS dia_vencimento FROM usuarios WHERE codigo = :cod LIMIT 1";
    $stmtDia = $conexao->prepare($sqlDia);
    $stmtDia->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
    $stmtDia->execute();
    $diaRow = $stmtDia->fetch(PDO::FETCH_ASSOC);
    $diaVenc = (int) ($diaRow['dia_vencimento'] ?? 1);
    if ($diaVenc < 1 || $diaVenc > 28) $diaVenc = 1;

    $vencAtual = strtotime($assinatura['data_vencimento']);
    $hoje = strtotime(date('Y-m-d'));
    $baseTimestamp = ($vencAtual >= $hoje) ? $vencAtual : $hoje;

    $mesBase = (int) date('m', $baseTimestamp);
    $anoBase = (int) date('Y', $baseTimestamp);
    $mesProx = $mesBase + 1;
    $anoProx = $anoBase;
    if ($mesProx > 12) { $mesProx = 1; $anoProx++; }

    $diasNoMes = cal_days_in_month(CAL_GREGORIAN, $mesProx, $anoProx);
    $diaFinal = min($diaVenc, $diasNoMes);
    $novoVencimento = sprintf('%04d-%02d-%02d', $anoProx, $mesProx, $diaFinal);

    // Atualizar assinatura como paga (isen��o)
    $stmtUp = $conexao->prepare(
        "UPDATE assinaturas SET
            status_pagamento = 2,
            data_pagamento   = CURRENT_DATE,
            data_vencimento  = :novo_venc,
            forma_pagamento  = 'Isento (Indica��o)'
         WHERE codigo_barbeiro = :cod
           AND codigo = :cod_assin"
    );
    $stmtUp->bindParam(':novo_venc', $novoVencimento);
    $stmtUp->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
    $stmtUp->bindParam(':cod_assin', $assinatura['codigo'], PDO::PARAM_INT);
    $stmtUp->execute();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Assinatura renovada gratuitamente! Novo vencimento: ' . date('d/m/Y', strtotime($novoVencimento)),
        'novo_vencimento' => $novoVencimento
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro no banco de dados: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
