<?php
/**
 * API de Exclus&#65533;o de Produtos
 * Sistema: MustaBarber
 * Data: 29/11/2025
 * 
 * Este arquivo processa a exclus&#65533;o de produtos do sistema
 */

// Inicia a sess&#65533;o
session_start();

header('Content-Type: application/json; charset=utf-8');

// Verifica se o usu&#65533;rio est&#65533; autenticado
if (!isset($_SESSION['usuario_codigo'])) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Usu&#65533;rio n&#65533;o autenticado.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Verifica se a requisi&#65533;&#65533;o &#65533; do tipo POST ou DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'M&#65533;todo n&#65533;o permitido. Use POST ou DELETE.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Inclui o arquivo de conexão
    require_once '../conexao.php';
    require_once '../planos/plano_manager.php';

    // Verificar acesso por plano (produtos/estoque exige Solo Pro ou superior)
    exigirPlanoOuBloquear($conexao, (int)$_SESSION['usuario_codigo'], (int)($_SESSION['usuario_perfil'] ?? 0), 'produtos');

    // Recebe o código do produto
    $codigo = isset($_POST['codigo']) ? intval($_POST['codigo']) : null;
    
    // Se for DELETE, tenta pegar do query string
    if (!$codigo && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $codigo = isset($_GET['codigo']) ? intval($_GET['codigo']) : null;
    }
    
    // Valida&#65533;&#65533;o
    if (!$codigo) {
        throw new Exception('C&#65533;digo do produto n&#65533;o informado.');
    }
    
    // Busca o produto para obter o caminho da foto e verificar se pertence ao barbeiro
    $stmt = $conexao->prepare("SELECT foto_caminho FROM produtos WHERE codigo = :codigo AND codigo_barbeiro = :codigo_barbeiro");
    $stmt->bindParam(':codigo', $codigo, PDO::PARAM_INT);
    $stmt->bindParam(':codigo_barbeiro', $_SESSION['usuario_codigo'], PDO::PARAM_INT);
    $stmt->execute();
    
    $produto = $stmt->fetch();
    
    if (!$produto) {
        throw new Exception('Produto n&#65533;o encontrado ou voc&#65533; n&#65533;o tem permiss&#65533;o para exclu&#65533;-lo.');
    }
    
    // Exclui o produto do banco de dados
    $stmt = $conexao->prepare("DELETE FROM produtos WHERE codigo = :codigo AND codigo_barbeiro = :codigo_barbeiro");
    $stmt->bindParam(':codigo', $codigo, PDO::PARAM_INT);
    $stmt->bindParam(':codigo_barbeiro', $_SESSION['usuario_codigo'], PDO::PARAM_INT);
    $stmt->execute();
    
    // Remove a foto se existir
    if ($produto['foto_caminho'] && file_exists('../../' . $produto['foto_caminho'])) {
        unlink('../../' . $produto['foto_caminho']);
    }
    
    // Retorna sucesso
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Produto exclu&#65533;do com sucesso!'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Em caso de erro, retorna mensagem
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // Erro de banco de dados
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao excluir produto. Tente novamente.',
        'erro_debug' => $e->getMessage() // Remover em produ&#65533;&#65533;o
    ], JSON_UNESCAPED_UNICODE);
}
?>
