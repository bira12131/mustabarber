<?php
/**
 * Script para buscar categorias do banco de dados
 * Sistema: MustaBarber - Nexus Gerência
 * Data: 29/11/2025
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Incluir arquivo de conexão
require_once '../conexao.php';
require_once '../usuarios/sessao.php';
require_once '../planos/plano_manager.php';

try {
    // Verificar se usuário está logado
    if (!estaLogado()) {
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado']);
        exit;
    }

    // Verificar acesso por plano (financeiro exige Solo Pro ou superior)
    $usuarioLogado = obterUsuarioLogado();
    exigirPlanoOuBloquear($conexao, (int)$usuarioLogado['codigo'], (int)$usuarioLogado['perfil'], 'financeiro');

    // Receber tipo de transação (1 = Receita, 2 = Despesa)
    $tipoTransacao = isset($_GET['tipo']) ? intval($_GET['tipo']) : 2;
    
    // Validar tipo de transação
    if ($tipoTransacao !== 1 && $tipoTransacao !== 2) {
        $tipoTransacao = 2; // Padrão para despesa
    }
    
    // Buscar categorias pelo tipo de transação
    $sql = "SELECT codigo, descricao 
            FROM categoria 
            WHERE tipo_transacao = :tipo_transacao 
            ORDER BY descricao ASC";
    
    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':tipo_transacao', $tipoTransacao, PDO::PARAM_INT);
    $stmt->execute();
    
    $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'sucesso' => true,
        'categorias' => $categorias
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao buscar categorias: ' . $e->getMessage(),
        'categorias' => []
    ]);
}
?>
