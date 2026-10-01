<?php
/**
 * Remover Funcionário da Barbearia
 * Sistema: MustaBarber
 * Data: 11/03/2026
 * Remove o vínculo de um usuário com a barbearia do usuário logado,
 * limpando o campo codigo_barbearia na tabela usuarios.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado']);
        exit;
    }

    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = $usuarioLogado['codigo'];

    // Verificar acesso por plano (funcionários exige plano Equipe ou superior)
    require_once '../planos/plano_manager.php';
    exigirPlanoOuBloquear($conexao, (int)$codigoUsuario, (int)$usuarioLogado['perfil'], 'funcionarios');

    $dados = json_decode(file_get_contents('php://input'), true);
    $codigoFuncionario = isset($dados['codigo_funcionario']) ? intval($dados['codigo_funcionario']) : 0;

    if ($codigoFuncionario <= 0) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Código do funcionário inválido.']);
        exit;
    }

    // Buscar barbearia do usuário logado
    $stmtBarb = $conexao->prepare("SELECT codigo FROM barbearia WHERE codigo_usuario = :codigo_usuario LIMIT 1");
    $stmtBarb->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    $stmtBarb->execute();
    $barbearia = $stmtBarb->fetch(PDO::FETCH_ASSOC);

    if (!$barbearia) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Você não possui uma barbearia cadastrada.']);
        exit;
    }

    $codigoBarbearia = intval($barbearia['codigo']);

    // Verificar se o funcionário pertence a esta barbearia
    $stmtUser = $conexao->prepare(
        "SELECT codigo, nome FROM usuarios WHERE codigo = :codigo AND codigo_barbearia = :codigo_barbearia"
    );
    $stmtUser->bindParam(':codigo', $codigoFuncionario, PDO::PARAM_INT);
    $stmtUser->bindParam(':codigo_barbearia', $codigoBarbearia, PDO::PARAM_INT);
    $stmtUser->execute();
    $funcionario = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$funcionario) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Funcionário não encontrado nesta barbearia.']);
        exit;
    }

    // Remover o vínculo, reverter perfil para Solo Básico (2) e remover isenção
    $stmtUp = $conexao->prepare(
        "UPDATE usuarios
         SET codigo_barbearia = NULL,
             perfil           = 2,
             isento_pagamento = FALSE
         WHERE codigo = :codigo"
    );
    $stmtUp->bindParam(':codigo', $codigoFuncionario, PDO::PARAM_INT);
    $stmtUp->execute();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => $funcionario['nome'] . ' foi removido da barbearia com sucesso!'
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('[remover_funcionario] PDOException: ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro no banco de dados.'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
