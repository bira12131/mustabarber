<?php
/**
 * API de Listagem de Servi&ccedil;os
 * Sistema: MustaBarber
 * Data: 28/11/2025
 * 
 * Este arquivo retorna a lista de servi&ccedil;os cadastrados no sistema
 */

header('Content-Type: application/json; charset=utf-8');

// Permite requisi&ccedil;&otilde;es GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'M&eacute;todo n&atilde;o permitido. Use GET.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Inclui o arquivo de conex&atilde;o
    require_once '../conexao.php';
    
    // Inclui o sistema de sess&atilde;o para obter usu&aacute;rio logado
    require_once '../usuarios/sessao.php';
    
    // Obt&eacute;m dados do usu&aacute;rio logado
    $usuarioLogado = obterUsuarioLogado();
    
    // Par&acirc;metros opcionais
    $codigo = isset($_GET['codigo']) ? intval($_GET['codigo']) : null;
    $codigoBarbeiro = isset($_GET['codigo_barbeiro']) ? intval($_GET['codigo_barbeiro']) : null;
    $status = isset($_GET['status']) ? intval($_GET['status']) : 1; // Padr&atilde;o: apenas ativos
    $busca = isset($_GET['busca']) ? trim($_GET['busca']) : null;
    
    // Se n&atilde;o foi passado c&oacute;digo de barbeiro, usa o da sess&atilde;o
    if (!$codigoBarbeiro && $usuarioLogado) {
        $codigoBarbeiro = intval($usuarioLogado['codigo']);
    }
    
    // Se foi informado um c&oacute;digo espec&iacute;fico, busca apenas esse servi&ccedil;o
    if ($codigo) {
        $sql = "SELECT s.codigo, s.nome, s.descricao, s.preco, s.duracao, s.foto_caminho,
                       s.codigo_barbeiro, s.status, s.data_cadastro, s.data_atualizacao,
                       u.nome as nome_barbeiro, u.foto_caminho as foto_barbeiro
                FROM servicos s
                LEFT JOIN usuarios u ON s.codigo_barbeiro = u.codigo
                WHERE s.codigo = :codigo";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bindParam(':codigo', $codigo, PDO::PARAM_INT);
        $stmt->execute();
        
        $servico = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($servico) {
            // Formata o pre&ccedil;o
            $servico['preco'] = floatval($servico['preco']);
            $servico['duracao'] = intval($servico['duracao']);
            
            echo json_encode([
                'sucesso' => true,
                'servico' => $servico
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Servi&ccedil;o n&atilde;o encontrado.'
            ], JSON_UNESCAPED_UNICODE);
        }
        
    } else {
        // Lista todos os servi&ccedil;os com filtros opcionais
        $sql = "SELECT s.codigo, s.nome, s.descricao, s.preco, s.duracao, s.foto_caminho,
                       s.codigo_barbeiro, s.status, s.data_cadastro, s.data_atualizacao,
                       u.nome as nome_barbeiro, u.foto_caminho as foto_barbeiro
                FROM servicos s
                LEFT JOIN usuarios u ON s.codigo_barbeiro = u.codigo
                WHERE 1=1";
        
        $params = [];
        
        // Filtro por status
        if ($status !== null) {
            $sql .= " AND s.status = :status";
            $params[':status'] = $status;
        }
        
        // Filtro por barbeiro
        if ($codigoBarbeiro) {
            $sql .= " AND s.codigo_barbeiro = :codigo_barbeiro";
            $params[':codigo_barbeiro'] = $codigoBarbeiro;
        } else {
            // Se n&atilde;o tem filtro de barbeiro, n&atilde;o mostra servi&ccedil;os sem barbeiro (NULL)
            $sql .= " AND s.codigo_barbeiro IS NOT NULL";
        }
        
        // Filtro por busca no nome ou descri&ccedil;&atilde;o
        if ($busca) {
            $sql .= " AND (LOWER(s.nome) LIKE LOWER(:busca) OR LOWER(s.descricao) LIKE LOWER(:busca))";
            $params[':busca'] = '%' . $busca . '%';
        }
        
        // Ordena&ccedil;&atilde;o
        $sql .= " ORDER BY s.nome ASC";
        
        $stmt = $conexao->prepare($sql);
        
        // Bind dos par&acirc;metros
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        
        $servicos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Formata os dados
        foreach ($servicos as &$servico) {
            $servico['preco'] = floatval($servico['preco']);
            $servico['duracao'] = intval($servico['duracao']);
        }
        
        echo json_encode([
            'sucesso' => true,
            'total' => count($servicos),
            'servicos' => $servicos
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (PDOException $e) {
    // Erro de banco de dados
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao buscar servi&ccedil;os. Tente novamente.',
        'erro_debug' => $e->getMessage() // Remover em produ&ccedil;&atilde;o
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Outros erros
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
