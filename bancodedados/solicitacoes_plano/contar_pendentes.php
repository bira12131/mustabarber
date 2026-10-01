<?php
/**
 * Conta solicitações de plano pendentes do barbeiro logado
 * GET — retorna { sucesso, total }
 */
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

require_once '../usuarios/sessao.php';
require_once '../conexao.php';
require_once '../planos/plano_manager.php';

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'total' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $usuario = obterUsuarioLogado();
    $codigo  = intval($usuario['codigo']);
    $perfil  = intval($usuario['perfil'] ?? 0);

    exigirPlanoOuBloquear($conexao, $codigo, $perfil, 'solicitacoes_plano');

    $stmt = $conexao->prepare(
        "SELECT COUNT(*) AS total
         FROM solicitacoes_plano_cliente
         WHERE codigo_barbeiro = :barbeiro AND status = 'pendente'"
    );
    $stmt->bindParam(':barbeiro', $codigo, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['sucesso' => true, 'total' => intval($row['total'])], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'total' => 0], JSON_UNESCAPED_UNICODE);
}
?>
