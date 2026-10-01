<?php
/**
 * Salvar comissoes por funcionario da barbearia.
 */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../usuarios/sessao.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Metodo nao permitido']);
    exit;
}

try {
    require_once '../conexao.php';

    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuario nao autenticado']);
        exit;
    }

    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = (int)$usuarioLogado['codigo'];

    $json = file_get_contents('php://input');
    $payload = json_decode($json, true);

    if (!is_array($payload) || !isset($payload['comissoes']) || !is_array($payload['comissoes'])) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Payload invalido']);
        exit;
    }

    $stmtBarb = $conexao->prepare(
        "SELECT codigo FROM barbearia WHERE codigo_usuario = :codigo_usuario LIMIT 1"
    );
    $stmtBarb->bindValue(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    $stmtBarb->execute();
    $barbearia = $stmtBarb->fetch(PDO::FETCH_ASSOC);

    if (!$barbearia) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Somente o dono da barbearia pode editar comissoes.']);
        exit;
    }

    $codigoBarbearia = (int)$barbearia['codigo'];

    $conexao->exec(
        "CREATE TABLE IF NOT EXISTS comissoes_funcionarios (
            codigo SERIAL PRIMARY KEY,
            codigo_barbearia INTEGER NOT NULL,
            codigo_funcionario INTEGER NOT NULL,
            percentual_servicos NUMERIC(5,2) NOT NULL DEFAULT 0,
            percentual_produtos NUMERIC(5,2) NOT NULL DEFAULT 0,
            data_atualizacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(codigo_barbearia, codigo_funcionario)
        )"
    );

    $stmtFuncionarioValido = $conexao->prepare(
        "SELECT 1
         FROM usuarios u
         WHERE u.codigo = :codigo_funcionario
           AND u.codigo_barbearia = :codigo_barbearia
                     AND u.status = 1
         LIMIT 1"
    );

    $stmtUpsert = $conexao->prepare(
        "INSERT INTO comissoes_funcionarios (
            codigo_barbearia,
            codigo_funcionario,
            percentual_servicos,
            percentual_produtos,
            data_atualizacao
         ) VALUES (
            :codigo_barbearia,
            :codigo_funcionario,
            :percentual_servicos,
            :percentual_produtos,
            CURRENT_TIMESTAMP
         )
         ON CONFLICT (codigo_barbearia, codigo_funcionario)
         DO UPDATE SET
            percentual_servicos = EXCLUDED.percentual_servicos,
            percentual_produtos = EXCLUDED.percentual_produtos,
            data_atualizacao = CURRENT_TIMESTAMP"
    );

    $conexao->beginTransaction();

    $salvos = 0;
    foreach ($payload['comissoes'] as $item) {
        $codigoFuncionario = isset($item['codigo_funcionario']) ? (int)$item['codigo_funcionario'] : 0;
        $percentualServicos = isset($item['percentual_servicos']) ? (float)$item['percentual_servicos'] : 0;
        $percentualProdutos = isset($item['percentual_produtos']) ? (float)$item['percentual_produtos'] : 0;

        if ($codigoFuncionario <= 0) {
            continue;
        }

        if ($percentualServicos < 0 || $percentualServicos > 100 || $percentualProdutos < 0 || $percentualProdutos > 100) {
            $conexao->rollBack();
            echo json_encode(['sucesso' => false, 'mensagem' => 'Percentuais devem estar entre 0 e 100.']);
            exit;
        }

        $stmtFuncionarioValido->bindValue(':codigo_funcionario', $codigoFuncionario, PDO::PARAM_INT);
        $stmtFuncionarioValido->bindValue(':codigo_barbearia', $codigoBarbearia, PDO::PARAM_INT);
        $stmtFuncionarioValido->execute();

        if (!$stmtFuncionarioValido->fetchColumn()) {
            continue;
        }

        $stmtUpsert->bindValue(':codigo_barbearia', $codigoBarbearia, PDO::PARAM_INT);
        $stmtUpsert->bindValue(':codigo_funcionario', $codigoFuncionario, PDO::PARAM_INT);
        $stmtUpsert->bindValue(':percentual_servicos', $percentualServicos);
        $stmtUpsert->bindValue(':percentual_produtos', $percentualProdutos);
        $stmtUpsert->execute();
        $salvos++;
    }

    $conexao->commit();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Comissoes atualizadas com sucesso.',
        'itens_salvos' => $salvos
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($conexao) && $conexao->inTransaction()) {
        $conexao->rollBack();
    }

    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Nao foi possivel salvar as comissoes.'
    ], JSON_UNESCAPED_UNICODE);
}
?>