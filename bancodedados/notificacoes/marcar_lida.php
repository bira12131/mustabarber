<?php
/**
 * Marcar Notificação do Sistema como Lida
 * Sistema: MustaBarber
 */
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(0);

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario       = obterUsuarioLogado();
    $codigoUsuario = (int) $usuario['codigo'];

    $dados       = json_decode(file_get_contents('php://input'), true);
    $codigoNotif = (int)($dados['codigo'] ?? 0);

    if ($codigoNotif <= 0) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Código inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Garante que o usuário só pode marcar as próprias notificações
    $stmt = $conexao->prepare(
        "UPDATE notificacoes_sistema SET lida = TRUE
         WHERE codigo = :cod AND codigo_usuario = :user"
    );
    $stmt->bindParam(':cod',  $codigoNotif,    PDO::PARAM_INT);
    $stmt->bindParam(':user', $codigoUsuario,  PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode(['sucesso' => true], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno'], JSON_UNESCAPED_UNICODE);
}
?>
