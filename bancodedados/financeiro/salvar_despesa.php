<?php
/**
 * Script para salvar despesas no banco de dados
 * Sistema: MustaBarber - Nexus Gerência
 * Data: 29/11/2025
 */

header('Content-Type: application/json; charset=utf-8');

// Incluir arquivo de conexão
require_once '../conexao.php';
require_once '../usuarios/sessao.php';

// Verificar se é uma requisição POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Método não permitido'
    ]);
    exit;
}

try {
    // Verificar se usuário está logado
    if (!estaLogado()) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usuário não autenticado'
        ]);
        exit;
    }

    // Obter código do usuário logado
    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = $usuarioLogado['codigo'];

    // Verificar acesso por plano (financeiro exige Solo Pro ou superior)
    require_once '../planos/plano_manager.php';
    exigirPlanoOuBloquear($conexao, (int)$codigoUsuario, (int)$usuarioLogado['perfil'], 'financeiro');

    // Obter dados JSON
    $json = file_get_contents('php://input');
    $dados = json_decode($json, true);

    // Validar dados obrigatórios
    if (empty($dados['data']) || empty($dados['valor']) || empty($dados['descricao']) || 
        empty($dados['categoria']) || empty($dados['formaPagamento'])) {
        throw new Exception('Todos os campos obrigatórios devem ser preenchidos');
    }

    // Validar valor
    if (!is_numeric($dados['valor']) || $dados['valor'] <= 0) {
        throw new Exception('Valor inválido');
    }

    // Preparar dados para inserção
    $tipo_transacao = 2; // 2 = Despesa
    $descricao = trim($dados['descricao']);
    $valor = floatval($dados['valor']);
    $data_transacao = $dados['data'];
    $categoria = intval($dados['categoria']);
    $forma_pagamento = intval($dados['formaPagamento']);
    $fornecedor = isset($dados['fornecedor']) ? trim($dados['fornecedor']) : null;
    $status_pagamento = isset($dados['status']) ? intval($dados['status']) : 1; // 1 = Pendente, 2 = Pago
    $data_vencimento = isset($dados['dataVencimento']) && !empty($dados['dataVencimento']) ? $dados['dataVencimento'] : null;

    // Preparar query de inserção
    $sql = "INSERT INTO transacoes (
                tipo_transacao, 
                descricao, 
                valor, 
                data_transacao, 
                data_vencimento,
                forma_pagamento, 
                categoria, 
                fornecedor, 
                status_pagamento,
                codigo_barbeiro
            ) VALUES (
                :tipo_transacao,
                :descricao,
                :valor,
                :data_transacao,
                :data_vencimento,
                :forma_pagamento,
                :categoria,
                :fornecedor,
                :status_pagamento,
                :codigo_barbeiro
            )";

    $stmt = $conexao->prepare($sql);
    
    // Bind dos parâmetros
    $stmt->bindParam(':tipo_transacao', $tipo_transacao, PDO::PARAM_INT);
    $stmt->bindParam(':descricao', $descricao, PDO::PARAM_STR);
    $stmt->bindParam(':valor', $valor, PDO::PARAM_STR);
    $stmt->bindParam(':data_transacao', $data_transacao, PDO::PARAM_STR);
    $stmt->bindParam(':data_vencimento', $data_vencimento, PDO::PARAM_STR);
    $stmt->bindParam(':forma_pagamento', $forma_pagamento, PDO::PARAM_INT);
    $stmt->bindParam(':categoria', $categoria, PDO::PARAM_INT);
    $stmt->bindParam(':fornecedor', $fornecedor, PDO::PARAM_STR);
    $stmt->bindParam(':status_pagamento', $status_pagamento, PDO::PARAM_INT);
    $stmt->bindParam(':codigo_barbeiro', $codigoUsuario, PDO::PARAM_INT);

    // Executar query
    if ($stmt->execute()) {
        $codigo = $conexao->lastInsertId();
        
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Despesa registrada com sucesso!',
            'codigo' => $codigo
        ]);
    } else {
        throw new Exception('Erro ao salvar despesa no banco de dados');
    }

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro no banco de dados: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ]);
}
?>
