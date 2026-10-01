<?php
/**
 * Adicionar Funcionário à Barbearia — ENDPOINT DESCONTINUADO
 * Use convidar_barbeiro.php no lugar deste endpoint.
 */
header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode([
    'sucesso'  => false,
    'mensagem' => 'Este endpoint foi descontinuado. Use o sistema de convites (convidar_barbeiro.php).'
]);
exit;


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

    // Verificar se o funcionário existe e está ativo
    $stmtUser = $conexao->prepare("SELECT codigo, nome, perfil, codigo_barbearia FROM usuarios WHERE codigo = :codigo AND status = 1");
    $stmtUser->bindParam(':codigo', $codigoFuncionario, PDO::PARAM_INT);
    $stmtUser->execute();
    $funcionario = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$funcionario) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não encontrado ou inativo.']);
        exit;
    }

    if (intval($funcionario['perfil']) == 1) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Administradores não podem ser adicionados como funcionários.']);
        exit;
    }

    if ($codigoFuncionario == $codigoUsuario) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Você não pode se adicionar como funcionário da sua própria barbearia.']);
        exit;
    }

    if (!is_null($funcionario['codigo_barbearia']) && intval($funcionario['codigo_barbearia']) == $codigoBarbearia) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Este usuário já é funcionário desta barbearia.']);
        exit;
    }

    // Vincular o funcionário à barbearia
    $stmtUp = $conexao->prepare("UPDATE usuarios SET codigo_barbearia = :codigo_barbearia WHERE codigo = :codigo");
    $stmtUp->bindParam(':codigo_barbearia', $codigoBarbearia, PDO::PARAM_INT);
    $stmtUp->bindParam(':codigo', $codigoFuncionario, PDO::PARAM_INT);
    $stmtUp->execute();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => $funcionario['nome'] . ' foi adicionado como funcionário com sucesso!'
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('[adicionar_funcionario] PDOException: ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro no banco de dados.'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
