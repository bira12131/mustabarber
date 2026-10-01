<?php
/**
 * API de Exclus&atilde;o de Servi&ccedil;os
 * Sistema: MustaBarber
 * Data: 28/11/2025
 * 
 * Este arquivo processa a exclus&atilde;o de servi&ccedil;os do sistema
 */

header('Content-Type: application/json; charset=utf-8');

// Verifica se a requisi&ccedil;&atilde;o &eacute; do tipo POST ou DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'M&eacute;todo n&atilde;o permitido. Use POST ou DELETE.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Inclui o arquivo de conex&atilde;o
    require_once '../conexao.php';
    
    // Recebe o c&oacute;digo do servi&ccedil;o
    $codigo = isset($_POST['codigo']) ? intval($_POST['codigo']) : null;
    
    // Se for DELETE, tenta pegar do query string
    if (!$codigo && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $codigo = isset($_GET['codigo']) ? intval($_GET['codigo']) : null;
    }
    
    // Valida&ccedil;&atilde;o
    if (!$codigo) {
        throw new Exception('C&oacute;digo do servi&ccedil;o n&atilde;o informado.');
    }
    
    // Busca o servi&ccedil;o para obter o caminho da foto
    $stmt = $conexao->prepare("SELECT foto_caminho FROM servicos WHERE codigo = :codigo");
    $stmt->bindParam(':codigo', $codigo, PDO::PARAM_INT);
    $stmt->execute();
    
    $servico = $stmt->fetch();
    
    if (!$servico) {
        throw new Exception('Servi&ccedil;o n&atilde;o encontrado.');
    }
    
    // Exclui o servi&ccedil;o do banco de dados
    $stmt = $conexao->prepare("DELETE FROM servicos WHERE codigo = :codigo");
    $stmt->bindParam(':codigo', $codigo, PDO::PARAM_INT);
    $stmt->execute();
    
    // Remove a foto se existir
    if ($servico['foto_caminho'] && file_exists('../../' . $servico['foto_caminho'])) {
        unlink('../../' . $servico['foto_caminho']);
    }
    
    // Retorna sucesso
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Servi&ccedil;o exclu&iacute;do com sucesso!'
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
        'mensagem' => 'Erro ao excluir servi&ccedil;o. Tente novamente.',
        'erro_debug' => $e->getMessage() // Remover em produ&ccedil;&atilde;o
    ], JSON_UNESCAPED_UNICODE);
}
?>
