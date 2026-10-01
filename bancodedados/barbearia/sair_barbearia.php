<?php
/**
 * Sair da Barbearia
 * Sistema: MustaBarber
 * Permite ao barbeiro sair voluntariamente da barbearia da qual faz parte.
 */
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

try {
    require_once '../conexao.php';
    require_once '../usuarios/sessao.php';

    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Não autenticado']);
        exit;
    }

    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = (int)$usuarioLogado['codigo'];

    // Verificar que o usuário está em alguma barbearia
    $stmtCheck = $conexao->prepare(
        "SELECT codigo_barbearia FROM usuarios
         WHERE codigo = :cod AND codigo_barbearia IS NOT NULL"
    );
    $stmtCheck->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
    $stmtCheck->execute();
    $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Você não faz parte de nenhuma barbearia.']);
        exit;
    }

    $codigoBarbearia = (int)$row['codigo_barbearia'];

    // Remover vínculo, reverter perfil para Solo Básico (2) e remover isenção
    $stmtUp = $conexao->prepare(
        "UPDATE usuarios
         SET codigo_barbearia = NULL,
             perfil           = 2,
             isento_pagamento = FALSE
         WHERE codigo = :cod"
    );
    $stmtUp->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
    $stmtUp->execute();

    // Registrar saída no histórico de convites
    $stmtConv = $conexao->prepare(
        "UPDATE convites
         SET status = 'saiu', data_resposta = CURRENT_TIMESTAMP
         WHERE codigo_barbearia = :barb
           AND codigo_usuario_convidado = :conv
           AND status = 'aceito'"
    );
    $stmtConv->bindParam(':barb', $codigoBarbearia, PDO::PARAM_INT);
    $stmtConv->bindParam(':conv', $codigoUsuario,   PDO::PARAM_INT);
    $stmtConv->execute();

    echo json_encode([
        'sucesso'  => true,
        'mensagem' => 'Você saiu da barbearia com sucesso.'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
