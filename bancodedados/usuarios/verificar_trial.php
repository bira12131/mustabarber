<?php
/**
 * Verificar Status do Trial
 * Sistema: MustaBarber
 * Data: 26/03/2026
 *
 * Retorna o status do per�odo de teste gratuito do usu�rio logado.
 * Usado pelo JS do sistema para atualizar a interface dinamicamente.
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';
require_once 'sessao.php';

try {
    if (!estaLogado()) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usu�rio n�o autenticado'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario = obterUsuarioLogado();
    $codigoUsuario = (int) $usuario['codigo'];
    $perfilUsuario = (int) $usuario['perfil'];

    // Admins e perfis não-Solo não passam por trial
    if ($perfilUsuario !== 2) {
        echo json_encode([
            'sucesso' => true,
            'em_trial' => false,
            'trial_expirado' => false,
            'tem_assinatura_ativa' => true,
            'dias_restantes' => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Buscar dados extra do usu�rio
    $stmtUser = $conexao->prepare(
        "SELECT data_cadastro,
                COALESCE(isento_pagamento, FALSE) AS isento_pagamento
         FROM usuarios
         WHERE codigo = :codigo
         LIMIT 1"
    );
    $stmtUser->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmtUser->execute();
    $dadosUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$dadosUser) {
        throw new Exception('Usu�rio n�o encontrado.');
    }

    $isento = ($dadosUser['isento_pagamento'] === true || $dadosUser['isento_pagamento'] === 't');

    // Verificar em tempo real se os 5 indicados continuam ativos e pagantes
    if ($isento) {
        $stmtCheckInd = $conexao->prepare(
            "SELECT COUNT(*) AS total
             FROM indicacoes i
             INNER JOIN usuarios u ON u.codigo = i.codigo_indicado
             WHERE i.codigo_indicador = :codigo
               AND i.convertido = TRUE
               AND u.status = 1"
        );
        $stmtCheckInd->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
        $stmtCheckInd->execute();
        $checkInd = $stmtCheckInd->fetch(PDO::FETCH_ASSOC);
        if ((int) $checkInd['total'] < 5) {
            // Indicados ficaram inativos � suspender isen��o
            $isento = false;
            $conexao->prepare("UPDATE usuarios SET isento_pagamento = FALSE WHERE codigo = :cod")
                ->execute([':cod' => $codigoUsuario]);
        }
    }

    // Isento de pagamento &#8594; acesso pleno
    if ($isento) {
        echo json_encode([
            'sucesso' => true,
            'em_trial' => false,
            'trial_expirado' => false,
            'tem_assinatura_ativa' => true,
            'isento_pagamento' => true,
            'dias_restantes' => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Verificar assinatura paga ativa
    $stmtAssin = $conexao->prepare(
        "SELECT status_pagamento, data_vencimento
         FROM assinaturas
         WHERE codigo_barbeiro = :codigo
         ORDER BY codigo DESC
         LIMIT 1"
    );
    $stmtAssin->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmtAssin->execute();
    $assinatura = $stmtAssin->fetch(PDO::FETCH_ASSOC);

    $temAssinaturaAtiva = false;
    if ($assinatura) {
        $hoje = new DateTime('today');
        $venc = new DateTime($assinatura['data_vencimento']);
        $temAssinaturaAtiva = ((int) $assinatura['status_pagamento'] === 2 && $venc >= $hoje);
    }

    if ($temAssinaturaAtiva) {
        echo json_encode([
            'sucesso' => true,
            'em_trial' => false,
            'trial_expirado' => false,
            'tem_assinatura_ativa' => true,
            'isento_pagamento' => false,
            'dias_restantes' => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Sem assinatura ativa &#8594; verificar per�odo trial (7 dias a partir de data_cadastro)
    $dataCadastro = new DateTime($dadosUser['data_cadastro']);
    $hojeCheck = new DateTime('today');
    $diasDecorridos = (int) $hojeCheck->diff($dataCadastro)->days;
    $diasRestantes = max(0, 7 - $diasDecorridos);
    $emTrial = ($diasDecorridos <= 7);
    $trialExpirado = ($diasDecorridos > 7);

    echo json_encode([
        'sucesso' => true,
        'em_trial' => $emTrial,
        'trial_expirado' => $trialExpirado,
        'tem_assinatura_ativa' => false,
        'isento_pagamento' => false,
        'dias_restantes' => $diasRestantes,
        'dias_decorridos' => $diasDecorridos,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro no banco de dados.'
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
