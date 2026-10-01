<?php
/**
 * Responder Convite (Aceitar ou Recusar)
 * Sistema: MustaBarber
 * Processa a resposta do convidado a um convite de barbearia.
 */
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

try {
    require_once '../conexao.php';
    require_once '../usuarios/sessao.php';
    require_once '../planos/plano_manager.php';

    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Não autenticado']);
        exit;
    }

    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = (int)$usuarioLogado['codigo'];

    $dados         = json_decode(file_get_contents('php://input'), true);
    $codigoConvite = isset($dados['codigo_convite']) ? (int)$dados['codigo_convite'] : 0;
    $resposta      = isset($dados['resposta']) ? trim($dados['resposta']) : ''; // 'aceitar' ou 'recusar'

    if ($codigoConvite <= 0 || !in_array($resposta, ['aceitar', 'recusar'])) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Dados inválidos.']);
        exit;
    }

    // Buscar convite e validar que pertence ao usuário logado
    $stmtConv = $conexao->prepare(
        "SELECT c.*,
                b.nome_barbearia,
                u.nome AS nome_dono,
                ud.perfil AS perfil_dono
         FROM convites c
         INNER JOIN barbearia b ON b.codigo = c.codigo_barbearia
         INNER JOIN usuarios u  ON u.codigo = c.codigo_usuario_dono
         INNER JOIN usuarios ud ON ud.codigo = c.codigo_usuario_dono
         WHERE c.codigo = :cod
           AND c.codigo_usuario_convidado = :convidado
           AND c.status = 'pendente'"
    );
    $stmtConv->bindParam(':cod',       $codigoConvite, PDO::PARAM_INT);
    $stmtConv->bindParam(':convidado', $codigoUsuario, PDO::PARAM_INT);
    $stmtConv->execute();
    $convite = $stmtConv->fetch(PDO::FETCH_ASSOC);

    if (!$convite) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Convite não encontrado ou já respondido.']);
        exit;
    }

    $codigoBarbearia = (int)$convite['codigo_barbearia'];

    if ($resposta === 'aceitar') {

        // Verificar vagas ainda disponíveis (race condition)
        $maxBarbeiros = getMaxBarbeirosPorPlano((int)$convite['perfil_dono']);

        $stmtCount = $conexao->prepare(
            "SELECT COUNT(*) FROM usuarios WHERE codigo_barbearia = :cod"
        );
        $stmtCount->bindParam(':cod', $codigoBarbearia, PDO::PARAM_INT);
        $stmtCount->execute();
        $totalAtual = (int)$stmtCount->fetchColumn();

        if ($totalAtual >= $maxBarbeiros) {
            // Cancelar convite — sem vagas
            $conexao->prepare(
                "UPDATE convites SET status = 'cancelado', data_resposta = CURRENT_TIMESTAMP WHERE codigo = :cod"
            )->execute([':cod' => $codigoConvite]);

            echo json_encode([
                'sucesso'  => false,
                'mensagem' => 'Não há mais vagas disponíveis nesta barbearia. O convite foi cancelado automaticamente.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Verificar se já está em outra barbearia
        $stmtChk = $conexao->prepare(
            "SELECT codigo_barbearia FROM usuarios WHERE codigo = :cod"
        );
        $stmtChk->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmtChk->execute();
        $userRow = $stmtChk->fetch(PDO::FETCH_ASSOC);

        if (!is_null($userRow['codigo_barbearia'])) {
            echo json_encode([
                'sucesso'  => false,
                'mensagem' => 'Você já faz parte de uma barbearia. Saia dela antes de aceitar este convite.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Vincular usuário à barbearia, definir perfil Solo Pro (3) e isentar de pagamento
        $stmtVinc = $conexao->prepare(
            "UPDATE usuarios
             SET codigo_barbearia  = :barb,
                 perfil            = 3,
                 isento_pagamento  = TRUE
             WHERE codigo = :cod"
        );
        $stmtVinc->bindParam(':barb', $codigoBarbearia, PDO::PARAM_INT);
        $stmtVinc->bindParam(':cod',  $codigoUsuario,   PDO::PARAM_INT);
        $stmtVinc->execute();

        // Marcar convite como aceito
        $conexao->prepare(
            "UPDATE convites SET status = 'aceito', data_resposta = CURRENT_TIMESTAMP WHERE codigo = :cod"
        )->execute([':cod' => $codigoConvite]);

        // Cancelar outros convites pendentes deste usuário (de outras barbearias)
        $stmtCancel = $conexao->prepare(
            "UPDATE convites
             SET status = 'cancelado', data_resposta = CURRENT_TIMESTAMP
             WHERE codigo_usuario_convidado = :conv
               AND status = 'pendente'
               AND codigo != :cod"
        );
        $stmtCancel->bindParam(':conv', $codigoUsuario, PDO::PARAM_INT);
        $stmtCancel->bindParam(':cod',  $codigoConvite, PDO::PARAM_INT);
        $stmtCancel->execute();

        echo json_encode([
            'sucesso'  => true,
            'aceito'   => true,
            'mensagem' => 'Convite aceito! Você agora faz parte de ' . $convite['nome_barbearia'] . '.'
        ], JSON_UNESCAPED_UNICODE);

    } else {
        // Recusar
        $conexao->prepare(
            "UPDATE convites SET status = 'recusado', data_resposta = CURRENT_TIMESTAMP WHERE codigo = :cod"
        )->execute([':cod' => $codigoConvite]);

        echo json_encode(['sucesso' => true, 'aceito' => false, 'mensagem' => 'Convite recusado.'], JSON_UNESCAPED_UNICODE);
    }

} catch (PDOException $e) {
    error_log('[responder_convite] PDOException: ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro no banco de dados.'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
