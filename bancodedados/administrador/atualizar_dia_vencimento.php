<?php
/**
 * Atualizar Dia de Vencimento do Barbeiro
 * Sistema: MustaBarber - Painel Administrativo
 * Data: 06/05/2026
 * Altera o dia do mês preferido para renovação da mensalidade (campo dia_vencimento na tabela usuarios)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

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

    $codigo_barbeiro = isset($dados['codigo_barbeiro']) ? intval($dados['codigo_barbeiro']) : 0;
    $dia_vencimento  = isset($dados['dia_vencimento'])  ? intval($dados['dia_vencimento'])  : 0;

    if ($codigo_barbeiro <= 0) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Código do barbeiro é obrigatório.']);
        exit;
    }

    if ($dia_vencimento < 1 || $dia_vencimento > 31) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'O dia de vencimento deve ser entre 1 e 31.']);
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

    $stmt = $conexao->prepare(
        "UPDATE usuarios SET dia_vencimento = :dia WHERE codigo = :cod"
    );
    $stmt->bindParam(':dia', $dia_vencimento, PDO::PARAM_INT);
    $stmt->bindParam(':cod', $codigo_barbeiro, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'sucesso'  => true,
        'mensagem' => "Dia de vencimento atualizado para dia {$dia_vencimento} com sucesso."
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro no banco de dados: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
