<?php
/**
 * Cadastrar Novo Perfil
 * Sistema: MustaBarber - Painel Administrativo
 * Data: 29/01/2026
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

try {
    // Verificar se usuário está logado
    if (!estaLogado()) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usuário não autenticado'
        ]);
        exit;
    }

    // Verificar se é administrador
    $usuarioLogado = obterUsuarioLogado();
    if ($usuarioLogado['perfil'] != 1) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Acesso negado. Apenas administradores podem acessar esta funcionalidade.'
        ]);
        exit;
    }

    // Obter dados da requisição
    $dados = json_decode(file_get_contents('php://input'), true);

    if (!$dados) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Dados inválidos'
        ]);
        exit;
    }

    // Validar campos obrigatórios
    if (empty($dados['nome'])) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Nome do perfil é obrigatório'
        ]);
        exit;
    }

    // Verificar se perfil já existe
    $sqlCheck = "SELECT codigo FROM perfil WHERE nome = :nome";
    $stmtCheck = $conexao->prepare($sqlCheck);
    $stmtCheck->bindParam(':nome', $dados['nome']);
    $stmtCheck->execute();
    
    if ($stmtCheck->fetch()) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Já existe um perfil com este nome'
        ]);
        exit;
    }

    // Inserir novo perfil
    $sql = "INSERT INTO perfil (nome, descricao) 
            VALUES (:nome, :descricao)";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':nome', $dados['nome']);
    $stmt->bindParam(':descricao', $dados['descricao']);
    
    $stmt->execute();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Perfil criado com sucesso',
        'codigo' => $conexao->lastInsertId()
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro no banco de dados: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
