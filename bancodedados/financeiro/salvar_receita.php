<?php
/**
 * Script para salvar receita no banco de dados
 * Sistema: MustaBarber - Nexus Gerência
 * Data: 30/11/2025
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Tratar requisição OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Incluir arquivo de conexão
require_once '../conexao.php';
require_once '../usuarios/sessao.php';

// Verificar se é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
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

    // Receber dados JSON
    $json = file_get_contents('php://input');
    $dados = json_decode($json, true);

    // Validar se JSON foi decodificado
    if ($dados === null) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro ao processar dados enviados'
        ]);
        exit;
    }

    // Validar dados obrigatórios
    if (empty($dados['descricao'])) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Descrição é obrigatória']);
        exit;
    }
    if (empty($dados['valor']) || $dados['valor'] <= 0) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Valor deve ser maior que zero']);
        exit;
    }
    if (empty($dados['data_transacao'])) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Data da transação é obrigatória']);
        exit;
    }
    if (empty($dados['categoria'])) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Categoria é obrigatória']);
        exit;
    }
    if (empty($dados['forma_pagamento'])) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Forma de pagamento é obrigatória']);
        exit;
    }

    // Mapear forma de pagamento para código
    $formasPagamento = [
        'dinheiro' => 1,
        'cartao-debito' => 2,
        'cartao-credito' => 3,
        'pix' => 4,
        'transferencia' => 5
    ];

    $formaPagamentoCodigo = $formasPagamento[$dados['forma_pagamento']] ?? 1;

    // Preparar valores
    $descricao = trim($dados['descricao']);
    $valor = floatval($dados['valor']);
    $dataTransacao = $dados['data_transacao'];
    $categoria = intval($dados['categoria']);
    $codigoBarbeiro = $codigoUsuario; // Usuário logado
    $observacoes = isset($dados['observacoes']) ? trim($dados['observacoes']) : null;

    // Preparar SQL para inserção
    $sql = "INSERT INTO transacoes (
                tipo_transacao, 
                descricao, 
                valor, 
                data_transacao, 
                forma_pagamento, 
                categoria, 
                codigo_barbeiro,
                status_pagamento,
                observacoes
            ) VALUES (
                1, 
                :descricao, 
                :valor, 
                :data_transacao, 
                :forma_pagamento, 
                :categoria,
                :codigo_barbeiro,
                2,
                :observacoes
            ) RETURNING codigo";

    $stmt = $conexao->prepare($sql);
    
    // Bind dos parâmetros
    $stmt->bindValue(':descricao', $descricao, PDO::PARAM_STR);
    $stmt->bindValue(':valor', $valor, PDO::PARAM_STR);
    $stmt->bindValue(':data_transacao', $dataTransacao, PDO::PARAM_STR);
    $stmt->bindValue(':forma_pagamento', $formaPagamentoCodigo, PDO::PARAM_INT);
    $stmt->bindValue(':categoria', $categoria, PDO::PARAM_INT);
    $stmt->bindValue(':codigo_barbeiro', $codigoBarbeiro, PDO::PARAM_INT);
    $stmt->bindValue(':observacoes', $observacoes, PDO::PARAM_STR);

    // Executar inserção
    if ($stmt->execute()) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Receita registrada com sucesso!',
            'codigo' => $row['codigo']
        ]);
    } else {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro ao executar inserção no banco de dados'
        ]);
    }

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao salvar receita no banco de dados'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro inesperado ao processar requisição'
    ]);
}
?>
