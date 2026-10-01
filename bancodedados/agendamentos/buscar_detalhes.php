<?php
// Configurações de erro
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

try {
    // Incluir arquivo de conexão
    if (!file_exists('../conexao.php')) {
        throw new Exception("Arquivo de conexão não encontrado");
    }
    
    require_once '../conexao.php';
    
    // Verificar se conexão está aberta
    if (!isset($conexao) || !$conexao) {
        throw new Exception("Conexão com banco de dados não estabelecida");
    }
    
    // Receber parâmetros
    $codigo_agendamento = isset($_GET['codigo']) ? intval($_GET['codigo']) : 0;
    $codigo_barbeiro = isset($_GET['barbeiro']) ? intval($_GET['barbeiro']) : 0;
    
    // Validar parâmetros
    if (!$codigo_agendamento || !$codigo_barbeiro) {
        http_response_code(400);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Parâmetros inválidos fornecidos (codigo e barbeiro são obrigatórios)'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // ============================================================
    // 1. BUSCAR AGENDAMENTO E DADOS DO BARBEIRO
    // ============================================================
    $sql_agendamento = "
        SELECT 
            a.codigo,
            a.cliente_nome,
            a.cliente_telefone,
            a.data_agendamento,
            a.horario,
            a.codigo_status,
            a.codigo_servico,
            a.valor,
            a.duracao_minutos,
            a.observacoes,
            u.codigo AS barbeiro_codigo,
            u.nome AS barbeiro_nome,
            u.email AS barbeiro_email,
            u.telefone AS barbeiro_telefone,
            u.foto_caminho AS barbeiro_foto
        FROM agendamentos a
        LEFT JOIN usuarios u ON a.codigo_barbeiro = u.codigo
        WHERE a.codigo = ? AND a.codigo_barbeiro = ?
        LIMIT 1
    ";
    
    $stmt_agendamento = $conexao->prepare($sql_agendamento);
    if (!$stmt_agendamento) {
        throw new Exception("Erro ao preparar consulta de agendamento: " . $conexao->error);
    }
    
    if (!$stmt_agendamento->bind_param('ii', $codigo_agendamento, $codigo_barbeiro)) {
        throw new Exception("Erro ao vincular parâmetros: " . $stmt_agendamento->error);
    }
    
    if (!$stmt_agendamento->execute()) {
        throw new Exception("Erro ao executar consulta de agendamento: " . $stmt_agendamento->error);
    }
    
    $result_agendamento = $stmt_agendamento->get_result();
    
    if ($result_agendamento->num_rows === 0) {
        http_response_code(404);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Agendamento não encontrado ou sem permissão para acessar'
        ], JSON_UNESCAPED_UNICODE);
        $stmt_agendamento->close();
        exit;
    }
    
    $agendamento = $result_agendamento->fetch_assoc();
    $stmt_agendamento->close();
    
    // ============================================================
    // 2. BUSCAR SERVIÇO (se existir código_servico)
    // ============================================================
    $servico = null;
    if (!empty($agendamento['codigo_servico'])) {
        $sql_servico = "
            SELECT 
                codigo,
                nome,
                descricao,
                preco,
                duracao
            FROM servicos
            WHERE codigo = ? AND status = 1
            LIMIT 1
        ";
        
        $stmt_servico = $conexao->prepare($sql_servico);
        if ($stmt_servico) {
            $stmt_servico->bind_param('i', $agendamento['codigo_servico']);
            if ($stmt_servico->execute()) {
                $result_servico = $stmt_servico->get_result();
                if ($result_servico->num_rows > 0) {
                    $servico = $result_servico->fetch_assoc();
                }
            }
            $stmt_servico->close();
        }
    }
    
    // ============================================================
    // 3. BUSCAR NOME DO STATUS
    // ============================================================
    $status_nome = 'Pendente';
    if (!empty($agendamento['codigo_status'])) {
        $sql_status = "
            SELECT nome 
            FROM status_agendamento 
            WHERE codigo = ?
            LIMIT 1
        ";
        
        $stmt_status = $conexao->prepare($sql_status);
        if ($stmt_status) {
            $stmt_status->bind_param('i', $agendamento['codigo_status']);
            if ($stmt_status->execute()) {
                $result_status = $stmt_status->get_result();
                if ($result_status->num_rows > 0) {
                    $row_status = $result_status->fetch_assoc();
                    $status_nome = !empty($row_status['nome']) ? ucfirst($row_status['nome']) : 'Pendente';
                }
            }
            $stmt_status->close();
        }
    }
    
    // ============================================================
    // 4. FORMATAR E RETORNAR DADOS
    // ============================================================
    
    // Formatar data (YYYY-MM-DD para exibição)
    $data_formatada = isset($agendamento['data_agendamento']) && !empty($agendamento['data_agendamento']) 
        ? $agendamento['data_agendamento'] 
        : null;
    
    // Formatar horário (HH:MM:SS para HH:MM)
    $horario_formatado = isset($agendamento['horario']) && !empty($agendamento['horario']) 
        ? substr($agendamento['horario'], 0, 5) 
        : null;
    
    // Preparar resposta
    $resposta = [
        'sucesso' => true,
        'agendamento' => [
            'codigo' => intval($agendamento['codigo']),
            'cliente_nome' => $agendamento['cliente_nome'] ?? '',
            'cliente_telefone' => $agendamento['cliente_telefone'] ?? '',
            'data_agendamento' => $data_formatada,
            'horario' => $horario_formatado,
            'codigo_status' => intval($agendamento['codigo_status']),
            'status_nome' => $status_nome,
            'observacoes' => $agendamento['observacoes'] ?? '',
            'duracao_minutos' => intval($agendamento['duracao_minutos'] ?? 0),
            'valor' => floatval($agendamento['valor'] ?? 0),
            'codigo_servico' => !empty($agendamento['codigo_servico']) ? intval($agendamento['codigo_servico']) : null
        ],
        'barbeiro' => [
            'codigo' => intval($agendamento['barbeiro_codigo'] ?? 0),
            'nome' => $agendamento['barbeiro_nome'] ?? 'Não informado',
            'email' => $agendamento['barbeiro_email'] ?? '',
            'telefone' => $agendamento['barbeiro_telefone'] ?? '',
            'foto' => $agendamento['barbeiro_foto'] ?? ''
        ],
        'servico' => $servico ? [
            'codigo' => intval($servico['codigo']),
            'nome' => $servico['nome'],
            'descricao' => $servico['descricao'] ?? '',
            'preco' => floatval($servico['preco']),
            'duracao' => intval($servico['duracao'])
        ] : null
    ];
    
    // Retornar resposta em JSON
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao buscar detalhes do agendamento: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} finally {
    // Fechar conexão
    if (isset($conexao) && $conexao) {
        $conexao->close();
    }
}
?>
