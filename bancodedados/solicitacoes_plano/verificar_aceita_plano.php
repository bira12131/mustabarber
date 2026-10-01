<?php
/**
 * Verificar se o barbeiro aceita solicitações de plano
 * Sistema: MustaBarber
 * Endpoint público — não requer login
 * GET ?barbeiro=<codigo>
 */

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';

try {
    $codigoBarbeiro = isset($_GET['barbeiro']) ? intval($_GET['barbeiro']) : 0;
    if ($codigoBarbeiro <= 0) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Barbeiro não informado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $conexao->prepare(
        "SELECT codigo, nome, foto_caminho,
                COALESCE(permite_solicitacao_plano, FALSE) AS permite_solicitacao_plano
         FROM usuarios
         WHERE codigo = :codigo AND status = 1
         LIMIT 1"
    );
    $stmt->bindParam(':codigo', $codigoBarbeiro, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Barbeiro não encontrado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $aceita = ($row['permite_solicitacao_plano'] === true
            || $row['permite_solicitacao_plano'] === 't'
            || $row['permite_solicitacao_plano'] == 1);

    echo json_encode([
        'sucesso'                  => true,
        'aceita_solicitacao_plano' => $aceita,
        'barbeiro' => [
            'codigo'       => intval($row['codigo']),
            'nome'         => $row['nome'],
            'foto_caminho' => $row['foto_caminho'],
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno'], JSON_UNESCAPED_UNICODE);
}
?>
