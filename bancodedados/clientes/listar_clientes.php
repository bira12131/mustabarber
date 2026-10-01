<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../conexao.php';
require_once '../usuarios/sessao.php';

try {
    // Verificar se usuário está logado
    if (!estaLogado()) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usuário não autenticado',
            'clientes' => []
        ]);
        exit;
    }

    // Obter código do usuário logado
    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = $usuarioLogado['codigo'];

    // Parâmetros de filtro e paginação
    $busca = $_GET['busca'] ?? '';
    $limite = isset($_GET['limite']) ? intval($_GET['limite']) : 50;
    $pagina = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
    $offset = ($pagina - 1) * $limite;
    
    // Monta a query base com filtro de usuário
    $sql = "SELECT codigo, nome, telefone, dtnow, COALESCE(is_assinante, FALSE) AS is_assinante, COALESCE(bloqueado, FALSE) AS bloqueado, motivo_bloqueio FROM clientes WHERE cod_barbeiro = :codigo_usuario";
    $params = [':codigo_usuario' => $codigoUsuario];
    
    // Adiciona filtro de busca
    if (!empty($busca)) {
        $sql .= " AND (nome ILIKE :busca1 OR telefone ILIKE :busca2)";
        $busca_param = "%{$busca}%";
        $params[':busca1'] = $busca_param;
        $params[':busca2'] = $busca_param;
    }
    
    // Conta total de registros (para paginação)
    $sql_count = "SELECT COUNT(*) as total FROM clientes WHERE cod_barbeiro = :codigo_usuario";
    if (!empty($busca)) {
        $sql_count .= " AND (nome ILIKE :busca1 OR telefone ILIKE :busca2)";
    }
    
    $stmt_count = $conn->prepare($sql_count);
    $stmt_count->bindValue(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        if ($key !== ':codigo_usuario') {
            $stmt_count->bindValue($key, $value);
        }
    }
    $stmt_count->execute();
    $row_count = $stmt_count->fetch();
    $total = intval($row_count['total']);
    
    // Ordena e adiciona pagina��o
    $sql .= " ORDER BY nome ASC LIMIT :limite OFFSET :offset";
    $params[':limite'] = $limite;
    $params[':offset'] = $offset;
    
    // Executa query principal
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        if ($key === ':limite' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    $stmt->execute();
    
    $clientes = [];
    while ($row = $stmt->fetch()) {
        $clientes[] = [
            'codigo' => $row['codigo'],
            'nome' => $row['nome'],
            'telefone' => $row['telefone'],
            'dtnow' => $row['dtnow'],
            'is_assinante' => ($row['is_assinante'] === true || $row['is_assinante'] === 't' || $row['is_assinante'] == 1),
            'bloqueado' => ($row['bloqueado'] === true || $row['bloqueado'] === 't' || $row['bloqueado'] == 1),
            'motivo_bloqueio' => $row['motivo_bloqueio'] ?? null,
        ];
    }
    
    // Calcula informa��es de pagina��o
    $total_paginas = ceil($total / $limite);
    
    echo json_encode([
        'sucesso' => true,
        'clientes' => $clientes,
        'paginacao' => [
            'total' => $total,
            'pagina_atual' => $pagina,
            'total_paginas' => $total_paginas,
            'por_pagina' => $limite
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao buscar clientes: ' . $e->getMessage()
    ]);
}
?>
