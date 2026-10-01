<?php
/**
 * Contar Notificações Pendentes
 * Sistema: MustaBarber
 * Endpoint leve para polling de badge de notificação.
 */
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    require_once '../conexao.php';
    require_once '../usuarios/sessao.php';

    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'total' => 0]);
        exit;
    }

    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = (int)$usuarioLogado['codigo'];

    $stmt = $conexao->prepare(
        "SELECT COUNT(*) FROM convites
         WHERE codigo_usuario_convidado = :cod AND status = 'pendente'"
    );
    $stmt->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
    $stmt->execute();
    $totalConvites = (int)$stmt->fetchColumn();

    // Contar notificações do sistema não lidas
    // Isolado: se a tabela não existir, retorna 0 sem quebrar o endpoint
    $totalSistema = 0;
    try {
        $stmtSis = $conexao->prepare(
            "SELECT COUNT(*) FROM notificacoes_sistema
             WHERE codigo_usuario = :cod AND lida = FALSE"
        );
        $stmtSis->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmtSis->execute();
        $totalSistema = (int)$stmtSis->fetchColumn();
    } catch (Exception $eSis) {
        $totalSistema = 0;
    }

    $total = $totalConvites + $totalSistema;

    echo json_encode(['sucesso' => true, 'total' => $total]);

} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'total' => 0]);
}
?>
