<?php
/**
 * API de Busca de Agendamentos por Cliente
 * Sistema: MustaBarber
 * Data: 20/12/2025
 * 
 * Busca agendamentos de um cliente pelo telefone
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'M&#65533;todo n&#65533;o permitido. Use GET.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $telefone = isset($_GET['telefone']) ? trim($_GET['telefone']) : '';
    $periodo = isset($_GET['periodo']) ? trim($_GET['periodo']) : 'proximo';
    
    if (empty($telefone)) {
        throw new Exception('Telefone &#65533; obrigat&#65533;rio.');
    }
    
    // Construir filtro de per&#65533;odo
    $filtroData = '';
    $params = [':telefone' => $telefone];
    
    switch ($periodo) {
        case 'proximo':
            $filtroData = "AND a.data_agendamento >= CURRENT_DATE";
            break;
        case '30':
            $filtroData = "AND a.data_agendamento >= CURRENT_DATE - INTERVAL '30 days'";
            break;
        case '60':
            $filtroData = "AND a.data_agendamento >= CURRENT_DATE - INTERVAL '60 days'";
            break;
        case '90':
            $filtroData = "AND a.data_agendamento >= CURRENT_DATE - INTERVAL '90 days'";
            break;
        case 'todos':
            $filtroData = '';
            break;
        default:
            $filtroData = "AND a.data_agendamento >= CURRENT_DATE";
    }
    
    // Buscar agendamentos
    $sql = "SELECT 
                a.codigo,
                a.data_agendamento,
                a.horario,
                a.valor,
                a.duracao_minutos,
                a.cliente_nome,
                a.cliente_telefone,
                a.codigo_barbeiro,
                u.nome as barbeiro_nome,
                u.telefone as barbeiro_telefone,
                a.codigo_servico,
                s.nome as servico_nome,
                st.nome as status
            FROM agendamentos a
            INNER JOIN usuarios u ON a.codigo_barbeiro = u.codigo
            LEFT JOIN servicos s ON a.codigo_servico = s.codigo
            INNER JOIN status_agendamento st ON a.codigo_status = st.codigo
            WHERE a.cliente_telefone = :telefone
            $filtroData
            ORDER BY a.data_agendamento DESC, a.horario DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $agendamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($agendamentos)) {
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Nenhum agendamento encontrado para este telefone.',
            'agendamentos' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    echo json_encode([
        'sucesso' => true,
        'agendamentos' => $agendamentos,
        'total' => count($agendamentos)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
