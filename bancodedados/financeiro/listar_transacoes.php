<?php
/**
 * Script para listar transações financeiras
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
            'mensagem' => 'Usuário não autenticado',
            'transacoes' => []
        ]);
        exit;
    }

    // Obter código do usuário logado
    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = $usuarioLogado['codigo'];

    // Verificar acesso por plano (financeiro exige Solo Pro ou superior)
    require_once '../planos/plano_manager.php';
    exigirPlanoOuBloquear($conexao, (int)$codigoUsuario, (int)$usuarioLogado['perfil'], 'financeiro');

    // Receber filtros opcionais
    $tipoTransacao = isset($_GET['tipo']) ? intval($_GET['tipo']) : null; // 1 = Receita, 2 = Despesa
    $statusPagamento = isset($_GET['status']) ? intval($_GET['status']) : null; // 1 = Pendente, 2 = Pago
    $mesAno = isset($_GET['mes_ano']) ? $_GET['mes_ano'] : null; // Formato: YYYY-MM
    $dataInicio = isset($_GET['dataInicio']) ? $_GET['dataInicio'] : null; // Formato: YYYY-MM-DD
    $dataFim = isset($_GET['dataFim']) ? $_GET['dataFim'] : null; // Formato: YYYY-MM-DD
    
    // Query base
    $sql = "SELECT 
                t.codigo,
                t.tipo_transacao,
                t.descricao,
                t.valor,
                t.data_transacao,
                t.data_vencimento,
                t.forma_pagamento,
                t.status_pagamento,
                t.observacoes,
                c.descricao as categoria_nome,
                t.data_cadastro
            FROM transacoes t
            LEFT JOIN categoria c ON t.categoria = c.codigo
            WHERE t.codigo_barbeiro = :codigo_usuario";
    
    $params = [];
    
    // Filtrar por tipo de transação
    if ($tipoTransacao !== null) {
        $sql .= " AND t.tipo_transacao = :tipo_transacao";
        $params[':tipo_transacao'] = $tipoTransacao;
    }
    
    // Filtrar por status de pagamento
    if ($statusPagamento !== null) {
        $sql .= " AND t.status_pagamento = :status_pagamento";
        $params[':status_pagamento'] = $statusPagamento;
    }
    
    // Filtrar por período personalizado (tem prioridade)
    if ($dataInicio !== null && $dataFim !== null) {
        $sql .= " AND t.data_transacao BETWEEN TO_DATE(:data_inicio, 'YYYY-MM-DD') AND TO_DATE(:data_fim, 'YYYY-MM-DD')";
        $params[':data_inicio'] = $dataInicio;
        $params[':data_fim'] = $dataFim;
    }
    // Filtrar por mês/ano (se não houver período personalizado)
    else if ($mesAno !== null) {
        $sql .= " AND TO_CHAR(t.data_transacao, 'YYYY-MM') = :mes_ano";
        $params[':mes_ano'] = $mesAno;
    }
    
    // Ordenar por data mais recente
    $sql .= " ORDER BY t.data_transacao DESC, t.codigo DESC LIMIT 500";
    
    $stmt = $conexao->prepare($sql);
    
    // Bind do código do usuário
    $stmt->bindValue(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    
    // Bind dos parâmetros
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    $transacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mapear nomes das formas de pagamento
    $formasPagamento = [
        1 => 'Dinheiro',
        2 => 'Cartão Débito',
        3 => 'Cartão Crédito',
        4 => 'PIX',
        5 => 'Transferência'
    ];
    
    // Processar transações
    foreach ($transacoes as &$transacao) {
        $transacao['forma_pagamento_nome'] = $formasPagamento[$transacao['forma_pagamento']] ?? 'Não informado';
        $transacao['tipo_nome'] = $transacao['tipo_transacao'] == 1 ? 'Receita' : 'Despesa';
        $transacao['status_nome'] = $transacao['status_pagamento'] == 2 ? 'Pago' : 'Pendente';
        
        // Formatar valor
        $transacao['valor_formatado'] = 'R$ ' . number_format($transacao['valor'], 2, ',', '.');
        
        // Formatar data
        $transacao['data_formatada'] = date('d/m/Y', strtotime($transacao['data_transacao']));
    }
    
    echo json_encode([
        'sucesso' => true,
        'transacoes' => $transacoes,
        'total' => count($transacoes)
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao buscar transações: ' . $e->getMessage(),
        'transacoes' => []
    ]);
}
?>
