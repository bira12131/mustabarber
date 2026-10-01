<?php
/**
 * Script para buscar dados do gráfico com período personalizável
 * Sistema: MustaBarber - Nexus Gerência
 * Data: 09/12/2025
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
            'mensagem' => 'Usuário não autenticado',
            'dados' => [
                'meses' => [],
                'receitas' => [],
                'despesas' => []
            ],
            'estatisticas' => [
                'media_receitas' => 0,
                'media_despesas' => 0,
                'maior_receita' => 0,
                'maior_despesa' => 0
            ]
        ]);
        exit;
    }

    // Obter código do usuário logado
    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = $usuarioLogado['codigo'];

    // Verificar acesso por plano (financeiro exige Solo Pro ou superior)
    require_once '../planos/plano_manager.php';
    exigirPlanoOuBloquear($conexao, (int)$codigoUsuario, (int)$usuarioLogado['perfil'], 'financeiro');

    // Array para armazenar os meses
    $meses = [];
    $receitas = [];
    $despesas = [];
    
    // Obter parâmetros da requisição
    $numMeses = isset($_GET['meses']) ? intval($_GET['meses']) : 6;
    $dataInicio = isset($_GET['dataInicio']) ? $_GET['dataInicio'] : null;
    $dataFim = isset($_GET['dataFim']) ? $_GET['dataFim'] : null;
    
    // Se período personalizado foi fornecido
    if ($dataInicio && $dataFim) {
        $inicioTimestamp = strtotime($dataInicio . '-01');
        $fimTimestamp = strtotime($dataFim . '-01');
        
        // Iterar pelos meses do período
        $currentTimestamp = $inicioTimestamp;
        while ($currentTimestamp <= $fimTimestamp) {
            $data = date('Y-m', $currentTimestamp);
            $timestamp = strtotime($data . '-01');
            $meses[] = date('M/y', $timestamp);
            
            // Buscar receitas do mês
            $sqlReceita = "SELECT COALESCE(SUM(valor), 0) as total 
                           FROM transacoes 
                           WHERE tipo_transacao = 1 
                           AND codigo_barbeiro = :codigo_usuario
                           AND TO_CHAR(data_transacao, 'YYYY-MM') = :mes";
            $stmtReceita = $conexao->prepare($sqlReceita);
            $stmtReceita->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
            $stmtReceita->bindParam(':mes', $data);
            $stmtReceita->execute();
            $receitas[] = floatval($stmtReceita->fetch(PDO::FETCH_ASSOC)['total']);
            
            // Buscar despesas do mês
            $sqlDespesa = "SELECT COALESCE(SUM(valor), 0) as total 
                           FROM transacoes 
                           WHERE tipo_transacao = 2 
                           AND codigo_barbeiro = :codigo_usuario
                           AND TO_CHAR(data_transacao, 'YYYY-MM') = :mes";
            $stmtDespesa = $conexao->prepare($sqlDespesa);
            $stmtDespesa->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
            $stmtDespesa->bindParam(':mes', $data);
            $stmtDespesa->execute();
            $despesas[] = floatval($stmtDespesa->fetch(PDO::FETCH_ASSOC)['total']);
            
            // Avançar para o próximo mês
            $currentTimestamp = strtotime('+1 month', $currentTimestamp);
        }
    } else {
        // Gerar os últimos N meses
        for ($i = $numMeses - 1; $i >= 0; $i--) {
            $data = date('Y-m', strtotime("-$i months"));
            $timestamp = strtotime($data . '-01');
            $meses[] = date('M/y', $timestamp);
            
            // Buscar receitas do mês
            $sqlReceita = "SELECT COALESCE(SUM(valor), 0) as total 
                           FROM transacoes 
                           WHERE tipo_transacao = 1 
                           AND codigo_barbeiro = :codigo_usuario
                           AND TO_CHAR(data_transacao, 'YYYY-MM') = :mes";
            $stmtReceita = $conexao->prepare($sqlReceita);
            $stmtReceita->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
            $stmtReceita->bindParam(':mes', $data);
            $stmtReceita->execute();
            $receitas[] = floatval($stmtReceita->fetch(PDO::FETCH_ASSOC)['total']);
            
            // Buscar despesas do mês
            $sqlDespesa = "SELECT COALESCE(SUM(valor), 0) as total 
                           FROM transacoes 
                           WHERE tipo_transacao = 2 
                           AND codigo_barbeiro = :codigo_usuario
                           AND TO_CHAR(data_transacao, 'YYYY-MM') = :mes";
            $stmtDespesa = $conexao->prepare($sqlDespesa);
            $stmtDespesa->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
            $stmtDespesa->bindParam(':mes', $data);
            $stmtDespesa->execute();
            $despesas[] = floatval($stmtDespesa->fetch(PDO::FETCH_ASSOC)['total']);
        }
    }
    
    // Calcular estatísticas
    $mediaReceitas = count($receitas) > 0 ? array_sum($receitas) / count($receitas) : 0;
    $mediaDespesas = count($despesas) > 0 ? array_sum($despesas) / count($despesas) : 0;
    $maiorReceita = count($receitas) > 0 ? max($receitas) : 0;
    $maiorDespesa = count($despesas) > 0 ? max($despesas) : 0;
    
    echo json_encode([
        'sucesso' => true,
        'dados' => [
            'meses' => $meses,
            'receitas' => $receitas,
            'despesas' => $despesas
        ],
        'estatisticas' => [
            'media_receitas' => $mediaReceitas,
            'media_despesas' => $mediaDespesas,
            'maior_receita' => $maiorReceita,
            'maior_despesa' => $maiorDespesa
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao buscar dados do gráfico',
        'dados' => [
            'meses' => [],
            'receitas' => [],
            'despesas' => []
        ],
        'estatisticas' => [
            'media_receitas' => 0,
            'media_despesas' => 0,
            'maior_receita' => 0,
            'maior_despesa' => 0
        ]
    ]);
}
?>
