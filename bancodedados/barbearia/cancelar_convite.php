<?php
/**
 * Cancelar Convite
 * Sistema: MustaBarber
 * Permite ao dono da barbearia cancelar um convite pendente.
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

    $usuarioLogado  = obterUsuarioLogado();
    $codigoUsuario  = (int)$usuarioLogado['codigo'];

    $dados          = json_decode(file_get_contents('php://input'), true);
    $codigoConvite  = isset($dados['codigo_convite']) ? (int)$dados['codigo_convite'] : 0;

    if ($codigoConvite <= 0) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Código de convite inválido.']);
        exit;
    }

    // Somente o dono pode cancelar
    $stmt = $conexao->prepare(
        "UPDATE convites
         SET status = 'cancelado', data_resposta = CURRENT_TIMESTAMP
         WHERE codigo = :cod
           AND codigo_usuario_dono = :dono
           AND status = 'pendente'"
    );
    $stmt->bindParam(':cod',  $codigoConvite, PDO::PARAM_INT);
    $stmt->bindParam(':dono', $codigoUsuario, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Convite não encontrado ou já respondido.']);
        exit;
    }

    echo json_encode(['sucesso' => true, 'mensagem' => 'Convite cancelado com sucesso.'], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
