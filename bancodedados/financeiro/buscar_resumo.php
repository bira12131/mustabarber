<?php
/**
 * Script para buscar resumo financeiro (totais)
 * Sistema: MustaBarber - Nexus Gerência
 * Data: 30/11/2025
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

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

    // Obter código do usuário logado
    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = $usuarioLogado['codigo'];

    // Verificar acesso por plano (financeiro exige Solo Pro ou superior)
    require_once '../planos/plano_manager.php';
    exigirPlanoOuBloquear($conexao, (int)$codigoUsuario, (int)$usuarioLogado['perfil'], 'financeiro');

    // Receber mês/ano (padrão: mês atual)
    $mesAno = isset($_GET['mes_ano']) ? $_GET['mes_ano'] : date('Y-m');
    
    // Calcular mês anterior para comparação
    $mesAnterior = date('Y-m', strtotime($mesAno . '-01 -1 month'));
    
    // Receitas do mês atual
    $sqlReceitaAtual = "SELECT COALESCE(SUM(valor), 0) as total 
                        FROM transacoes 
                        WHERE tipo_transacao = 1 
                        AND codigo_barbeiro = :codigo_usuario
                        AND TO_CHAR(data_transacao, 'YYYY-MM') = :mes_ano";
    $stmt = $conexao->prepare($sqlReceitaAtual);
    $stmt->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    $stmt->bindParam(':mes_ano', $mesAno);
    $stmt->execute();
    $receitaAtual = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Receitas do mês anterior
    $sqlReceitaAnterior = "SELECT COALESCE(SUM(valor), 0) as total 
                           FROM transacoes 
                           WHERE tipo_transacao = 1 
                           AND codigo_barbeiro = :codigo_usuario
                           AND TO_CHAR(data_transacao, 'YYYY-MM') = :mes_anterior";
    $stmt = $conexao->prepare($sqlReceitaAnterior);
    $stmt->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    $stmt->bindParam(':mes_anterior', $mesAnterior);
    $stmt->execute();
    $receitaAnterior = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Despesas do mês atual
    $sqlDespesaAtual = "SELECT COALESCE(SUM(valor), 0) as total 
                        FROM transacoes 
                        WHERE tipo_transacao = 2 
                        AND codigo_barbeiro = :codigo_usuario
                        AND TO_CHAR(data_transacao, 'YYYY-MM') = :mes_ano";
    $stmt = $conexao->prepare($sqlDespesaAtual);
    $stmt->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    $stmt->bindParam(':mes_ano', $mesAno);
    $stmt->execute();
    $despesaAtual = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Despesas do mês anterior
    $sqlDespesaAnterior = "SELECT COALESCE(SUM(valor), 0) as total 
                           FROM transacoes 
                           WHERE tipo_transacao = 2 
                           AND codigo_barbeiro = :codigo_usuario
                           AND TO_CHAR(data_transacao, 'YYYY-MM') = :mes_anterior";
    $stmt = $conexao->prepare($sqlDespesaAnterior);
    $stmt->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    $stmt->bindParam(':mes_anterior', $mesAnterior);
    $stmt->execute();
    $despesaAnterior = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Saldo atual
    $saldoAtual = $receitaAtual - $despesaAtual;
    $saldoAnterior = $receitaAnterior - $despesaAnterior;
    
    // Calcular percentuais de variação
    $variacaoReceita = $receitaAnterior > 0 ? (($receitaAtual - $receitaAnterior) / $receitaAnterior * 100) : 0;
    $variacaoDespesa = $despesaAnterior > 0 ? (($despesaAtual - $despesaAnterior) / $despesaAnterior * 100) : 0;
    $variacaoSaldo = $saldoAnterior != 0 ? (($saldoAtual - $saldoAnterior) / abs($saldoAnterior) * 100) : 0;
    
    echo json_encode([
        'sucesso' => true,
        'resumo' => [
            'receita_atual' => floatval($receitaAtual),
            'receita_anterior' => floatval($receitaAnterior),
            'variacao_receita' => round($variacaoReceita, 1),
            'despesa_atual' => floatval($despesaAtual),
            'despesa_anterior' => floatval($despesaAnterior),
            'variacao_despesa' => round($variacaoDespesa, 1),
            'saldo_atual' => floatval($saldoAtual),
            'saldo_anterior' => floatval($saldoAnterior),
            'variacao_saldo' => round($variacaoSaldo, 1),
            'mes_ano' => $mesAno
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao buscar resumo: ' . $e->getMessage()
    ]);
}
?>
