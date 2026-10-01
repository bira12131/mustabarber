<?php
ini_set('display_errors', 0);
error_reporting(0);

ob_clean();
header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';

$barbeiro = isset($_GET['barbeiro']) ? (int)$_GET['barbeiro'] : 0;

if (!$barbeiro) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Barbeiro n&atilde;o informado'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $hoje = date('Y-m-d');

    // Buscar somente paralisações ativas que ainda não terminaram
    $sql = "SELECT data_inicio, data_fim
            FROM agenda_paralisacao
            WHERE codigo_barbeiro = :barbeiro
              AND ativa = TRUE
              AND (data_fim IS NULL OR data_fim >= :hoje)
            ORDER BY data_inicio ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([':barbeiro' => $barbeiro, ':hoje' => $hoje]);
    $paralisacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'sucesso'      => true,
        'paralisacoes' => $paralisacoes
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso'  => false,
        'mensagem' => 'Erro ao buscar paralisa&ccedil;&otilde;es: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
