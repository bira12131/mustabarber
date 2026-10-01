<?php
/**
 * Atualizar Assinatura
 * Sistema: MustaBarber - Painel Administrativo
 * Data: 30/01/2026
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

// Incluir arquivo de conexão
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

    // Verificar se é administrador (perfil 1)
    $usuarioLogado = obterUsuarioLogado();
    if ($usuarioLogado['perfil'] != 1) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Acesso negado. Apenas administradores podem acessar esta funcionalidade.'
        ]);
        exit;
    }

    // Receber dados
    $dados = json_decode(file_get_contents('php://input'), true);

    $codigo_barbeiro = $dados['codigo_barbeiro'] ?? null;
    $data_inicio = $dados['data_inicio'] ?? null;
    $data_vencimento = $dados['data_vencimento'] ?? null;
    $valor_mensalidade = $dados['valor_mensalidade'] ?? null;
    $status_pagamento = $dados['status_pagamento'] ?? 1;
    $forma_pagamento = $dados['forma_pagamento'] ?? null;
    $observacoes = $dados['observacoes'] ?? null;

    // Validações
    if (!$codigo_barbeiro) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Código do barbeiro é obrigatório'
        ]);
        exit;
    }

    if (!$data_vencimento) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Data de vencimento é obrigatória'
        ]);
        exit;
    }

    // Verificar se barbeiro existe
    $sqlBarbeiro = "SELECT codigo, perfil FROM usuarios WHERE codigo = :codigo AND perfil IN (2, 3, 4)";
    $stmtBarbeiro = $conexao->prepare($sqlBarbeiro);
    $stmtBarbeiro->bindParam(':codigo', $codigo_barbeiro, PDO::PARAM_INT);
    $stmtBarbeiro->execute();
    $barbeiro = $stmtBarbeiro->fetch(PDO::FETCH_ASSOC);

    if (!$barbeiro) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Barbeiro não encontrado'
        ]);
        exit;
    }

    // Verificar se já existe assinatura para este barbeiro
    $sqlVerifica = "SELECT codigo FROM assinaturas WHERE codigo_barbeiro = :codigo_barbeiro";
    $stmtVerifica = $conexao->prepare($sqlVerifica);
    $stmtVerifica->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
    $stmtVerifica->execute();
    $assinaturaExiste = $stmtVerifica->fetch(PDO::FETCH_ASSOC);

    // Atualizar status baseado na data de vencimento
    $hoje = date('Y-m-d');
    if ($status_pagamento == 1 && $data_vencimento < $hoje) {
        $status_pagamento = 3; // Vencido
    }

    if ($assinaturaExiste) {
        // Atualizar assinatura existente
        $sql = "UPDATE assinaturas SET 
                    data_inicio = COALESCE(:data_inicio, data_inicio),
                    data_vencimento = :data_vencimento,
                    valor_mensalidade = COALESCE(:valor_mensalidade, valor_mensalidade),
                    status_pagamento = :status_pagamento,
                    forma_pagamento = :forma_pagamento,
                    observacoes = :observacoes
                WHERE codigo_barbeiro = :codigo_barbeiro";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bindParam(':data_inicio', $data_inicio);
        $stmt->bindParam(':data_vencimento', $data_vencimento);
        $stmt->bindParam(':valor_mensalidade', $valor_mensalidade);
        $stmt->bindParam(':status_pagamento', $status_pagamento, PDO::PARAM_INT);
        $stmt->bindParam(':forma_pagamento', $forma_pagamento);
        $stmt->bindParam(':observacoes', $observacoes);
        $stmt->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
        
        $stmt->execute();

        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Assinatura atualizada com sucesso'
        ], JSON_UNESCAPED_UNICODE);

    } else {
        // Criar nova assinatura
        if (!$data_inicio) {
            $data_inicio = date('Y-m-d'); // Data atual se não informada
        }

        if (!$valor_mensalidade) {
            // Valores padrão por plano
            $valores = [2 => 50.00, 3 => 100.00, 4 => 150.00];
            $valor_mensalidade = $valores[$barbeiro['perfil']] ?? 50.00;
        }

        $sql = "INSERT INTO assinaturas 
                (codigo_barbeiro, codigo_perfil, data_inicio, data_vencimento, 
                 valor_mensalidade, status_pagamento, forma_pagamento, observacoes)
                VALUES 
                (:codigo_barbeiro, :codigo_perfil, :data_inicio, :data_vencimento,
                 :valor_mensalidade, :status_pagamento, :forma_pagamento, :observacoes)";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
        $stmt->bindParam(':codigo_perfil', $barbeiro['perfil'], PDO::PARAM_INT);
        $stmt->bindParam(':data_inicio', $data_inicio);
        $stmt->bindParam(':data_vencimento', $data_vencimento);
        $stmt->bindParam(':valor_mensalidade', $valor_mensalidade);
        $stmt->bindParam(':status_pagamento', $status_pagamento, PDO::PARAM_INT);
        $stmt->bindParam(':forma_pagamento', $forma_pagamento);
        $stmt->bindParam(':observacoes', $observacoes);
        
        $stmt->execute();

        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Assinatura cadastrada com sucesso'
        ], JSON_UNESCAPED_UNICODE);
    }

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
