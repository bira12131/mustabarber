<?php
/**
 * Resolve token de link publico de agendamento.
 * Endpoint publico: recebe t=token e retorna o destino do agendamento.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once '../conexao.php';

function ensureSecureLinkTable(PDO $conn): void {
    $sql = "
        CREATE TABLE IF NOT EXISTS links_agendamento_publico (
            codigo SERIAL PRIMARY KEY,
            token_hash VARCHAR(64) UNIQUE NOT NULL,
            tipo VARCHAR(20) NOT NULL,
            codigo_barbeiro INTEGER,
            codigo_barbearia INTEGER,
            criado_por INTEGER NOT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
            expira_em TIMESTAMP NOT NULL,
            ativo BOOLEAN NOT NULL DEFAULT TRUE,
            ultimo_acesso_em TIMESTAMP,
            CONSTRAINT chk_links_agendamento_tipo
                CHECK (tipo IN ('barbeiro', 'barbearia'))
        );
    ";

    $conn->exec($sql);
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_links_agendamento_publico_expira ON links_agendamento_publico (expira_em);");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_links_agendamento_publico_ativo ON links_agendamento_publico (ativo);");
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Metodo nao permitido.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    ensureSecureLinkTable($conn);

    $token = isset($_GET['t']) ? trim((string)$_GET['t']) : '';
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Link invalido ou expirado.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tokenHash = hash('sha256', strtolower($token));

    $sql = "
        SELECT codigo, tipo, codigo_barbeiro, codigo_barbearia
        FROM links_agendamento_publico
        WHERE token_hash = :token_hash
          AND ativo = TRUE
          AND expira_em > NOW()
        ORDER BY codigo DESC
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':token_hash', $tokenHash);
    $stmt->execute();
    $link = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$link) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Link invalido ou expirado.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmtTouch = $conn->prepare("UPDATE links_agendamento_publico SET ultimo_acesso_em = NOW() WHERE codigo = :codigo");
    $stmtTouch->bindValue(':codigo', (int)$link['codigo'], PDO::PARAM_INT);
    $stmtTouch->execute();

    echo json_encode([
        'sucesso' => true,
        'tipo' => $link['tipo'],
        'cod_barbeiro' => isset($link['codigo_barbeiro']) ? (int)$link['codigo_barbeiro'] : null,
        'cod_barbearia' => isset($link['codigo_barbearia']) ? (int)$link['codigo_barbearia'] : null
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[resolver_link_agendamento_seguro] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro interno ao validar link.'
    ], JSON_UNESCAPED_UNICODE);
}
