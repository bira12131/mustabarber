<?php
ini_set('display_errors', 0);
error_reporting(0);

session_start();
ob_clean();
header('Content-Type: application/json; charset=utf-8');

include_once '../conexao.php';

if (!isset($_SESSION['usuario_codigo'])) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Usu&aacute;rio n&atilde;o autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$codigo_barbeiro = $_SESSION['usuario_codigo'];

try {
    $sql = "SELECT codigo, data_inicio, data_fim, motivo, ativa, data_criacao
            FROM agenda_paralisacao
            WHERE codigo_barbeiro = :codigo_barbeiro
            ORDER BY data_criacao DESC
            LIMIT 20";

    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
    $stmt->execute();
    $paralisacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'sucesso' => true,
        'paralisacoes' => $paralisacoes
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao buscar paralisa&ccedil;&otilde;es: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
