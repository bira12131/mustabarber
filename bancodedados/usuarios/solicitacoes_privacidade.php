<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';
require_once 'sessao.php';

if (!estaLogado()) {
    http_response_code(401);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Usuario nao autenticado.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$usuario = obterUsuarioLogado();
$codigoUsuario = (int)($usuario['codigo'] ?? 0);

if ($codigoUsuario <= 0) {
    http_response_code(401);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Sessao invalida.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conexao->exec("\n        CREATE TABLE IF NOT EXISTS lgpd_solicitacoes (\n            codigo SERIAL PRIMARY KEY,\n            codigo_usuario INTEGER NOT NULL REFERENCES usuarios(codigo) ON DELETE CASCADE,\n            tipo VARCHAR(30) NOT NULL,\n            descricao TEXT NOT NULL,\n            status VARCHAR(20) NOT NULL DEFAULT 'aberta',\n            data_solicitacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            data_atualizacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n        )\n    ");

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $conexao->prepare("\n            SELECT codigo, tipo, descricao, status, data_solicitacao, data_atualizacao\n            FROM lgpd_solicitacoes\n            WHERE codigo_usuario = :codigo_usuario\n            ORDER BY codigo DESC\n            LIMIT 100\n        ");
        $stmt->bindValue(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
        $stmt->execute();

        echo json_encode([
            'sucesso' => true,
            'dados' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Metodo nao permitido.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $tipo = trim((string)($body['tipo'] ?? ''));
    $descricao = trim((string)($body['descricao'] ?? ''));

    $tiposPermitidos = ['acesso', 'correcao', 'portabilidade', 'eliminacao', 'oposicao'];
    if (!in_array($tipo, $tiposPermitidos, true)) {
        throw new Exception('Tipo de solicitacao invalido.');
    }

    if ($descricao === '' || strlen($descricao) < 10) {
        throw new Exception('Descreva sua solicitacao com pelo menos 10 caracteres.');
    }

    $stmtIns = $conexao->prepare("\n        INSERT INTO lgpd_solicitacoes (codigo_usuario, tipo, descricao)\n        VALUES (:codigo_usuario, :tipo, :descricao)\n        RETURNING codigo\n    ");
    $stmtIns->bindValue(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    $stmtIns->bindValue(':tipo', $tipo, PDO::PARAM_STR);
    $stmtIns->bindValue(':descricao', $descricao, PDO::PARAM_STR);
    $stmtIns->execute();

    $codigoSolicitacao = (int)$stmtIns->fetchColumn();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Solicitacao registrada com sucesso.',
        'protocolo' => 'LGPD-' . str_pad((string)$codigoSolicitacao, 6, '0', STR_PAD_LEFT)
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro interno ao processar solicitacao LGPD.'
    ], JSON_UNESCAPED_UNICODE);
}
