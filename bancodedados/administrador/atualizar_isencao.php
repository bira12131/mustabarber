<?php
/**
 * Atualizar Isenção de Pagamento do Barbeiro
 * Sistema: MustaBarber - Painel Administrativo
 * Data: 06/05/2026
 * Permite ao administrador conceder ou remover isenção de mensalidade manualmente.
 */

ini_set('display_errors', 0);
error_reporting(0);
ob_start();
session_start();
ob_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado']);
        exit;
    }

    $usuarioLogado = obterUsuarioLogado();
    if ($usuarioLogado['perfil'] != 1) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Acesso negado. Apenas administradores.']);
        exit;
    }

    $dados = json_decode(file_get_contents('php://input'), true);

    $codigo_barbeiro   = isset($dados['codigo_barbeiro']) ? intval($dados['codigo_barbeiro']) : 0;
    $isento_pagamento  = isset($dados['isento_pagamento']) ? (bool)$dados['isento_pagamento'] : null;

    if ($codigo_barbeiro <= 0) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Código do barbeiro é obrigatório.']);
        exit;
    }

    if ($isento_pagamento === null) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Informe o valor de isento_pagamento (true/false).']);
        exit;
    }

    // Verificar se barbeiro existe
    $stmtCheck = $conexao->prepare(
        "SELECT codigo FROM usuarios WHERE codigo = :cod AND perfil IN (2, 3, 4)"
    );
    $stmtCheck->bindParam(':cod', $codigo_barbeiro, PDO::PARAM_INT);
    $stmtCheck->execute();
    if (!$stmtCheck->fetch()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Barbeiro não encontrado.']);
        exit;
    }

    $isento_int = $isento_pagamento ? 1 : 0;
    $stmt = $conexao->prepare(
        "UPDATE usuarios SET isento_pagamento = :isento WHERE codigo = :cod"
    );
    $stmt->bindParam(':isento', $isento_int, PDO::PARAM_BOOL);
    $stmt->bindParam(':cod', $codigo_barbeiro, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Nenhum registro foi atualizado. Verifique os dados.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $acao = $isento_pagamento ? 'concedida' : 'removida';
    echo json_encode([
        'sucesso'  => true,
        'mensagem' => "Isenção de pagamento {$acao} com sucesso.",
        'isento'   => $isento_pagamento
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro no banco de dados: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
