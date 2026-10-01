<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(0);

require_once 'sessao.php';
require_once '../conexao.php';

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usu&aacute;rio n&atilde;o autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['sucesso' => false, 'mensagem' => 'M&eacute;todo n&atilde;o permitido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Dados inv&aacute;lidos'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario = obterUsuarioLogado();
    $codigoUsuario = intval($usuario['codigo']);

    $senhaAtual  = $input['senha_atual'] ?? '';
    $novaSenha   = $input['nova_senha'] ?? '';
    $confirmar   = $input['confirmar_senha'] ?? '';

    if (empty($senhaAtual) || empty($novaSenha) || empty($confirmar)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Preencha todos os campos'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($novaSenha !== $confirmar) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'A nova senha e a confirma&ccedil;&atilde;o n&atilde;o coincidem'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (strlen($novaSenha) < 6) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'A nova senha deve ter pelo menos 6 caracteres'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Buscar hash da senha atual
    $stmtBuscar = $conexao->prepare("SELECT senha FROM usuarios WHERE codigo = :codigo LIMIT 1");
    $stmtBuscar->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmtBuscar->execute();
    $dados = $stmtBuscar->fetch(PDO::FETCH_ASSOC);

    if (!$dados || !password_verify($senhaAtual, $dados['senha'])) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Senha atual incorreta'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $novoHash = password_hash($novaSenha, PASSWORD_BCRYPT);

    $stmtAtualizar = $conexao->prepare("UPDATE usuarios SET senha = :senha WHERE codigo = :codigo");
    $stmtAtualizar->bindParam(':senha', $novoHash);
    $stmtAtualizar->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmtAtualizar->execute();

    echo json_encode(['sucesso' => true, 'mensagem' => 'Senha alterada com sucesso!'], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao alterar senha: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
