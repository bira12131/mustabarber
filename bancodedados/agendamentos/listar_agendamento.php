<?php
/**
 * API de Listagem de Agendamentos
 * Sistema: MustaBarber
 * Data: 11/12/2025
 * 
 * Este arquivo retorna a lista de agendamentos do sistema
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

try {
    // Inclui o arquivo de conex&#65533;o
    require_once '../conexao.php';
    require_once '../usuarios/sessao.php';
    
    // Verificar se usu&#65533;rio est&#65533; logado
    if (!estaLogado()) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usu&#65533;rio n&#65533;o autenticado',
            'agendamentos' => []
        ]);
        exit;
    }

    // Obter c&#65533;digo do usu&#65533;rio logado
    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = $usuarioLogado['codigo'];
    
    // Par&#65533;metros de filtro (opcionais)
    $codigoAgendamento = isset($_GET['codigo']) ? intval($_GET['codigo']) : null;
    $dataInicio = $_GET['data_inicio'] ?? null;
    $dataFim = $_GET['data_fim'] ?? null;
    $codBarbeiro = isset($_GET['cod_barbeiro']) ? intval($_GET['cod_barbeiro']) : $codigoUsuario; // Filtrar pelo usu&#65533;rio logado por padr&#65533;o
    $codCliente = isset($_GET['cod_cliente']) ? intval($_GET['cod_cliente']) : null;
    $status = isset($_GET['status']) ? intval($_GET['status']) : null;
    
    // Monta a query base
    $sql = "
        SELECT 
            a.codigo as id,
            a.codigo_barbeiro,
            a.cliente_nome,
            a.cliente_telefone,
            a.data_agendamento,
            a.horario,
                        a.codigo_servico,
                        a.grupo_recorrente,
            a.valor,
            a.duracao_minutos,
            a.observacoes,
            a.codigo_status,
            a.data_criacao,
                        (
                                SELECT t.forma_pagamento
                                FROM transacoes t
                                WHERE t.codigo_barbeiro = a.codigo_barbeiro
                                    AND a.grupo_recorrente IS NOT NULL
                                    AND a.grupo_recorrente <> ''
                                    AND t.observacoes = ('Plano #' || a.grupo_recorrente)
                                ORDER BY t.codigo DESC
                                LIMIT 1
                        ) AS forma_pagamento_plano,
                        (
                                SELECT t.valor
                                FROM transacoes t
                                WHERE t.codigo_barbeiro = a.codigo_barbeiro
                                    AND a.grupo_recorrente IS NOT NULL
                                    AND a.grupo_recorrente <> ''
                                    AND t.observacoes = ('Plano #' || a.grupo_recorrente)
                                ORDER BY t.codigo DESC
                                LIMIT 1
                        ) AS valor_plano_lancado,
            u.nome as barbeiro_nome,
            u.foto_caminho as barbeiro_foto,
            sa.nome as status_nome,
            s.nome as servico_nome
        FROM agendamentos a
        INNER JOIN usuarios u ON a.codigo_barbeiro = u.codigo
        INNER JOIN status_agendamento sa ON a.codigo_status = sa.codigo
        LEFT JOIN servicos s ON a.codigo_servico = s.codigo
        WHERE 1=1
    ";
    
    $params = [];
    
    // Se filtrar por c&#65533;digo de agendamento espec&#65533;fico
    if ($codigoAgendamento) {
        $sql .= " AND a.codigo = :codigo_agendamento";
        $params[':codigo_agendamento'] = $codigoAgendamento;
    }
    
    // Sempre filtrar pelo barbeiro (usu&#65533;rio logado)
    $sql .= " AND a.codigo_barbeiro = :cod_barbeiro";
    $params[':cod_barbeiro'] = $codBarbeiro;
    
    // Adiciona filtros
    if ($dataInicio) {
        $sql .= " AND a.data_agendamento >= :data_inicio";
        $params[':data_inicio'] = $dataInicio;
    }
    
    if ($dataFim) {
        $sql .= " AND a.data_agendamento <= :data_fim";
        $params[':data_fim'] = $dataFim;
    }
    
    if ($codCliente) {
        $sql .= " AND a.cod_cliente = :cod_cliente";
        $params[':cod_cliente'] = $codCliente;
    }
    
    if ($status) {
        $sql .= " AND a.codigo_status = :status";
        $params[':status'] = $status;
    }
    
    $sql .= " ORDER BY a.data_agendamento DESC, a.horario DESC";
    
    // Executa a query
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $agendamentos = $stmt->fetchAll();
    
    // Para cada agendamento, busca os servi&#65533;os
    foreach ($agendamentos as &$agendamento) {
        $servicosExtraidos = null;
        $observacoesBrutas = (string)($agendamento['observacoes'] ?? '');
        if ($observacoesBrutas !== '') {
            $marca = '[MB_SERVICOS]';
            $posMarca = strpos($observacoesBrutas, $marca);
            if ($posMarca !== false) {
                $conteudoMarca = substr($observacoesBrutas, $posMarca + strlen($marca));
                $conteudoMarca = trim($conteudoMarca);
                $jsonMeta = base64_decode($conteudoMarca, true);
                if ($jsonMeta !== false) {
                    $meta = json_decode($jsonMeta, true);
                    if (is_array($meta) && isset($meta['itens']) && is_array($meta['itens'])) {
                        $servicosExtraidos = $meta['itens'];
                    }
                }

                // Remove metadados técnicos da observação exibida
                $agendamento['observacoes'] = trim(substr($observacoesBrutas, 0, $posMarca));
            }
        }

        // Como a tabela agendamentos suporta apenas 1 servi&#65533;o (codigo_servico)
        // vamos criar um array com esse servi&#65533;o
        $agendamento['servicos'] = [];

        if (is_array($servicosExtraidos) && count($servicosExtraidos) > 0) {
            foreach ($servicosExtraidos as $itemServico) {
                $agendamento['servicos'][] = [
                    'codigo' => isset($itemServico['codigo']) ? intval($itemServico['codigo']) : null,
                    'nome' => (string)($itemServico['nome'] ?? 'Serviço'),
                    'valor' => isset($itemServico['valor']) ? floatval($itemServico['valor']) : 0,
                    'duracao' => isset($itemServico['duracao']) ? intval($itemServico['duracao']) : null
                ];
            }
        } elseif ($agendamento['servico_nome']) {
            $agendamento['servicos'][] = [
                'codigo' => $agendamento['codigo_servico'],
                'nome' => $agendamento['servico_nome'],
                'valor' => $agendamento['valor']
            ];
        }
        
        // Formata a data para DD/MM/YYYY
        if ($agendamento['data_agendamento']) {
            $data = new DateTime($agendamento['data_agendamento']);
            $agendamento['data_formatada'] = $data->format('d/m/Y');
        }
        
        // Formata a hora para HH:MM
        if ($agendamento['horario']) {
            $agendamento['hora_formatada'] = substr($agendamento['horario'], 0, 5);
        }

        $formaPg = isset($agendamento['forma_pagamento_plano']) ? intval($agendamento['forma_pagamento_plano']) : 0;
        $formasPagamento = [
            1 => 'dinheiro',
            2 => 'debito',
            3 => 'credito',
            4 => 'pix'
        ];
        $agendamento['forma_pagamento_plano_codigo'] = $formaPg > 0 ? $formaPg : null;
        $agendamento['forma_pagamento_plano_nome'] = $formasPagamento[$formaPg] ?? null;

        $ehPlano = !empty($agendamento['grupo_recorrente']) && strpos((string)$agendamento['grupo_recorrente'], 'plano_') === 0;
        $valorPlanoLancado = isset($agendamento['valor_plano_lancado']) ? floatval($agendamento['valor_plano_lancado']) : 0;
        $valorPlanoReferencia = $valorPlanoLancado > 0
            ? $valorPlanoLancado
            : floatval($agendamento['valor'] ?? 0);

        $agendamento['eh_plano_recorrente'] = $ehPlano;
        $agendamento['plano_pago'] = $ehPlano && $valorPlanoLancado > 0;
        $agendamento['valor_plano_referencia'] = $ehPlano ? $valorPlanoReferencia : null;
    }
    
    echo json_encode([
        'sucesso' => true,
        'agendamentos' => $agendamentos,
        'total' => count($agendamentos)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao buscar agendamentos: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
