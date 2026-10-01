<?php
/**
 * API de Listagem de Produtos
 * Sistema: MustaBarber
 * Data: 29/11/2025
 * 
 * Este arquivo retorna a lista de produtos cadastrados no sistema
 */

header('Content-Type: application/json; charset=utf-8');

// Inicia a sess�o
session_start();

// Verifica se o usu�rio est� autenticado
if (!isset($_SESSION['usuario_codigo'])) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Usu&aacute;rio n&atilde;o autenticado'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$codigo_barbeiro = $_SESSION['usuario_codigo'];

// Permite requisi&#65533;&#65533;es GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'M&#65533;todo n&#65533;o permitido. Use GET.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Inclui o arquivo de conexão
    require_once '../conexao.php';
    require_once '../planos/plano_manager.php';

    // Verificar acesso por plano (produtos/estoque exige Solo Pro ou superior)
    exigirPlanoOuBloquear($conexao, (int)$_SESSION['usuario_codigo'], (int)($_SESSION['usuario_perfil'] ?? 0), 'produtos');

    // Parâmetros opcionais
    $codigo = isset($_GET['codigo']) ? intval($_GET['codigo']) : null;
    $status = isset($_GET['status']) ? intval($_GET['status']) : null;
    $busca = isset($_GET['busca']) ? trim($_GET['busca']) : null;
    
    // Se foi informado um c&#65533;digo espec&#65533;fico, busca apenas esse produto
    if ($codigo) {
        $sql = "SELECT codigo, nome, marca, preco, quantidade_estoque, foto_caminho,
                       status, data_cadastro, data_atualizacao
                FROM produtos
                WHERE codigo = :codigo AND codigo_barbeiro = :codigo_barbeiro";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bindParam(':codigo', $codigo, PDO::PARAM_INT);
        $stmt->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
        $stmt->execute();
        
        $produto = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($produto) {
            // Formata o pre&#65533;o
            $produto['preco'] = floatval($produto['preco']);
            $produto['quantidade_estoque'] = intval($produto['quantidade_estoque']);
            
            echo json_encode([
                'sucesso' => true,
                'produtos' => [$produto],
                'total' => 1
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Produto n&#65533;o encontrado',
                'produtos' => []
            ], JSON_UNESCAPED_UNICODE);
        }
    } else {
        // Busca todos os produtos com filtros opcionais
        $sql = "SELECT codigo, nome, marca, preco, quantidade_estoque, foto_caminho,
                       status, data_cadastro, data_atualizacao
                FROM produtos
                WHERE codigo_barbeiro = :codigo_barbeiro";
        
        $parametros = [':codigo_barbeiro' => $codigo_barbeiro];
        
        // Filtro por status
        if ($status !== null) {
            $sql .= " AND status = :status";
            $parametros[':status'] = $status;
        }
        
        // Filtro por busca (nome ou marca)
        if ($busca) {
            $sql .= " AND (nome ILIKE :busca OR marca ILIKE :busca)";
            $parametros[':busca'] = '%' . $busca . '%';
        }
        
        $sql .= " ORDER BY nome ASC";
        
        $stmt = $conexao->prepare($sql);
        
        // Bind dos par&#65533;metros
        foreach ($parametros as $key => $valor) {
            $stmt->bindValue($key, $valor);
        }
        
        $stmt->execute();
        $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Formata os pre&#65533;os e quantidades
        foreach ($produtos as &$produto) {
            $produto['preco'] = floatval($produto['preco']);
            $produto['quantidade_estoque'] = intval($produto['quantidade_estoque']);
        }
        
        echo json_encode([
            'sucesso' => true,
            'produtos' => $produtos,
            'total' => count($produtos)
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro no banco de dados: ' . $e->getMessage(),
        'produtos' => []
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage(),
        'produtos' => []
    ], JSON_UNESCAPED_UNICODE);
}
?>
