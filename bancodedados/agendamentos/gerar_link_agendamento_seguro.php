<?php
/**
 * Gera link publico de agendamento usando token aleatorio.
 * O token evita expor codigos de barbeiro/barbearia na URL.
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

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

function getOriginBaseUrl(): string {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!empty($origin)) {
        return rtrim($origin, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}

function getAppBasePath(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $basePath = preg_replace('#/bancodedados/agendamentos/[^/]+$#', '', $script);

    if (!is_string($basePath) || $basePath === null) {
        return '';
    }

    return rtrim($basePath, '/');
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Metodo nao permitido.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!estaLogado()) {
        http_response_code(401);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usuario nao autenticado.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    ensureSecureLinkTable($conn);

    $payload = json_decode(file_get_contents('php://input'), true);
    $tipo = '';
    if (is_array($payload) && isset($payload['tipo'])) {
        $tipo = (string)$payload['tipo'];
    } elseif (isset($_POST['tipo'])) {
        $tipo = (string)$_POST['tipo'];
    }

    if ($tipo !== 'barbeiro' && $tipo !== 'barbearia') {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Tipo de link invalido.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario = obterUsuarioLogado();
    $codigoUsuario = (int)($usuario['codigo'] ?? 0);

    if ($codigoUsuario <= 0) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usuario invalido.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $codigoBarbeiro = null;
    $codigoBarbearia = null;

    if ($tipo === 'barbeiro') {
        $codigoBarbeiro = $codigoUsuario;
    } else {
        $stmtBarbearia = $conn->prepare(
            "SELECT codigo FROM barbearia WHERE codigo_usuario = :codigo_usuario LIMIT 1"
        );
        $stmtBarbearia->bindValue(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
        $stmtBarbearia->execute();
        $row = $stmtBarbearia->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['codigo'])) {
            $codigoBarbearia = (int)$row['codigo'];
        }

        if (!$codigoBarbearia) {
            // Compatibilidade para conta de funcionario com coluna opcional.
            try {
                $stmtFunc = $conn->prepare(
                    "SELECT codigo_barbearia FROM usuarios WHERE codigo = :codigo_usuario AND codigo_barbearia IS NOT NULL LIMIT 1"
                );
                $stmtFunc->bindValue(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
                $stmtFunc->execute();
                $funcRow = $stmtFunc->fetch(PDO::FETCH_ASSOC);
                if ($funcRow && isset($funcRow['codigo_barbearia'])) {
                    $codigoBarbearia = (int)$funcRow['codigo_barbearia'];
                }
            } catch (Throwable $ignored) {
                // Ignorar em bases antigas sem a coluna.
            }
        }

        if (!$codigoBarbearia) {
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Nenhuma barbearia vinculada para gerar este link.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiraEm = (new DateTime('+180 days'))->format('Y-m-d H:i:s');

    $sqlInsert = "
        INSERT INTO links_agendamento_publico
            (token_hash, tipo, codigo_barbeiro, codigo_barbearia, criado_por, expira_em, ativo)
        VALUES
            (:token_hash, :tipo, :codigo_barbeiro, :codigo_barbearia, :criado_por, :expira_em, TRUE)
    ";
    $stmtInsert = $conn->prepare($sqlInsert);
    $stmtInsert->bindValue(':token_hash', $tokenHash);
    $stmtInsert->bindValue(':tipo', $tipo);
    if ($codigoBarbeiro !== null) {
        $stmtInsert->bindValue(':codigo_barbeiro', $codigoBarbeiro, PDO::PARAM_INT);
    } else {
        $stmtInsert->bindValue(':codigo_barbeiro', null, PDO::PARAM_NULL);
    }
    if ($codigoBarbearia !== null) {
        $stmtInsert->bindValue(':codigo_barbearia', $codigoBarbearia, PDO::PARAM_INT);
    } else {
        $stmtInsert->bindValue(':codigo_barbearia', null, PDO::PARAM_NULL);
    }
    $stmtInsert->bindValue(':criado_por', $codigoUsuario, PDO::PARAM_INT);
    $stmtInsert->bindValue(':expira_em', $expiraEm);
    $stmtInsert->execute();

    $originBase = getOriginBaseUrl();
    $appBasePath = getAppBasePath();
    $linkPath = $appBasePath . '/paginas/agendamentoclientes/agendar.html?t=' . urlencode($token);
    $link = $originBase . $linkPath;

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Link seguro gerado com sucesso.',
        'tipo' => $tipo,
        'expira_em' => $expiraEm,
        'link' => $link
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[gerar_link_agendamento_seguro] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro interno ao gerar link seguro.'
    ], JSON_UNESCAPED_UNICODE);
}
