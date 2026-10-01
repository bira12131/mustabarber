<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Log para arquivo
file_put_contents(__DIR__ . '/debug.log', date('Y-m-d H:i:s') . " - INICIANDO listar_servicos.php\n", FILE_APPEND);

header('Content-Type: application/json; charset=utf-8');

file_put_contents(__DIR__ . '/debug.log', "GET params: " . json_encode($_GET) . "\n", FILE_APPEND);

require_once '../conexao.php';

file_put_contents(__DIR__ . '/debug.log', "Conex&#65533;o estabelecida\n", FILE_APPEND);

try {
    $agendamento = isset($_GET['agendamento']) ? intval($_GET['agendamento']) : 0;
    $barbeiro = isset($_GET['barbeiro']) ? intval($_GET['barbeiro']) : 0;

    file_put_contents(__DIR__ . '/debug.log', "Barbeiro: $barbeiro, Agendamento: $agendamento\n", FILE_APPEND);

    // Se barbeiro foi fornecido, buscar todos os servi&#65533;os daquele barbeiro
    if ($barbeiro > 0) {
        file_put_contents(__DIR__ . '/debug.log', "Entrando no bloco barbeiro > 0\n", FILE_APPEND);
        
        $sql = "SELECT
                    s.codigo,
                    s.nome,
                    s.descricao,
                    s.preco AS valor,
                    s.duracao
                FROM servicos s
                WHERE s.status = 1
                  AND s.codigo_barbeiro = :barbeiro
                ORDER BY s.nome";
        
        file_put_contents(__DIR__ . '/debug.log', "SQL: $sql\n", FILE_APPEND);
        
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':barbeiro', $barbeiro, PDO::PARAM_INT);
        file_put_contents(__DIR__ . '/debug.log', "Prepare OK\n", FILE_APPEND);
        
        $stmt->execute();
        file_put_contents(__DIR__ . '/debug.log', "Execute OK\n", FILE_APPEND);
        
        $servicos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents(__DIR__ . '/debug.log', "FetchAll OK - Total: " . count($servicos) . "\n", FILE_APPEND);
        
        $resultado = [
            'sucesso' => true,
            'servicos' => $servicos,
            'barbeiro' => $barbeiro,
            'total' => count($servicos)
        ];
        
        file_put_contents(__DIR__ . '/debug.log', "Resultado montado: " . json_encode($resultado) . "\n", FILE_APPEND);
        
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        
        file_put_contents(__DIR__ . '/debug.log', "Echo executado, saindo...\n", FILE_APPEND);
        exit;
    }
    
    // Se agendamento foi fornecido, buscar o barbeiro primeiro
    if ($agendamento > 0) {
        $sqlBarbeiro = "SELECT codigo_barbeiro FROM agendamentos WHERE codigo = :codigo";
        $stmtBarbeiro = $conn->prepare($sqlBarbeiro);
        $stmtBarbeiro->execute([':codigo' => $agendamento]);
        $agRow = $stmtBarbeiro->fetch(PDO::FETCH_ASSOC);
        
        if (!$agRow) {
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Agendamento n&#65533;o encontrado'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $barbeiro = intval($agRow['codigo_barbeiro']);
        
        // Buscar servi&#65533;os realizados pelo barbeiro naquele agendamento
        $sql = "SELECT DISTINCT
                    s.codigo,
                    s.nome,
                    s.descricao,
                    s.valor
                FROM agendamentos_servicos asv
                INNER JOIN servicos s ON asv.servico = s.codigo
                INNER JOIN agendamentos a ON asv.agendamento = a.codigo
                WHERE a.codigo_barbeiro = :barbeiro AND asv.agendamento = :agendamento
                ORDER BY s.nome";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([':barbeiro' => $barbeiro, ':agendamento' => $agendamento]);
        $servicos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'sucesso' => true,
            'barbeiro' => $barbeiro,
            'servicos' => $servicos,
            'total' => count($servicos)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Se nenhum filtro foi fornecido, retorna todos os servi&#65533;os
    $sql = "SELECT codigo, nome, descricao, valor FROM servicos WHERE ativo = 1 ORDER BY nome";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $servicos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'sucesso' => true,
        'servicos' => $servicos,
        'total' => count($servicos)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao buscar servi&#65533;os: ' . $e->getMessage(),
        'erro' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
