<?php
/**
 * Cadastrar Nova Permissão
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
    if (empty($dados['codigo_perfil']) || empty($dados['nome']) || empty($dados['modulo']) || empty($dados['acao'])) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Campos obrigatórios não preenchidos'
        ]);
        exit;
    }

    // Inserir nova permissão
    $sql = "INSERT INTO permissao (codigo_perfil, nome, modulo, acao, descricao) 
            VALUES (:codigo_perfil, :nome, :modulo, :acao, :descricao)";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':codigo_perfil', $dados['codigo_perfil'], PDO::PARAM_INT);
    $stmt->bindParam(':nome', $dados['nome']);
    $stmt->bindParam(':modulo', $dados['modulo']);
    $stmt->bindParam(':acao', $dados['acao']);
    $stmt->bindParam(':descricao', $dados['descricao']);
    
    $stmt->execute();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Permissão cadastrada com sucesso',
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
