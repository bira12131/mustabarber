<?php
/**
 * Atualizar Estoque de Produto
 * Atualiza a quantidade em estoque de um produto específico
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

// Incluir conexão com banco
require_once '../conexao.php';
require_once '../usuarios/sessao.php';
require_once '../planos/plano_manager.php';

// Verificar se é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Método não permitido'
    ]);
    exit;
}

try {
    // Verificar autenticação
    if (!estaLogado()) {
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado']);
        exit;
    }

    // Verificar acesso por plano (produtos/estoque exige Solo Pro ou superior)
    $usuarioLogado = obterUsuarioLogado();
    exigirPlanoOuBloquear($conexao, (int)$usuarioLogado['codigo'], (int)$usuarioLogado['perfil'], 'produtos');

    // Receber dados
    $codigo = isset($_POST['codigo']) ? intval($_POST['codigo']) : 0;
    $quantidade_estoque = isset($_POST['quantidade_estoque']) ? intval($_POST['quantidade_estoque']) : 0;

    // Validar dados
    if ($codigo <= 0) {
        throw new Exception('Código do produto inválido');
    }

    if ($quantidade_estoque < 0) {
        throw new Exception('Quantidade em estoque não pode ser negativa');
    }

    // Verificar se produto existe
    $stmt = $conn->prepare("SELECT codigo FROM produtos WHERE codigo = :codigo");
    $stmt->bindParam(':codigo', $codigo, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        throw new Exception('Produto não encontrado');
    }

    // Atualizar quantidade em estoque
    $stmt = $conn->prepare("UPDATE produtos SET quantidade_estoque = :quantidade WHERE codigo = :codigo");
    $stmt->bindParam(':quantidade', $quantidade_estoque, PDO::PARAM_INT);
    $stmt->bindParam(':codigo', $codigo, PDO::PARAM_INT);
    
    if (!$stmt->execute()) {
        throw new Exception('Erro ao atualizar estoque');
    }

    // Retornar sucesso
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Estoque atualizado com sucesso',
        'novo_estoque' => $quantidade_estoque
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

// PDO não precisa fechar conexão explicitamente
?>
