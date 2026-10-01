<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

include_once '../conexao.php';
require_once '../usuarios/sessao.php';
require_once '../planos/plano_manager.php';

try {
    // Verificar autenticação
    if (!estaLogado()) {
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado']);
        exit;
    }

    // Verificar acesso por plano (financeiro exige Solo Pro ou superior)
    $usuarioLogado = obterUsuarioLogado();
    exigirPlanoOuBloquear($conexao, (int)$usuarioLogado['codigo'], (int)$usuarioLogado['perfil'], 'financeiro');

    // Receber dados da requisição
    $dados = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($dados['codigo'])) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Código da transação não informado'
        ]);
        exit;
    }
    
    $codigo = $dados['codigo'];
    
    // Buscar a transação antes de excluir para saber o tipo
    $sqlBuscar = "SELECT tipo_transacao FROM transacoes WHERE codigo = :codigo";
    $stmtBuscar = $conexao->prepare($sqlBuscar);
    $stmtBuscar->bindParam(':codigo', $codigo, PDO::PARAM_INT);
    $stmtBuscar->execute();
    
    if ($stmtBuscar->rowCount() == 0) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Transação não encontrada'
        ]);
        exit;
    }
    
    $transacao = $stmtBuscar->fetch(PDO::FETCH_ASSOC);
    
    // Excluir a transação
    $sql = "DELETE FROM transacoes WHERE codigo = :codigo";
    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':codigo', $codigo, PDO::PARAM_INT);
    
    if ($stmt->execute()) {
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Transação excluída com sucesso',
            'tipo_transacao' => $transacao['tipo_transacao']
        ]);
    } else {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro ao excluir transação'
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao excluir: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro: ' . $e->getMessage()
    ]);
}
?>
